<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MeAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionLockPolicy $lockPolicy,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $session = (array) $request->getAttribute(AuthMiddleware::ATTR_SESSION, []);
        $currentSupplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $ossSelect = $this->db->hasColumn('supplier', 'oss_enabled') ? 'oss_enabled' : '0 AS oss_enabled';
        $supplierStatement = $this->db->pdo()->query(
            'SELECT id, company_name, ic, is_vat_payer, is_identified, ' . $ossSelect . ', taxpayer_type,
                    default_payment_due_days, default_payment_due_unit, default_prices_include_vat,
                    auto_send_reminders, payment_thanks_enabled, payment_thanks_default_checked
               FROM supplier ORDER BY id'
        );
        if ($supplierStatement === false) {
            throw new \RuntimeException('Seznam dodavatelů se nepodařilo načíst.');
        }
        $suppliers = $supplierStatement->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($suppliers as &$s) {
            $s['id']                       = (int) $s['id'];
            $s['is_vat_payer']             = (bool) $s['is_vat_payer'];
            // Identifikovaná osoba (§ 6g–6l, issue #94) — neplátce s přeshraničními
            // povinnostmi; editor podle ní nabídne RC u zahraničních faktur.
            $s['is_identified']            = (bool) ($s['is_identified'] ?? false);
            $s['oss_enabled']              = (bool) ($s['oss_enabled'] ?? false);
            // 'fo' = OSVČ (fyzická osoba), 'po' = s.r.o. (právnická osoba), null = nenastaveno.
            $s['taxpayer_type']            = $s['taxpayer_type'] !== null ? (string) $s['taxpayer_type'] : null;
            $s['default_payment_due_days'] = (int) $s['default_payment_due_days'];
            $s['default_payment_due_unit'] = (string) ($s['default_payment_due_unit'] ?? 'days');
            // Výchozí režim cen u nových faktur (0 = bez DPH, 1 = ceny s DPH) — předvyplní editor.
            $s['default_prices_include_vat'] = (bool) ($s['default_prices_include_vat'] ?? false);
            // Per-faktura přepínač upomínek v editoru se skryje, když dodavatel auto-upomínky nemá.
            $s['auto_send_reminders']      = (bool) ($s['auto_send_reminders'] ?? true);
            // Děkovný e-mail (issue #57) — UI v mark-paid modalu podle nich zobrazí checkbox.
            $s['payment_thanks_enabled']         = (bool) ($s['payment_thanks_enabled'] ?? false);
            $s['payment_thanks_default_checked'] = (bool) ($s['payment_thanks_default_checked'] ?? false);
        }

        $totpEnabled  = (bool) ($user['totp_enabled'] ?? false);
        $requireTotp  = (bool) $this->config->get('auth.require_totp', false);
        $mustSetupTotp = $requireTotp && !$totpEnabled;
        $userId = (int) ($user['id'] ?? 0);
        $passkeyCount = $userId > 0 ? $this->credentials->countActiveForUser($userId) : 0;
        $mfaMethods = [];
        if ($passkeyCount > 0) {
            $mfaMethods[] = 'passkey';
        }
        if ($totpEnabled) {
            $mfaMethods[] = 'totp';
        }
        $assurance = (string) ($session['assurance_level'] ?? 'legacy');
        $mustSetupMfa = $assurance === 'setup';
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $userTimeout = ($user['session_lock_after_minutes'] ?? null) !== null
            ? (int) $user['session_lock_after_minutes']
            : null;
        $effectiveTimeout = $this->lockPolicy->effectiveTimeoutMinutes($userTimeout);
        $idleExpiresAt = null;
        if ($assurance !== 'setup' && $effectiveTimeout > 0) {
            // Autoritou zámku je SessionLockMiddleware; /me jen reportuje. Chybějící
            // nebo pokažený čas aktivity proto nesmí shodit celý profil na 500 —
            // klient si deadline dotáhne z /api/auth/session/status.
            $lastActivity = self::tryParseUtc((string) ($session['last_user_activity_at'] ?? ''));
            $idleExpiresAt = $lastActivity !== null
                ? self::isoUtc($lastActivity->modify(sprintf('+%d minutes', $effectiveTimeout)))
                : null;
        }

        return Json::ok($response, [
            'user' => [
                'id'              => $userId,
                'email'           => $user['email'] ?? '',
                'name'            => $user['name'] ?? '',
                'role'            => $user['role'] ?? 'readonly',
                'locale'          => $user['locale'] ?? 'cs',
                'totp_enabled'    => $totpEnabled,
                'must_setup_totp' => $mustSetupTotp,
                'mfa_enabled'     => $mfaMethods !== [],
                'mfa_methods'     => $mfaMethods,
                'passkey_count'   => $passkeyCount,
                'must_setup_mfa'  => $mustSetupMfa,
            ],
            'csrf_token'          => $session['csrf_token'] ?? '',
            'current_supplier_id' => $currentSupplierId,
            'suppliers'           => $suppliers,
            'require_totp'        => $requireTotp,
            'require_mfa'         => $this->mfaPolicy->isRequired(),
            'allowed_mfa_methods' => $this->mfaPolicy->allowedMethods(),
            'session_state'       => 'active',
            'lock_after_minutes'  => $effectiveTimeout,
            'server_time'         => self::isoUtc($now),
            'idle_expires_at'     => $idleExpiresAt,
        ]);
    }

    private static function tryParseUtc(string $time): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $time, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return null;
    }

    private static function isoUtc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
