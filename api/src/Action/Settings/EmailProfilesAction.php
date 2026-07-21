<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\MailDeliveredArchiveException;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SentMailImapAppender;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EmailProfilesAction
{
    public function __construct(
        private readonly EmailProfileRepository $profiles,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly Mailer $mailer,
        private readonly SentMailImapAppender $imap,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        return Json::ok($response, $this->profiles->listProfiles($this->supplierId($request)));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $id = $this->profiles->createProfile($supplierId, $body, $this->userId($request));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($this->isDuplicate($e)) {
                return Json::error($response, 'profile_conflict', 'E-mailový profil s tímto kódem už existuje.', 409);
            }
            return Json::error($response, 'create_failed', 'E-mailový profil se nepodařilo vytvořit.', 500);
        } catch (\Throwable) {
            return Json::error($response, 'create_failed', 'E-mailový profil se nepodařilo vytvořit.', 500);
        }

        $profile = $this->profiles->findProfile($supplierId, $id);
        $this->log($request, 'email_profile.created', $id, [
            'code' => $profile['code'] ?? null,
            'from_email' => $profile['from_email'] ?? null,
            'reply_to_enabled' => $profile['reply_to_enabled'] ?? false,
            'dkim_enabled' => $profile['dkim_enabled'] ?? false,
            'transport_type' => $profile['transport_type'] ?? 'global',
            'imap_sent_enabled' => $profile['imap_sent_enabled'] ?? false,
            'imap_folder' => $profile['imap_folder'] ?? null,
            'imap_on_failure' => $profile['imap_on_failure'] ?? 'log_only',
            'is_default' => $profile['is_default'] ?? false,
            'signing_profile_id' => $profile['signing_profile_id'] ?? null,
        ]);

        return Json::ok($response, $profile, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        if ($this->profiles->findProfile($supplierId, $profileId) === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->profiles->updateProfile($supplierId, $profileId, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($this->isDuplicate($e)) {
                return Json::error($response, 'profile_conflict', 'E-mailový profil s tímto kódem už existuje.', 409);
            }
            return Json::error($response, 'update_failed', 'E-mailový profil se nepodařilo uložit.', 500);
        } catch (\Throwable) {
            return Json::error($response, 'update_failed', 'E-mailový profil se nepodařilo uložit.', 500);
        }

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        $this->log($request, 'email_profile.updated', $profileId, [
            'changed_fields' => array_values(array_filter(
                array_keys($body),
                static fn (string $field): bool => !in_array($field, ['smtp_password', 'imap_password'], true),
            )),
            'code' => $profile['code'] ?? null,
            'from_email' => $profile['from_email'] ?? null,
            'reply_to_enabled' => $profile['reply_to_enabled'] ?? false,
            'dkim_enabled' => $profile['dkim_enabled'] ?? false,
            'transport_type' => $profile['transport_type'] ?? 'global',
            'imap_sent_enabled' => $profile['imap_sent_enabled'] ?? false,
            'imap_folder' => $profile['imap_folder'] ?? null,
            'imap_on_failure' => $profile['imap_on_failure'] ?? 'log_only',
            'is_default' => $profile['is_default'] ?? false,
            'signing_profile_id' => $profile['signing_profile_id'] ?? null,
        ]);

        return Json::ok($response, $profile);
    }

    public function test(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findProfile($supplierId, $profileId, false, true);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }

        return $this->sendProfileTest($request, $response, $profile, $profileId, false);
    }

    public function testDraft(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $profileId = isset($body['id']) && (int) $body['id'] > 0 ? (int) $body['id'] : null;
        if ($profileId === null && isset($body['profile_id']) && (int) $body['profile_id'] > 0) {
            $profileId = (int) $body['profile_id'];
        }

        $profileData = isset($body['profile']) && is_array($body['profile'])
            ? (array) $body['profile']
            : $body;
        unset($profileData['id'], $profileData['profile_id'], $profileData['profile']);

        try {
            $profile = $this->profiles->profileForDraftTest($supplierId, $profileData, $profileId);
        } catch (\InvalidArgumentException $e) {
            if ($profileId !== null && $this->profiles->findProfile($supplierId, $profileId) === null) {
                return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
            }
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable) {
            return Json::error($response, 'validation_failed', 'Testovací e-mailový profil se nepodařilo připravit.', 400);
        }

        return $this->sendProfileTest($request, $response, $profile, $profileId, true);
    }

    public function browseImapFolders(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        try {
            $settings = $this->imapProbeSettings($request, $args);
        } catch (\InvalidArgumentException $e) {
            return $this->imapProbeError($request, $response, $args, $e);
        } catch (\Throwable) {
            return Json::error($response, 'validation_failed', 'IMAP nastavení se nepodařilo připravit.', 400);
        }

        $result = $this->imap->folders($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    public function testImapSettings(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        try {
            $settings = $this->imapProbeSettings($request, $args);
        } catch (\InvalidArgumentException $e) {
            return $this->imapProbeError($request, $response, $args, $e);
        } catch (\Throwable) {
            return Json::error($response, 'validation_failed', 'IMAP nastavení se nepodařilo připravit.', 400);
        }

        $result = $this->imap->test($settings);
        return Json::ok($response, $result, !empty($result['ok']) ? 200 : 400);
    }

    /**
     * @return array<string,mixed>
     */
    private function imapProbeSettings(Request $request, array $args = []): array
    {
        $supplierId = $this->supplierId($request);
        $profileId = isset($args['id']) ? (int) $args['id'] : null;
        $body = (array) ($request->getParsedBody() ?? []);
        if ($profileId === null && isset($body['id']) && (int) $body['id'] > 0) {
            $profileId = (int) $body['id'];
        }
        if ($profileId === null && isset($body['profile_id']) && (int) $body['profile_id'] > 0) {
            $profileId = (int) $body['profile_id'];
        }

        $profileData = isset($body['profile']) && is_array($body['profile'])
            ? (array) $body['profile']
            : $body;
        unset($profileData['id'], $profileData['profile_id'], $profileData['profile']);

        return $this->profiles->imapProbeSettingsForDraft($supplierId, $profileData, $profileId);
    }

    private function imapProbeError(Request $request, Response $response, array $args, \InvalidArgumentException $e): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $profileId = isset($args['id']) ? (int) $args['id'] : null;
        if ($profileId === null && isset($body['id']) && (int) $body['id'] > 0) {
            $profileId = (int) $body['id'];
        }
        if ($profileId === null && isset($body['profile_id']) && (int) $body['profile_id'] > 0) {
            $profileId = (int) $body['profile_id'];
        }
        if ($profileId !== null && $this->profiles->findProfile($this->supplierId($request), $profileId) === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }

        return Json::error($response, 'validation_failed', $e->getMessage(), 400);
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function sendProfileTest(Request $request, Response $response, array $profile, ?int $profileId, bool $draft): Response
    {
        $supplierId = $this->supplierId($request);
        $supplier = $this->supplierForEmail($supplierId);
        $recipient = $this->testRecipient($request, $supplier);
        if ($recipient === null) {
            return Json::error($response, 'no_test_recipient', 'Nelze určit testovací e-mail příjemce.', 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $locale = in_array(($user['locale'] ?? 'cs'), ['cs', 'en'], true) ? (string) $user['locale'] : 'cs';
        $smtpResponse = '';
        $imapAppend = ['status' => 'skipped', 'folder' => null, 'error' => null];

        try {
            $sendResult = $this->mailer->sendTemplateDetailed(
                'email_profile_test',
                $locale,
                [$recipient],
                [
                    'supplier' => $supplier,
                    'profile' => $this->profileTestVars($profile),
                ],
                null,
                [],
                [],
                [],
                $this->userId($request),
                $profile,
            );
            $smtpResponse = (string) ($sendResult['smtp_response'] ?? '');
            $imapAppend = is_array($sendResult['imap_append'] ?? null)
                ? $sendResult['imap_append']
                : $imapAppend;
        } catch (MailDeliveredArchiveException $e) {
            $smtpResponse = $e->smtpResponse();
            $imapAppend = $e->imapAppend();
        } catch (\Throwable $e) {
            $this->log($request, 'email.profile_test_failed', $profileId, [
                'code' => $profile['code'] ?? null,
                'to' => $recipient,
                'draft' => $draft,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return Json::error($response, 'send_failed', 'Testovací e-mail se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $sentAt = date('Y-m-d H:i:s');
        $this->log($request, 'email.sent_profile_test', $profileId, [
            'code' => $profile['code'] ?? null,
            'to' => $recipient,
            'draft' => $draft,
            'transport_type' => $profile['transport_type'] ?? 'global',
            'smtp_response' => $smtpResponse,
            'imap_append_status' => $imapAppend['status'] ?? 'skipped',
            'imap_append_folder' => $imapAppend['folder'] ?? null,
            'imap_append_error' => $imapAppend['error'] ?? null,
        ]);

        return Json::ok($response, [
            'sent_to' => [$recipient],
            'sent_at' => $sentAt,
            'smtp_response' => $smtpResponse,
            'imap_append' => $imapAppend,
            'is_test' => true,
            'is_draft' => $draft,
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'E-mailový profil nenalezen.', 404);
        }

        $brandingNames = $this->profiles->brandingProfileUsages($supplierId, $profileId);
        if ($brandingNames !== []) {
            return Json::error($response, 'profile_in_use', 'E-mailový profil používají brandingové profily: ' . implode(', ', $brandingNames) . '. Nejprve jim nastav jiného odesílatele.', 409);
        }

        $this->profiles->softDeleteProfile($supplierId, $profileId);
        $this->log($request, 'email_profile.deleted', $profileId, [
            'code' => $profile['code'] ?? null,
            'from_email' => $profile['from_email'] ?? null,
        ]);

        return Json::ok($response, ['deleted' => true]);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['role'] ?? null) === 'admin';
    }

    /**
     * @return array<string,mixed>
     */
    private function supplierForEmail(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, COALESCE(bp.display_name, s.display_name) AS display_name,
                    COALESCE(bp.tagline, s.tagline) AS tagline, s.street, s.city, s.zip,
                    COALESCE(bp.email, s.email) AS email, COALESCE(bp.phone, s.phone) AS phone,
                    COALESCE(bp.web, s.web) AS web,
                    COALESCE(bp.branding_enabled, s.email_branding_enabled) AS email_branding_enabled,
                    COALESCE(bp.accent_color, s.email_accent_color) AS email_accent_color,
                    COALESCE(bp.logo_path, s.logo_path) AS logo_path, bp.id AS branding_profile_id,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN branding_profiles bp ON s.branding_profiles_enabled = 1 AND bp.id = s.default_branding_profile_id AND bp.supplier_id = s.id AND bp.is_active = 1
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['id' => $supplierId];
        }

        $row['id'] = (int) $row['id'];
        $row['email_branding_enabled'] = (int) ($row['email_branding_enabled'] ?? 0) === 1;
        $row['email_accent_color'] = (string) ($row['email_accent_color'] ?: '#3B2D83');
        $row['accent_soft'] = AccentColor::emailBackground(
            (bool) $row['email_branding_enabled'],
            $row['email_accent_color'],
        );

        return $row;
    }

    /**
     * @param array<string,mixed> $supplier
     */
    private function testRecipient(Request $request, array $supplier): ?string
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        foreach ([
            (string) ($user['email'] ?? ''),
            (string) ($supplier['email'] ?? ''),
            (string) $this->config->get('smtp.from_email', ''),
        ] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,string>
     */
    private function profileTestVars(array $profile): array
    {
        $from = trim((string) ($profile['from_email'] ?? ''));
        $fromName = trim((string) ($profile['from_name'] ?? ''));
        if ($fromName !== '') {
            $from = $fromName . ' <' . $from . '>';
        }

        $replyTo = 'From';
        if (($profile['reply_to_enabled'] ?? false) && !empty($profile['reply_to_email'])) {
            $replyTo = trim((string) ($profile['reply_to_email'] ?? ''));
            $replyToName = trim((string) ($profile['reply_to_name'] ?? ''));
            if ($replyToName !== '') {
                $replyTo = $replyToName . ' <' . $replyTo . '>';
            }
        }

        return [
            'name' => (string) ($profile['name'] ?? ''),
            'code' => (string) ($profile['code'] ?? ''),
            'from' => $from,
            'reply_to' => $replyTo,
            'transport' => $this->transportLabel($profile),
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function transportLabel(array $profile): string
    {
        return match ((string) ($profile['transport_type'] ?? 'global')) {
            'smtp' => trim((string) ($profile['smtp_host'] ?? '')) !== ''
                ? sprintf('SMTP %s:%d', (string) $profile['smtp_host'], (int) ($profile['smtp_port'] ?? 587))
                : 'SMTP',
            'sendmail' => trim((string) ($profile['sendmail_command'] ?? '')) !== ''
                ? 'sendmail: ' . trim((string) $profile['sendmail_command'])
                : 'sendmail',
            default => 'cfg.php',
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, ?int $profileId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'email_profile',
            $profileId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->supplierId($request),
        );
    }

    private function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
