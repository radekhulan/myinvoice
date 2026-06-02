<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Service\Signing\SigningProfileAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Obecné podpisové profily pro aktuální supplier scope.
 *
 * První API vrstva řeší metadata profilů a RBAC. Upload certifikátů a runtime
 * výběr profilu se doplní v dalších krocích.
 */
final class SigningProfilesAction
{
    private const PDF_OUTPUT_TYPES = ['invoice', 'work_report'];
    private const EMAIL_OUTPUT_TYPES = [
        'email_invoice_send',
        'email_invoice_reminder',
        'email_proforma_reminder',
        'email_invoice_payment_thanks',
        'email_invoice_approval',
        'email_recurring_draft_reminder',
    ];
    private const PASSPHRASE_POLICIES = ['encrypted_store', 'passphrase_file', 'prompt_on_use'];
    private const MAX_CERT_SIZE = 128 * 1024;

    public function __construct(
        private readonly Connection $db,
        private readonly SigningProfileRepository $profiles,
        private readonly SigningProfileAccess $access,
        private readonly ActivityLogger $logger,
        private readonly SecretEncryption $secrets,
        private readonly PdfSigningService $pdfSigning,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    public function settings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request) && !$this->isAccountant($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        return Json::ok($response, $this->profiles->settings($this->supplierId($request)));
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $enabled = (bool) ($body['accountant_profiles_enabled'] ?? false);
        $this->profiles->setAccountantProfilesEnabled($supplierId, $enabled);
        $this->logger->log('signing.settings_updated', $this->userId($request), 'supplier', $supplierId, [
            'accountant_profiles_enabled' => $enabled,
        ], null, null, $supplierId);

        return Json::ok($response, $this->profiles->settings($supplierId));
    }

    public function listProfiles(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        $settings = $this->profiles->settings($supplierId);

        if ($this->isAdmin($request)) {
            return Json::ok($response, $this->profiles->listProfiles($supplierId));
        }

        if ($this->isAccountant($request) && $settings['accountant_profiles_enabled']) {
            return Json::ok($response, $this->profiles->listProfilesForOwner($supplierId, $this->userId($request)));
        }

        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    public function getProfile(Request $request, Response $response, array $args): Response
    {
        $profile = $this->visibleProfile($request, (int) ($args['id'] ?? 0));
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }

        return Json::ok($response, $profile);
    }

    public function createProfile(Request $request, Response $response): Response
    {
        $supplierId = $this->supplierId($request);
        $settings = $this->profiles->settings($supplierId);
        $role = $this->role($request);
        if (!$this->access->canCreate($role, $settings['accountant_profiles_enabled'])) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $ownerUserId = $role === 'accountant'
            ? $this->userId($request)
            : $this->nullableInt($body['owner_user_id'] ?? null);
        $pdfTsaEnabled = array_key_exists('pdf_tsa_enabled', $body)
            ? (bool) $body['pdf_tsa_enabled']
            : $this->stringOrNull($body['pdf_tsa_url'] ?? null) !== null;
        $pdfTsaUrl = $pdfTsaEnabled ? $this->stringOrNull($body['pdf_tsa_url'] ?? null) : null;
        if ($pdfTsaEnabled && $pdfTsaUrl === null) {
            return Json::error($response, 'validation_failed', 'Pro časové razítko zadej TSA URL.', 400);
        }

        try {
            $id = $this->profiles->createProfile(
                supplierId: $supplierId,
                ownerUserId: $ownerUserId,
                name: (string) ($body['name'] ?? ''),
                code: (string) ($body['code'] ?? ''),
                allowedUsages: $this->stringList($body['allowed_usages'] ?? ['pdf']),
                defaultBackend: (string) ($body['default_backend'] ?? 'native'),
                createdBy: $this->userId($request),
                isActive: (bool) ($body['is_active'] ?? true),
                pdfTsaUrl: $pdfTsaUrl,
                pdfTsaUsername: $pdfTsaEnabled ? $this->stringOrNull($body['pdf_tsa_username'] ?? null) : null,
                pdfTsaPasswordEnc: $pdfTsaEnabled ? $this->encryptedOptionalSecret($body['pdf_tsa_password'] ?? null) : null,
                pdfReason: $this->stringOrNull($body['pdf_reason'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($this->isIntegrityConflict($e)) {
                return Json::error($response, 'profile_conflict', 'Podpisový profil s tímto kódem už existuje.', 409);
            }
            return Json::error($response, 'create_failed', 'Podpisový profil se nepodařilo vytvořit.', 500);
        } catch (\Throwable $e) {
            return Json::error($response, 'create_failed', 'Podpisový profil se nepodařilo vytvořit.', 500);
        }

        $profile = $this->profiles->findProfile($supplierId, $id);
        $this->logger->log('signing.profile_created', $this->userId($request), 'signing_profile', $id, [
            'code' => $profile['code'] ?? null,
            'owner_user_id' => $profile['owner_user_id'] ?? null,
            'allowed_usages' => $profile['allowed_usages'] ?? [],
            'default_backend' => $profile['default_backend'] ?? null,
        ], null, null, $supplierId);

        return Json::ok($response, $profile, 201);
    }

    public function updateProfile(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }
        if (!$this->canManageProfile($request, $profile)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $changes = [];
            foreach (['name', 'code', 'default_backend', 'is_active'] as $field) {
                if (array_key_exists($field, $body)) {
                    $changes[$field] = $body[$field];
                }
            }
            if (array_key_exists('allowed_usages', $body)) {
                $changes['allowed_usages'] = $this->stringList($body['allowed_usages']);
            }
            if (array_key_exists('pdf_tsa_enabled', $body)) {
                $pdfTsaEnabled = (bool) $body['pdf_tsa_enabled'];
                if ($pdfTsaEnabled) {
                    $pdfTsaUrl = $this->stringOrNull($body['pdf_tsa_url'] ?? null);
                    if ($pdfTsaUrl === null) {
                        return Json::error($response, 'validation_failed', 'Pro časové razítko zadej TSA URL.', 400);
                    }
                    $changes['pdf_tsa_url'] = $pdfTsaUrl;
                    $changes['pdf_tsa_username'] = $this->stringOrNull($body['pdf_tsa_username'] ?? null);
                    if (array_key_exists('pdf_tsa_password', $body)) {
                        $password = $this->stringOrNull($body['pdf_tsa_password']);
                        if ($password !== null) {
                            $changes['pdf_tsa_password_enc'] = $this->secrets->encrypt($password);
                        }
                    }
                } else {
                    $changes['pdf_tsa_url'] = null;
                    $changes['pdf_tsa_username'] = null;
                    $changes['pdf_tsa_password_enc'] = null;
                }
            } else {
                foreach (['pdf_tsa_url', 'pdf_tsa_username'] as $field) {
                    if (array_key_exists($field, $body)) {
                        $changes[$field] = $this->stringOrNull($body[$field]);
                    }
                }
                if (array_key_exists('pdf_tsa_password', $body)) {
                    $password = $this->stringOrNull($body['pdf_tsa_password']);
                    $changes['pdf_tsa_password_enc'] = $password !== null ? $this->secrets->encrypt($password) : null;
                }
            }
            if (array_key_exists('pdf_reason', $body)) {
                $changes['pdf_reason'] = $this->stringOrNull($body['pdf_reason']);
            }

            $this->profiles->updateProfile($supplierId, $profileId, $changes);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            if ($this->isIntegrityConflict($e)) {
                return Json::error($response, 'profile_conflict', 'Podpisový profil s tímto kódem už existuje.', 409);
            }
            return Json::error($response, 'update_failed', 'Podpisový profil se nepodařilo uložit.', 500);
        } catch (\Throwable) {
            return Json::error($response, 'update_failed', 'Podpisový profil se nepodařilo uložit.', 500);
        }

        $updated = $this->profiles->findProfile($supplierId, $profileId);
        $this->logger->log('signing.profile_updated', $this->userId($request), 'signing_profile', $profileId, [
            'changed_fields' => array_keys($changes),
            'code' => $updated['code'] ?? null,
        ], null, null, $supplierId);

        // Změna podpisově relevantních polí může zneplatnit cachované podpisy.
        $signingFields = ['is_active', 'allowed_usages', 'default_backend', 'code',
            'pdf_tsa_url', 'pdf_tsa_username', 'pdf_tsa_password_enc', 'pdf_reason'];
        if (array_intersect(array_keys($changes), $signingFields)) {
            $this->invalidatePdfCacheForSupplierSigning($supplierId);
        }

        return Json::ok($response, $updated);
    }

    public function deleteProfile(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }
        if (!$this->canManageProfile($request, $profile)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $this->profiles->softDeleteProfile($supplierId, $profileId);
        $this->logger->log('signing.profile_deleted', $this->userId($request), 'signing_profile', $profileId, [
            'code' => $profile['code'] ?? null,
            'owner_user_id' => $profile['owner_user_id'] ?? null,
        ], null, null, $supplierId);

        return Json::ok($response, ['deleted' => true]);
    }

    public function pdfSettings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $supplierId = $this->supplierId($request);
        $settings = [];
        foreach ($this->allOutputTypes() as $outputType) {
            $settings[] = $this->profiles->outputSetting($supplierId, $outputType, $this->usageForOutputType($outputType));
        }

        return Json::ok($response, [
            'output_types' => $this->allOutputTypes(),
            'output_settings' => $settings,
        ]);
    }

    public function updatePdfOutputSetting(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $outputType = (string) ($args['output_type'] ?? '');
        if (!$this->isSupportedOutputType($outputType)) {
            return Json::error($response, 'unsupported_output_type', 'Typ výstupu není podporovaný.', 404);
        }

        $supplierId = $this->supplierId($request);
        $usage = $this->usageForOutputType($outputType);
        $before = $this->profiles->outputSetting($supplierId, $outputType, $usage);
        $body = (array) ($request->getParsedBody() ?? []);
        $data = [];
        foreach (['enabled', 'backend', 'selection_source', 'user_profile_fallback', 'failure_policy'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        if (array_key_exists('default_profile_id', $body)) {
            $data['default_profile_id'] = $this->nullableInt($body['default_profile_id']);
        }
        if (array_key_exists('signature_config', $body)) {
            $data['signature_config'] = is_array($body['signature_config']) ? $body['signature_config'] : [];
        }

        try {
            $this->profiles->upsertOutputSetting($supplierId, $outputType, $data, $usage);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable) {
            return Json::error($response, 'update_failed', 'Nastavení podpisu se nepodařilo uložit.', 500);
        }

        $updated = $this->profiles->outputSetting($supplierId, $outputType, $usage);
        $invalidatedPdfCache = 0;
        if ($usage === 'pdf' && $this->pdfOutputSettingChanged($before, $updated)) {
            $invalidatedPdfCache = $this->invalidatePdfCacheForOutputSetting($supplierId, $outputType);
        }
        $this->logger->log('signing.output_settings_updated', $this->userId($request), 'supplier', $supplierId, [
            'output_type' => $outputType,
            'usage' => $usage,
            'enabled' => $updated['enabled'] ?? null,
            'selection_source' => $updated['selection_source'] ?? null,
            'default_profile_id' => $updated['default_profile_id'] ?? null,
            'failure_policy' => $updated['failure_policy'] ?? null,
            'invalidated_pdf_cache' => $invalidatedPdfCache,
        ], null, null, $supplierId);

        return Json::ok($response, $updated);
    }

    public function testPdfSigning(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $outputType = (string) ($body['output_type'] ?? 'invoice');
        if (!in_array($outputType, self::PDF_OUTPUT_TYPES, true)) {
            return Json::error($response, 'unsupported_output_type', 'Typ PDF výstupu není podporovaný.', 404);
        }

        $supplier = $this->supplierRow($this->supplierId($request));
        if ($supplier === null) {
            return Json::error($response, 'not_found', 'Dodavatel nenalezen.', 404);
        }

        $tmpPath = null;
        try {
            $tmpPath = $this->createSigningTestPdf();
            $result = $this->pdfSigning->testSupplierPdfSigning(
                tmpPath: $tmpPath,
                supplierRow: $supplier,
                outputType: $outputType,
                userId: $this->userId($request),
            );

            return Json::ok($response, $result);
        } catch (\Throwable) {
            return Json::error($response, 'test_failed', 'Test podpisu se nepodařilo spustit.', 500);
        } finally {
            if (is_string($tmpPath)) {
                if (is_file($tmpPath)) {
                    @unlink($tmpPath);
                }
                if (is_file($tmpPath . '.signed')) {
                    @unlink($tmpPath . '.signed');
                }
            }
        }
    }

    public function userDefaults(Request $request, Response $response): Response
    {
        if (!$this->canUseUserDefaults($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        return Json::ok($response, [
            'output_types' => $this->allOutputTypes(),
            'user_defaults' => array_merge(
                $this->profiles->listUserProfileDefaults($this->supplierId($request), 'pdf', $this->userId($request)),
                $this->profiles->listUserProfileDefaults($this->supplierId($request), 'email_smime', $this->userId($request)),
            ),
            'output_settings' => array_map(
                fn (string $outputType): array => $this->profiles->outputSetting(
                    $this->supplierId($request),
                    $outputType,
                    $this->usageForOutputType($outputType),
                ),
                $this->allOutputTypes(),
            ),
        ]);
    }

    public function updateUserDefault(Request $request, Response $response, array $args): Response
    {
        if (!$this->canUseUserDefaults($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $outputType = (string) ($args['output_type'] ?? '');
        if (!$this->isSupportedOutputType($outputType)) {
            return Json::error($response, 'unsupported_output_type', 'Typ výstupu není podporovaný.', 404);
        }

        $supplierId = $this->supplierId($request);
        $userId = $this->userId($request);
        $usage = $this->usageForOutputType($outputType);
        $body = (array) ($request->getParsedBody() ?? []);
        $profileId = $this->nullableInt($body['profile_id'] ?? $body['default_profile_id'] ?? null);

        if ($profileId === null) {
            $this->profiles->deleteUserProfileDefault($supplierId, $usage, $outputType, $userId);
            $this->logger->log('signing.user_default_updated', $userId, 'supplier', $supplierId, [
                'usage' => $usage,
                'output_type' => $outputType,
                'profile_id' => null,
            ], null, null, $supplierId);

            return Json::ok($response, null);
        }

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }
        $ownerUserId = $profile['owner_user_id'] !== null ? (int) $profile['owner_user_id'] : null;
        if ($ownerUserId !== $userId) {
            return Json::error($response, 'forbidden', 'Lze použít pouze vlastní podpisový profil.', 403);
        }
        if (!($profile['is_active'] ?? false) || !in_array($usage, $profile['allowed_usages'] ?? [], true)) {
            return Json::error($response, 'profile_not_usable', 'Profil není aktivní nebo nepodporuje dané použití.', 400);
        }

        try {
            $this->profiles->setUserProfileDefault($supplierId, $usage, $outputType, $userId, $profileId);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable) {
            return Json::error($response, 'update_failed', 'Výchozí podpisový profil se nepodařilo uložit.', 500);
        }

        $default = $this->profiles->userProfileDefault($supplierId, $usage, $outputType, $userId);
        $this->logger->log('signing.user_default_updated', $userId, 'supplier', $supplierId, [
            'usage' => $usage,
            'output_type' => $outputType,
            'profile_id' => $profileId,
        ], null, null, $supplierId);

        return Json::ok($response, $default);
    }

    public function credentialCertificate(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);

        $profile = $this->visibleProfile($request, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }

        $credential = $this->profiles->credential($supplierId, $profileId);
        return Json::ok($response, $this->credentialMeta($credential));
    }

    public function uploadCredentialCertificate(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }
        if (!$this->canManageProfile($request, $profile)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return Json::error($response, 'no_file', 'Žádný soubor nebyl odeslán (pole `file`).', 400);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_failed', 'Nahrání selhalo (kód ' . $file->getError() . ').', 400);
        }
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > self::MAX_CERT_SIZE) {
            return Json::error($response, 'bad_size', 'Certifikát musí být 1 B–128 KiB.', 413);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $password = (string) (($body['password'] ?? '') ?: '');
        if ($password === '') {
            return Json::error($response, 'password_required', 'Zadej heslo k certifikátu.', 400);
        }
        $passphrasePolicy = $this->passphrasePolicy((string) ($body['passphrase_policy'] ?? 'encrypted_store'));
        if ($passphrasePolicy === null) {
            return Json::error($response, 'unsupported_passphrase_policy', 'Politika hesla není podporovaná.', 400);
        }
        if ($passphrasePolicy === 'prompt_on_use') {
            return Json::error(
                $response,
                'prompt_on_use_unsupported',
                'Politika „ptát se při použití“ zatím není pro runtime podpisy podporovaná.',
                400
            );
        }
        $passphraseProfileId = $this->stringOrNull($body['passphrase_profile_id'] ?? null);
        if ($passphrasePolicy === 'passphrase_file' && $passphraseProfileId === null) {
            return Json::error($response, 'passphrase_profile_required', 'Pro passphrase_file zadej ID profilu hesla.', 400);
        }

        $p12 = (string) $file->getStream();
        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $password)) {
            return Json::error($response, 'bad_cert', 'P12/PFX nelze otevřít zadaným heslem (nebo není platný PKCS#12).', 422);
        }
        $certPem = (string) ($certs['cert'] ?? '');
        $info = openssl_x509_parse($certPem);
        if (!is_array($info) || ($info['validTo_time_t'] ?? 0) < time()) {
            return Json::error($response, 'cert_expired', 'Certifikát je expirovaný nebo nečitelný.', 422);
        }

        $relativePath = $this->credentialRelPath($supplierId, $profileId);
        $absolutePath = RuntimePaths::storage($relativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (@file_put_contents($absolutePath, $p12) === false) {
            return Json::error($response, 'store_failed', 'Certifikát se nepodařilo uložit.', 500);
        }
        @chmod($absolutePath, 0600);

        $this->profiles->upsertCredential($supplierId, $profileId, [
            'certificate_path' => $relativePath,
            'certificate_fingerprint' => openssl_x509_fingerprint($certPem, 'sha256') ?: null,
            'certificate_subject' => $this->distinguishedName($info['subject'] ?? []),
            'certificate_email' => $this->certificateEmail($info),
            'certificate_valid_from' => date('Y-m-d H:i:s', (int) ($info['validFrom_time_t'] ?? 0)),
            'certificate_valid_to' => date('Y-m-d H:i:s', (int) ($info['validTo_time_t'] ?? 0)),
            'certificate_usage' => $this->certificateUsage($info),
            'passphrase_policy' => $passphrasePolicy,
            'passphrase_profile_id' => $passphrasePolicy === 'passphrase_file' ? $passphraseProfileId : null,
            'encrypted_passphrase' => $passphrasePolicy === 'encrypted_store' ? $this->secrets->encrypt($password) : null,
            'is_active' => true,
        ], $this->userId($request));

        $credential = $this->profiles->credential($supplierId, $profileId);
        $this->logger->log('signing.credential_uploaded', $this->userId($request), 'signing_profile', $profileId, [
            'profile_code' => $profile['code'] ?? null,
            'fingerprint' => $credential['certificate_fingerprint'] ?? null,
            'passphrase_policy' => $credential['passphrase_policy'] ?? null,
        ], null, null, $supplierId);

        $this->invalidatePdfCacheForSupplierSigning($supplierId);

        return Json::ok($response, $this->credentialMeta($credential), 201);
    }

    public function updateCredentialCertificate(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }
        if (!$this->canManageProfile($request, $profile)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $credential = $this->profiles->credential($supplierId, $profileId);
        if ($credential === null) {
            return Json::error($response, 'no_certificate', 'Nejdřív nahraj certifikát profilu.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $passphrasePolicy = $this->passphrasePolicy((string) ($body['passphrase_policy'] ?? ($credential['passphrase_policy'] ?? 'encrypted_store')));
        if ($passphrasePolicy === null) {
            return Json::error($response, 'unsupported_passphrase_policy', 'Politika hesla není podporovaná.', 400);
        }
        if ($passphrasePolicy === 'prompt_on_use') {
            return Json::error(
                $response,
                'prompt_on_use_unsupported',
                'Politika „ptát se při použití“ zatím není pro runtime podpisy podporovaná.',
                400
            );
        }

        $passphraseProfileId = $this->stringOrNull($body['passphrase_profile_id'] ?? null);
        if ($passphrasePolicy === 'passphrase_file') {
            $passphraseProfileId ??= $this->stringOrNull($credential['passphrase_profile_id'] ?? null);
            if ($passphraseProfileId === null) {
                return Json::error($response, 'passphrase_profile_required', 'Pro passphrase_file zadej ID profilu hesla.', 400);
            }
        }

        $password = (string) (($body['password'] ?? '') ?: '');
        $encryptedPassphrase = null;
        if ($passphrasePolicy === 'encrypted_store') {
            $encryptedPassphrase = $password !== ''
                ? $this->secrets->encrypt($password)
                : $this->stringOrNull($credential['encrypted_passphrase'] ?? null);
            if ($encryptedPassphrase === null) {
                return Json::error(
                    $response,
                    'password_required',
                    'Pro uložení hesla v aplikaci zadej heslo k certifikátu.',
                    400
                );
            }
        }

        $this->profiles->updateCredentialPassphrasePolicy(
            $supplierId,
            $profileId,
            $passphrasePolicy,
            $passphraseProfileId,
            $encryptedPassphrase,
        );

        $credential = $this->profiles->credential($supplierId, $profileId);
        $this->logger->log('signing.credential_passphrase_updated', $this->userId($request), 'signing_profile', $profileId, [
            'profile_code' => $profile['code'] ?? null,
            'passphrase_policy' => $credential['passphrase_policy'] ?? null,
            'passphrase_profile_id' => $credential['passphrase_profile_id'] ?? null,
        ], null, null, $supplierId);

        $this->invalidatePdfCacheForSupplierSigning($supplierId);

        return Json::ok($response, $this->credentialMeta($credential));
    }

    public function deleteCredentialCertificate(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->supplierId($request);
        $profileId = (int) ($args['id'] ?? 0);

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Podpisový profil nenalezen.', 404);
        }
        if (!$this->canManageProfile($request, $profile)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        $credential = $this->profiles->credential($supplierId, $profileId);
        if ($credential !== null) {
            $path = $this->credentialAbsPath((string) ($credential['certificate_path'] ?? ''));
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            $this->profiles->softDeleteCredential($supplierId, $profileId);
        }

        $this->logger->log('signing.credential_deleted', $this->userId($request), 'signing_profile', $profileId, [
            'profile_code' => $profile['code'] ?? null,
        ], null, null, $supplierId);

        // Smazaný certifikát → cachované podepsané PDF by jinak zůstalo podepsané.
        if ($credential !== null) {
            $this->invalidatePdfCacheForSupplierSigning($supplierId);
        }

        return Json::ok($response, $this->credentialMeta(null));
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function pdfOutputSettingChanged(array $before, array $after): bool
    {
        foreach (['enabled', 'backend', 'selection_source', 'user_profile_fallback', 'default_profile_id', 'failure_policy'] as $field) {
            if (($before[$field] ?? null) != ($after[$field] ?? null)) {
                return true;
            }
        }

        return $this->jsonStable($before['signature_config'] ?? []) !== $this->jsonStable($after['signature_config'] ?? []);
    }

    private function invalidatePdfCacheForOutputSetting(int $supplierId, string $outputType): int
    {
        $sql = 'SELECT i.id, i.status
                  FROM invoices i
                 WHERE i.supplier_id = ?
                   AND (i.pdf_path IS NOT NULL OR i.pdf_generated_at IS NOT NULL)';
        if ($outputType === 'work_report') {
            $sql .= ' AND EXISTS (SELECT 1 FROM work_reports wr WHERE wr.invoice_id = i.id)';
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $this->pdf->invalidate(
                (int) $row['id'],
                'invalidate_signature_config',
                archive: (string) ($row['status'] ?? '') !== 'draft',
            );
        }

        return count($rows);
    }

    /**
     * Profil i jeho certifikát se mohou používat pro libovolný PDF výstup
     * (fakturu i výkaz) a u zdroje `admin_profile_settings` se podepsané PDF
     * cachuje. Při změně certifikátu, TSA, důvodu nebo (de)aktivaci profilu proto
     * invaliduj VŠECHNY cachované PDF dodavatele — jinak by se servíroval starý
     * podpis, nebo dokonce podepsané PDF s už smazaným certifikátem. Dotaz bez
     * output filtru pokrývá faktury i výkazy (oba visí na řádku faktury).
     */
    private function invalidatePdfCacheForSupplierSigning(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.status
               FROM invoices i
              WHERE i.supplier_id = ?
                AND (i.pdf_path IS NOT NULL OR i.pdf_generated_at IS NOT NULL)'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $this->pdf->invalidate(
                (int) $row['id'],
                'invalidate_signature_config',
                archive: (string) ($row['status'] ?? '') !== 'draft',
            );
        }

        return count($rows);
    }

    /**
     * @param mixed $value
     */
    private function jsonStable($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function visibleProfile(Request $request, int $profileId): ?array
    {
        $supplierId = $this->supplierId($request);
        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if ($profile === null || $this->isAdmin($request)) {
            return $profile;
        }

        $settings = $this->profiles->settings($supplierId);
        if ($this->isAccountant($request)
            && $settings['accountant_profiles_enabled']
            && ($profile['owner_user_id'] ?? null) === $this->userId($request)
        ) {
            return $profile;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function canManageProfile(Request $request, array $profile): bool
    {
        $settings = $this->profiles->settings($this->supplierId($request));

        return $this->access->canManage(
            role: $this->role($request),
            currentUserId: $this->userId($request),
            ownerUserId: $profile['owner_user_id'] !== null ? (int) $profile['owner_user_id'] : null,
            accountantProfilesEnabled: $settings['accountant_profiles_enabled'],
        );
    }

    private function canUseUserDefaults(Request $request): bool
    {
        if ($this->isAdmin($request)) {
            return true;
        }

        $settings = $this->profiles->settings($this->supplierId($request));
        return $this->isAccountant($request) && $settings['accountant_profiles_enabled'];
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function supplierRow(int $supplierId): ?array
    {
        if ($supplierId <= 0) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    private function role(Request $request): string
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (string) ($user['role'] ?? '');
    }

    private function isAdmin(Request $request): bool
    {
        return $this->role($request) === 'admin';
    }

    private function isAccountant(Request $request): bool
    {
        return $this->role($request) === 'accountant';
    }

    private function isIntegrityConflict(\PDOException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('allowed_usages musí být pole.');
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }

    /**
     * @return list<string>
     */
    private function allOutputTypes(): array
    {
        return array_merge(self::PDF_OUTPUT_TYPES, self::EMAIL_OUTPUT_TYPES);
    }

    private function isSupportedOutputType(string $outputType): bool
    {
        return in_array($outputType, $this->allOutputTypes(), true);
    }

    private function usageForOutputType(string $outputType): string
    {
        return str_starts_with($outputType, 'email_') ? 'email_smime' : 'pdf';
    }

    private function passphrasePolicy(string $policy): ?string
    {
        return in_array($policy, self::PASSPHRASE_POLICIES, true) ? $policy : null;
    }

    /**
     * @param mixed $value
     */
    private function stringOrNull($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function encryptedOptionalSecret($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $this->secrets->encrypt($value) : null;
    }

    private function credentialRelPath(int $supplierId, int $profileId): string
    {
        return "signing/profiles/supplier-{$supplierId}/profile-{$profileId}/profile.p12";
    }

    private function credentialAbsPath(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        if (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $stored) === 1) {
            return $stored;
        }

        return RuntimePaths::storage(ltrim($stored, '/\\'));
    }

    private function createSigningTestPdf(): string
    {
        $dir = RuntimePaths::storage('tmp/signing-tests');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nelze vytvořit adresář pro testovací PDF.');
        }

        $path = tempnam($dir, 'pdf-');
        if (!is_string($path)) {
            throw new \RuntimeException('Nelze vytvořit dočasný PDF soubor.');
        }

        if (@file_put_contents($path, $this->samplePdf()) === false) {
            throw new \RuntimeException('Nelze zapsat dočasný PDF soubor.');
        }
        @chmod($path, 0600);

        return $path;
    }

    private function samplePdf(): string
    {
        $stream = "BT\n"
            . "/F1 12 Tf\n"
            . "40 100 Td\n"
            . "(MyInvoice PDF signing test) Tj\n"
            . "0 -18 Td\n"
            . "(Generated for signature verification.) Tj\n"
            . "ET\n";

        $objects = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] "
                . "/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
            "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $number = $index + 1;
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param array<string,mixed> $info
     * @return array<string,mixed>
     */
    private function certificateUsage(array $info): array
    {
        $extensions = isset($info['extensions']) && is_array($info['extensions']) ? $info['extensions'] : [];

        return [
            'key_usage' => isset($extensions['keyUsage']) ? (string) $extensions['keyUsage'] : null,
            'extended_key_usage' => isset($extensions['extendedKeyUsage']) ? (string) $extensions['extendedKeyUsage'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $info
     */
    private function certificateEmail(array $info): ?string
    {
        $subject = isset($info['subject']) && is_array($info['subject']) ? $info['subject'] : [];
        foreach (['emailAddress', 'E'] as $field) {
            if (!empty($subject[$field]) && is_scalar($subject[$field])) {
                return (string) $subject[$field];
            }
        }
        $extensions = isset($info['extensions']) && is_array($info['extensions']) ? $info['extensions'] : [];
        $san = isset($extensions['subjectAltName']) ? (string) $extensions['subjectAltName'] : '';
        if (preg_match('/email:([^,\s]+)/i', $san, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param mixed $dn
     */
    private function distinguishedName($dn): ?string
    {
        if (!is_array($dn)) {
            return null;
        }
        $parts = [];
        foreach ($dn as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = (string) $key . '=' . (string) $value;
            }
        }

        return $parts !== [] ? implode(',', $parts) : null;
    }

    /**
     * @param array<string,mixed>|null $credential
     * @return array<string,mixed>
     */
    private function credentialMeta(?array $credential): array
    {
        if ($credential === null) {
            return [
                'has_certificate' => false,
            ];
        }

        $validTo = $credential['certificate_valid_to'] ?? null;

        return [
            'has_certificate' => true,
            'certificate_fingerprint' => $credential['certificate_fingerprint'] ?? null,
            'certificate_subject' => $credential['certificate_subject'] ?? null,
            'certificate_email' => $credential['certificate_email'] ?? null,
            'certificate_valid_from' => $credential['certificate_valid_from'] ?? null,
            'certificate_valid_to' => $validTo,
            'certificate_usage' => $credential['certificate_usage'] ?? [],
            'passphrase_policy' => $credential['passphrase_policy'] ?? null,
            'passphrase_profile_id' => $credential['passphrase_profile_id'] ?? null,
            'is_active' => $credential['is_active'] ?? false,
            'expired' => is_string($validTo) && strtotime($validTo) !== false && strtotime($validTo) < time(),
        ];
    }
}
