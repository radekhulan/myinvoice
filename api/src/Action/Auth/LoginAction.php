<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\EmailOtpService;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\StoredPasskeyCredential;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Service\Auth\WebAuthnCeremonyStore;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LoginAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly BruteForceGuard $bf,
        private readonly TurnstileVerifier $turnstile,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly EmailOtpService $emailOtp,
        private readonly TrustedDeviceService $trustedDevices,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly PasskeyService $passkeys,
        private readonly WebAuthnCeremonyStore $ceremonies,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly LoginSessionIssuer $loginIssuer,
        private readonly ClockInterface $clock,
    ) {}

    public function passkeyOptions(Request $request, Response $response): Response
    {
        if (!(bool) $this->config->get('auth.passwordless_login.enabled', false)) {
            return Json::error(
                $response,
                'passwordless_login_disabled',
                'Přihlášení pouze pomocí passkey není v této instalaci povolené.',
                403,
            );
        }
        if (!$this->mfaPolicy->isMethodAllowed('passkey')) {
            return Json::error(
                $response,
                'mfa_method_not_allowed',
                'Přihlášení pomocí passkey není v této instalaci povolené.',
                403,
            );
        }
        if (!$this->passkeys->isAvailable()) {
            return Json::error(
                $response,
                'passkeys_unavailable',
                'Passkeys nejsou kvůli konfiguraci této instalace dostupné.',
                503,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $turnstileToken = isset($body['cf_turnstile_response'])
            ? (string) $body['cf_turnstile_response']
            : '';
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');
        if (!$this->turnstile->verify($turnstileToken, $ip, 'login')) {
            $this->logger->log('auth.captcha_failed', null, null, null, [
                'ip' => $ip,
                'login_method' => 'passkey',
            ], $ip, $userAgent);
            return Json::error($response, 'captcha_failed', 'CAPTCHA selhala.', 400);
        }

        $challenge = random_bytes(32);
        $options = $this->passkeys->discoverableAssertionOptions($challenge);
        $flowToken = $this->ceremonies->create(
            WebAuthnCeremonyStore::PURPOSE_DISCOVERABLE_LOGIN,
            null,
            null,
            null,
            $challenge,
            $options,
            $ip,
            $userAgent,
        );

        return Json::ok($response, [
            'flow_token' => $flowToken,
            'public_key' => $options,
        ]);
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $totpCode = isset($body['totp']) ? trim((string) $body['totp']) : '';
        $emailOtpCode = isset($body['email_otp']) ? trim((string) $body['email_otp']) : '';
        $resendOtp = !empty($body['resend_otp']);
        $rememberDevice = !empty($body['remember_device']);
        $turnstileToken = isset($body['cf_turnstile_response']) ? (string) $body['cf_turnstile_response'] : null;

        $ip        = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');

        if ($email === '' || $password === '') {
            return Json::error($response, 'invalid_credentials', 'Neplatné přihlašovací údaje.', 401);
        }

        // Brute-force check
        $state = $this->bf->check($email, $ip);

        if ($state === BruteForceGuard::STATE_LOCKED_24H || $state === BruteForceGuard::STATE_LOCKED_15M) {
            $this->logger->log('auth.login_locked', null, null, null, [
                'email' => $email, 'ip' => $ip, 'state' => $state,
            ], $ip, $userAgent);
            return Json::error(
                $response,
                'too_many_attempts',
                $state === BruteForceGuard::STATE_LOCKED_24H
                    ? 'Účet je zablokovaný na 24 hodin kvůli mnoha selháním.'
                    : 'Účet je zablokovaný na 15 minut kvůli mnoha selháním.',
                429,
            );
        }

        // Turnstile vždy aktivní — Cloudflare sám rozhoduje (auto-pass nebo interactive challenge).
        // No-op pokud captcha.provider != 'turnstile' nebo chybí secret_key (TurnstileVerifier).
        if (!$this->turnstile->verify($turnstileToken ?? '', $ip, 'login')) {
            $this->logger->log('auth.captcha_failed', null, null, null, [
                'email' => $email, 'ip' => $ip,
            ], $ip, $userAgent);
            $this->bf->recordFailure($email, $ip);
            return Json::error($response, 'captcha_failed', 'CAPTCHA selhala.', 400);
        }

        // Načti usera (vždy zavolej dummyVerify pokud user neexistuje → konstantní timing)
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, name, role, locale, password_hash, is_active,
                    totp_secret, totp_enabled, session_lock_after_minutes
               FROM users
              WHERE email = ?
              LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || (int) $user['is_active'] === 0) {
            $this->hasher->dummyVerify();
            $this->bf->recordFailure($email, $ip);
            $this->logger->log('auth.login_failed', null, null, null, [
                'email' => $email, 'reason' => $user ? 'user_inactive' : 'user_not_found',
            ], $ip, $userAgent);
            return Json::error($response, 'invalid_credentials', 'Neplatné přihlašovací údaje.', 401);
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->bf->recordFailure($email, $ip);
            $this->logger->log('auth.login_failed', (int) $user['id'], 'user', (int) $user['id'], [
                'email' => $email, 'reason' => 'wrong_password',
            ], $ip, $userAgent);
            return Json::error($response, 'invalid_credentials', 'Neplatné přihlašovací údaje.', 401);
        }

        $totpActive = (int) $user['totp_enabled'] === 1 && !empty($user['totp_secret']);
        $totpAllowed = $this->mfaPolicy->isMethodAllowed('totp');
        $passkeysUsable = $this->mfaPolicy->isMethodAllowed('passkey')
            && $this->passkeys->isAvailable();
        $storedPasskeys = $passkeysUsable
            ? $this->credentials->findAllForUser((int) $user['id'])
            : [];
        if ($storedPasskeys !== []
            && !($totpCode !== '' && $totpActive && $totpAllowed)
        ) {
            $this->rehashPasswordIfNeeded($user, $password);
            $challenge = random_bytes(32);
            $options = $this->passkeys->assertionOptions(
                array_map(
                    static fn (StoredPasskeyCredential $credential) => $credential->record,
                    $storedPasskeys,
                ),
                $challenge,
            );
            $flowToken = $this->ceremonies->create(
                WebAuthnCeremonyStore::PURPOSE_LOGIN,
                (int) $user['id'],
                null,
                null,
                $challenge,
                $options,
                $ip,
                $userAgent,
            );
            $methods = ['passkey'];
            if ($totpActive && $totpAllowed) {
                $methods[] = 'totp';
            }

            return Json::error(
                $response,
                'mfa_required',
                'Je vyžadováno ověření silným faktorem.',
                401,
                [
                    'flow_token' => $flowToken,
                    'methods' => $methods,
                    'public_key' => $options,
                ],
            );
        }

        // Default OFF — opt-in feature, ať to není breaking change pro existující
        // instalace (jinak by se uživatelům bez TOTP náhle vyžadoval e-mailový kód).
        $emailOtpOn     = (bool) $this->config->get('auth.email_otp.enabled', false);
        $issueTrustedTd = false;  // vystavit trusted-device cookie po úspěšném loginu?
        $authContext = SessionAuthContext::basic('password');

        // Uživatele s passkey nesmí prosté heslo pustit dovnitř jen proto, že se
        // WebAuthn stal nedostupným (rozbité app.url) nebo že správce metodu
        // zakázal. Připustíme jen jiný skutečný druhý faktor; countActive se díky
        // short-circuitu ptá jen v této výjimečné konfiguraci.
        if (!$passkeysUsable
            && !$totpActive
            && !$emailOtpOn
            && $this->credentials->countActiveForUser((int) $user['id']) > 0
        ) {
            $this->rehashPasswordIfNeeded($user, $password);
            $this->logger->log('auth.login_failed', (int) $user['id'], 'user', (int) $user['id'], [
                'email' => $email, 'reason' => 'passkeys_unavailable',
            ], $ip, $userAgent);
            return Json::error(
                $response,
                'passkeys_unavailable',
                'Passkeys nejsou kvůli konfiguraci této instalace dostupné.',
                503,
            );
        }

        // Faktor, který uživatel reálně má, se nikdy nepřeskakuje. `allowed_mfa_methods`
        // řídí jen to, co splní povinné MFA (assurance), ne jestli se na faktor zeptáme —
        // jinak by zúžení seznamu na ['passkey'] tiše zrušilo TOTP všem, kdo passkey nemají.
        if ($totpActive) {
            if ($totpCode === '') {
                // Nepočítej jako fail — uživatel zadal heslo OK, jen čekáme na 2FA
                return Json::error($response, 'totp_required', 'TOTP kód požadován.', 401);
            }
            // Per-user TOTP lockout — chrání 10⁶ keyspace proti brute-force
            if ($this->bf->isTotpLocked((int) $user['id'])) {
                $this->logger->log('auth.totp_locked', (int) $user['id'], 'user', (int) $user['id'], [
                    'email' => $email,
                ], $ip, $userAgent);
                return Json::error($response, 'too_many_attempts', 'Příliš mnoho TOTP pokusů. Zkus to později.', 429);
            }
            $totpSecret = $this->crypto->decrypt((string) $user['totp_secret']);
            if (!$this->totp->verify($totpSecret, $totpCode)) {
                $this->bf->recordTotpFailure((int) $user['id']);
                $this->bf->recordFailure($email, $ip);
                $this->logger->log('auth.login_failed', (int) $user['id'], 'user', (int) $user['id'], [
                    'email' => $email, 'reason' => 'totp_invalid',
                ], $ip, $userAgent);
                return Json::error($response, 'invalid_totp', 'Neplatný TOTP kód.', 401);
            }
            $this->bf->recordTotpSuccess((int) $user['id']);
            $authContext = $totpAllowed
                ? SessionAuthContext::strong('totp', $this->clock->now())
                : SessionAuthContext::basic('totp');
        } elseif ($emailOtpOn) {
            $tdCookieName = $this->trustedDevices->cookieName();
            $tdToken = $request->getCookieParams()[$tdCookieName] ?? null;
            $deviceTrusted = $this->trustedDevices->verify(is_string($tdToken) ? $tdToken : null, (int) $user['id']);

            if (!$deviceTrusted) {
                if ($emailOtpCode === '') {
                    // Heslo OK → pošli (nebo přeresetuj) kód a vyžádej ho. Není to fail.
                    $issued = $this->emailOtp->issue($user, $ip, $resendOtp);
                    $this->logger->log('auth.email_otp_sent', (int) $user['id'], 'user', (int) $user['id'], [
                        'email' => $email, 'sent' => $issued['sent'], 'resend' => $resendOtp,
                    ], $ip, $userAgent);
                    return Json::error($response, 'email_otp_required', 'Zadejte kód, který jsme poslali na váš e-mail.', 401, [
                        'otp_sent'           => $issued['sent'],
                        'cooldown_remaining' => $issued['cooldown_remaining'],
                        'email_masked'       => $this->maskEmail((string) $user['email']),
                    ]);
                }
                // Per-user lockout — stejná ochrana jako u TOTP (6místný kód).
                if ($this->bf->isEmailOtpLocked((int) $user['id'])) {
                    $this->logger->log('auth.email_otp_locked', (int) $user['id'], 'user', (int) $user['id'], [
                        'email' => $email,
                    ], $ip, $userAgent);
                    return Json::error($response, 'too_many_attempts', 'Příliš mnoho pokusů o ověření kódu. Zkus to později.', 429);
                }
                if (!$this->emailOtp->verify((int) $user['id'], $emailOtpCode)) {
                    $this->bf->recordEmailOtpFailure((int) $user['id']);
                    $this->bf->recordFailure($email, $ip);
                    $this->logger->log('auth.login_failed', (int) $user['id'], 'user', (int) $user['id'], [
                        'email' => $email, 'reason' => 'email_otp_invalid',
                    ], $ip, $userAgent);
                    return Json::error($response, 'invalid_email_otp', 'Neplatný nebo expirovaný kód.', 401);
                }
                $this->bf->recordEmailOtpSuccess((int) $user['id']);
                $issueTrustedTd = $rememberDevice;
                $authContext = SessionAuthContext::basic('email_otp');
            } else {
                $authContext = SessionAuthContext::basic('trusted_device');
            }
        }

        if ($this->mfaPolicy->isRequired()
            && $authContext->assuranceLevel !== 'strong'
        ) {
            $authContext = SessionAuthContext::setup($authContext->authMethod);
        }

        $this->rehashPasswordIfNeeded($user, $password);
        return $this->loginIssuer->issue(
            $response,
            $user,
            $ip,
            $userAgent,
            $authContext,
            $issueTrustedTd,
        );
    }

    /**
     * @param array<string,mixed> $user
     */
    private function rehashPasswordIfNeeded(array $user, string $password): void
    {
        if (!$this->hasher->needsRehash((string) $user['password_hash'])) {
            return;
        }
        $newHash = $this->hasher->hash($password);
        $this->db->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, (int) $user['id']]);
    }

    /** r***@hulan.cz — náznak adresy pro UI, bez prozrazení celého e-mailu. */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $first = mb_substr($local, 0, 1);
        return $first . str_repeat('*', max(1, mb_strlen($local) - 1)) . $domain;
    }
}
