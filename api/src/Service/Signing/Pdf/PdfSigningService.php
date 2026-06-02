<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\SigningConfig;
use MyInvoice\Service\Signing\SigningProfile;
use MyInvoice\Service\Signing\SigningPassphraseProviderInterface;

final class PdfSigningService
{
    public function __construct(
        private readonly Config $config,
        private readonly ActivityLogger $activity,
        private readonly NativePdfSignatureBackend $nativeBackend,
        private readonly ?SigningProfileRepository $profiles = null,
        private readonly ?SigningPassphraseProviderInterface $passphrases = null,
    ) {}

    /**
     * Podepíše PDF podle současného per-supplier nastavení.
     *
     * @param array<string,mixed> $supplierRow řádek tabulky supplier (SELECT s.*)
     */
    public function signSupplierPdfIfEnabled(
        string $tmpPath,
        array $supplierRow,
        string $documentType,
        int $documentId,
        ?int $userId = null,
    ): string {
        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;

        if (!$this->platformEnabled()) {
            return $tmpPath;
        }

        $outputSetting = $this->effectiveOutputSetting($supplierId, $documentType, $documentId);
        $policy = new PdfSignaturePolicy($this->failurePolicy($outputSetting));

        if (!$this->configOutputEnabled($documentType) || !$this->outputSettingEnabled($outputSetting)) {
            return $this->handleSkipped(
                tmpPath: $tmpPath,
                policy: $policy,
                documentType: $documentType,
                documentId: $documentId,
                supplierId: $supplierId,
                userId: $userId,
                reason: 'output_disabled',
            );
        }

        $profile = $this->selectProfile($supplierRow, $outputSetting, $userId, $documentType);
        if ($profile === null || $profile->pdfConfig === null) {
            return $this->handleUnconfigured(
                tmpPath: $tmpPath,
                policy: new PdfSignaturePolicy($this->unconfiguredFailurePolicy($outputSetting)),
                documentType: $documentType,
                documentId: $documentId,
                supplierId: $supplierId,
                userId: $userId,
                profile: $profile,
            );
        }

        $backend = $this->backendFor($profile);
        $capabilities = $backend->capabilities();
        $appearance = PdfSignatureAppearance::invisible();

        if (!$capabilities->supportsInvisible) {
            return $this->handleFailure(
                tmpPath: $tmpPath,
                policy: $policy,
                documentType: $documentType,
                documentId: $documentId,
                supplierId: $supplierId,
                userId: $userId,
                profile: $profile,
                backend: $backend->id(),
                error: 'Vybraný backend nepodporuje neviditelný PDF podpis.',
            );
        }

        $outputPath = $tmpPath . '.signed';
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }

        try {
            $result = $backend->sign(new PdfSigningRequest(
                inputPath: $tmpPath,
                outputPath: $outputPath,
                documentType: $documentType,
                documentId: $documentId,
                profile: $profile,
                appearance: $appearance,
                policy: $policy,
                supplierId: $supplierId,
                userId: $userId,
            ));

            @unlink($tmpPath);
            $this->activity->log('signing.pdf_signed', $userId, $documentType, $documentId, [
                'level' => $result->level,
                'tsa_url' => $profile->pdfConfig->tsaUrl,
                'status' => 'signed',
                'backend' => $result->backend,
                'profile_code' => $profile->code,
                'timestamped' => $result->timestamped,
            ], null, null, $supplierId);

            return $result->outputPath;
        } catch (\Throwable $e) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            return $this->handleFailure(
                tmpPath: $tmpPath,
                policy: $policy,
                documentType: $documentType,
                documentId: $documentId,
                supplierId: $supplierId,
                userId: $userId,
                profile: $profile,
                backend: $backend->id(),
                error: $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @param array<string,mixed> $supplierRow
     */
    public function outputDependsOnUserProfile(array $supplierRow, string $documentType, int $documentId): bool
    {
        if (!$this->platformEnabled() || !$this->configOutputEnabled($documentType)) {
            return false;
        }

        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;
        $outputSetting = $this->effectiveOutputSetting($supplierId, $documentType, $documentId);
        if (!$this->outputSettingEnabled($outputSetting)) {
            return false;
        }

        return (string) ($outputSetting['selection_source'] ?? 'admin_profile_settings') === 'logged_in_user';
    }

    /**
     * Provede test podpisu na dočasném PDF a vrátí bezpečný výsledek pro UI.
     *
     * @param array<string,mixed> $supplierRow řádek tabulky supplier (SELECT s.*)
     * @return array<string,mixed>
     */
    public function testSupplierPdfSigning(
        string $tmpPath,
        array $supplierRow,
        string $outputType,
        ?int $userId = null,
    ): array {
        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;
        $outputSetting = $this->outputSetting($supplierId, $outputType);
        $policy = new PdfSignaturePolicy($this->failurePolicy($outputSetting));
        $base = [
            'output_type' => $outputType,
            'backend' => (string) $this->config->get('pdf_signing.default_backend', 'native'),
            'profile_code' => null,
            'certificate_cn' => null,
            'level' => null,
            'timestamped' => false,
            'failure_policy' => $policy->failurePolicy,
        ];

        if (!$this->platformEnabled()) {
            return $this->testSkipped($userId, $supplierId, $base, 'platform_disabled');
        }

        if (!$this->configOutputEnabled($outputType) || !$this->outputSettingEnabled($outputSetting)) {
            return $this->testSkipped($userId, $supplierId, $base, 'output_disabled');
        }

        $profile = $this->selectProfile($supplierRow, $outputSetting, $userId, $outputType);
        $base['profile_code'] = $profile?->code;
        if ($profile === null || $profile->pdfConfig === null) {
            $unconfiguredPolicy = new PdfSignaturePolicy($this->unconfiguredFailurePolicy($outputSetting));
            $base['failure_policy'] = $unconfiguredPolicy->failurePolicy;
            if ($unconfiguredPolicy->failClosed()) {
                return $this->testFailed($userId, $supplierId, $base, 'Podpisový profil není nakonfigurovaný.');
            }

            return $this->testSkipped($userId, $supplierId, $base, 'missing_profile');
        }

        $backend = $this->backendFor($profile);
        $base['backend'] = $backend->id();
        $capabilities = $backend->capabilities();
        if (!$capabilities->supportsInvisible) {
            return $this->testFailed($userId, $supplierId, $base, 'Vybraný backend nepodporuje neviditelný PDF podpis.');
        }

        $outputPath = $tmpPath . '.signed';
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }

        try {
            $result = $backend->sign(new PdfSigningRequest(
                inputPath: $tmpPath,
                outputPath: $outputPath,
                documentType: $outputType,
                documentId: 0,
                profile: $profile,
                appearance: PdfSignatureAppearance::invisible(),
                policy: $policy,
                supplierId: $supplierId,
                userId: $userId,
            ));

            $payload = array_merge($base, [
                'status' => 'signed',
                'level' => $result->level,
                'timestamped' => $result->timestamped,
                'certificate_cn' => $result->metadata['certificate_cn'] ?? null,
            ]);
            $this->activity->log('signing.test_signed', $userId, 'supplier', $supplierId ?? 0, $payload, null, null, $supplierId);

            return $payload;
        } catch (\Throwable $e) {
            $status = $policy->failClosed() ? 'failed' : 'fallback_unsigned';
            return $this->testFailed($userId, $supplierId, $base, $e->getMessage(), $status);
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function platformEnabled(): bool
    {
        return (bool) $this->config->get('pdf_signing.enabled', true);
    }

    /**
     * @param array<string,mixed>|null $outputSetting
     */
    private function failurePolicy(?array $outputSetting = null): string
    {
        $policy = (string) ($outputSetting['failure_policy'] ?? $this->config->get('pdf_signing.failure_policy', PdfSignaturePolicy::FALLBACK_UNSIGNED));
        return in_array($policy, [
            PdfSignaturePolicy::FALLBACK_UNSIGNED,
            PdfSignaturePolicy::FAIL_CLOSED,
            PdfSignaturePolicy::SKIP_WHEN_UNCONFIGURED,
        ], true) ? $policy : PdfSignaturePolicy::FALLBACK_UNSIGNED;
    }

    /**
     * @param array<string,mixed>|null $outputSetting
     */
    private function unconfiguredFailurePolicy(?array $outputSetting = null): string
    {
        if (($outputSetting['selection_source'] ?? null) === 'logged_in_user') {
            $fallback = (string) ($outputSetting['user_profile_fallback'] ?? '');
            if ($fallback === 'fail_closed') {
                return PdfSignaturePolicy::FAIL_CLOSED;
            }
            if ($fallback === 'fallback_unsigned') {
                return PdfSignaturePolicy::FALLBACK_UNSIGNED;
            }
        }

        return $this->failurePolicy($outputSetting);
    }

    private function configOutputEnabled(string $documentType): bool
    {
        $key = match ($documentType) {
            'invoice' => 'invoices',
            'work_report' => 'work_reports',
            default => $documentType,
        };

        return (bool) $this->config->get('pdf_signing.enabled_outputs.' . $key, true);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function outputSetting(?int $supplierId, string $documentType): ?array
    {
        if ($supplierId === null || $this->profiles === null) {
            return null;
        }

        return $this->profiles->outputSetting($supplierId, $documentType);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function effectiveOutputSetting(?int $supplierId, string $documentType, int $documentId): ?array
    {
        $outputSetting = $this->outputSetting($supplierId, $documentType);
        if ($supplierId === null || $this->profiles === null || $outputSetting === null || $documentId <= 0) {
            return $outputSetting;
        }

        $override = $this->profiles->documentOverride($supplierId, 'pdf', $documentType, $documentId);
        if ($override === null) {
            return $outputSetting;
        }

        $outputSetting['selection_source'] = (string) $override['selection_source'];
        if ($outputSetting['selection_source'] === 'admin_profile_settings') {
            if (($override['admin_profile_id'] ?? null) !== null) {
                $outputSetting['default_profile_id'] = (int) $override['admin_profile_id'];
            }
        } else {
            $outputSetting['default_profile_id'] = null;
        }
        $outputSetting['document_override'] = [
            'entity_type' => $documentType,
            'entity_id' => $documentId,
            'selection_source' => $override['selection_source'],
            'admin_profile_id' => $override['admin_profile_id'] ?? null,
        ];

        return $outputSetting;
    }

    /**
     * @param array<string,mixed>|null $outputSetting
     */
    private function outputSettingEnabled(?array $outputSetting): bool
    {
        return $outputSetting === null || (bool) ($outputSetting['enabled'] ?? true);
    }

    /**
     * @param array<string,mixed> $supplierRow
     * @param array<string,mixed>|null $outputSetting
     */
    private function selectProfile(
        array $supplierRow,
        ?array $outputSetting,
        ?int $userId,
        string $documentType,
    ): ?SigningProfile {
        $source = (string) ($outputSetting['selection_source'] ?? 'admin_profile_settings');

        if ($source === 'admin_profile_settings') {
            return $this->profileById($supplierRow, (int) ($outputSetting['default_profile_id'] ?? 0), $documentType);
        }

        if ($source === 'logged_in_user') {
            $profile = $this->profileForUser($supplierRow, $userId, (string) ($outputSetting['output_type'] ?? ''), $documentType);
            return $profile ?? $this->fallbackProfile($supplierRow, $outputSetting, 'logged_in_user', $documentType);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $supplierRow
     * @param array<string,mixed>|null $outputSetting
     */
    private function fallbackProfile(array $supplierRow, ?array $outputSetting, string $source, string $documentType): ?SigningProfile
    {
        $fallback = (string) ($outputSetting['user_profile_fallback'] ?? 'fallback_unsigned');

        if ($fallback === 'admin_profile_settings') {
            return $this->profileById($supplierRow, (int) ($outputSetting['default_profile_id'] ?? 0), $documentType);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $supplierRow
     */
    private function profileById(array $supplierRow, int $profileId, string $documentType): ?SigningProfile
    {
        $supplierId = (int) ($supplierRow['id'] ?? 0);
        if ($supplierId <= 0 || $profileId <= 0 || $this->profiles === null) {
            return null;
        }

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if (is_array($profile) && ($profile['owner_user_id'] ?? null) !== null) {
            return null;
        }

        return is_array($profile) ? $this->storedProfile($supplierRow, $profile, 'admin_profile_settings', $documentType) : null;
    }

    /**
     * @param array<string,mixed> $supplierRow
     */
    private function profileForUser(array $supplierRow, ?int $userId, string $outputType, string $documentType): ?SigningProfile
    {
        $supplierId = (int) ($supplierRow['id'] ?? 0);
        if ($supplierId <= 0 || $userId === null || $userId <= 0 || $this->profiles === null) {
            return null;
        }

        if ($outputType !== '') {
            $default = $this->profiles->userProfileDefault($supplierId, 'pdf', $outputType, $userId);
            if ($default !== null) {
                $profile = $this->profiles->findProfile($supplierId, (int) ($default['profile_id'] ?? 0));
                if (is_array($profile)) {
                    $stored = $this->storedProfile($supplierRow, $profile, 'logged_in_user_default', $documentType);
                    if ($stored !== null) {
                        return $stored;
                    }
                }
            }
        }

        foreach ($this->profiles->listProfilesForOwner($supplierId, $userId) as $profile) {
            $stored = $this->storedProfile($supplierRow, $profile, 'logged_in_user', $documentType);
            if ($stored !== null) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $supplierRow
     * @param array<string,mixed> $profile
     */
    private function storedProfile(array $supplierRow, array $profile, string $source, string $documentType): ?SigningProfile
    {
        $supplierId = (int) ($supplierRow['id'] ?? 0);
        $profileId = (int) ($profile['id'] ?? 0);
        if ($supplierId <= 0 || $profileId <= 0 || $this->profiles === null) {
            return null;
        }
        if (!($profile['is_active'] ?? false) || !in_array('pdf', $profile['allowed_usages'] ?? [], true)) {
            return null;
        }

        $credential = $this->profiles->credential($supplierId, $profileId);
        if ($credential === null || !($credential['is_active'] ?? false)) {
            return null;
        }
        $passwordEnc = $this->encryptedPassphraseForCredential($credential);
        if ($passwordEnc === null) {
            return null;
        }

        $tsa = $this->stringOrNull($profile['pdf_tsa_url'] ?? null);
        $tsaUser = $this->stringOrNull($profile['pdf_tsa_username'] ?? null);
        $profileTsaPasswordEnc = $this->profiles->profilePdfTsaPasswordEnc($supplierId, $profileId);
        $tsaPasswordEnc = $this->stringOrNull($profileTsaPasswordEnc);
        $reason = $this->stringOrNull($profile['pdf_reason'] ?? null) ?? SigningConfig::defaultReason($documentType);
        $cfg = new SigningConfig(
            certPath: SigningConfig::absCertPath((string) ($credential['certificate_path'] ?? '')),
            passwordEnc: $passwordEnc,
            tsaUrl: ($tsa !== null && $tsa !== '') ? (string) $tsa : null,
            reason: $reason,
            tsaUsername: ($tsaUser !== null && $tsaUser !== '') ? (string) $tsaUser : null,
            tsaPasswordEnc: (string) ($tsaPasswordEnc ?? ''),
        );

        return new SigningProfile(
            code: (string) $profile['code'],
            ownerType: $profile['owner_user_id'] !== null ? 'user' : 'supplier',
            ownerId: $profile['owner_user_id'] !== null ? (int) $profile['owner_user_id'] : $supplierId,
            backend: (string) ($profile['default_backend'] ?? 'native'),
            pdfConfig: $cfg,
            metadata: [
                'source' => $source,
                'profile_id' => $profileId,
                'passphrase_policy' => $credential['passphrase_policy'],
            ],
        );
    }

    /**
     * @param array<string,mixed> $credential
     */
    private function encryptedPassphraseForCredential(array $credential): ?string
    {
        if ($this->passphrases !== null) {
            return $this->passphrases->encryptedPassphraseForCredential($credential);
        }

        if (($credential['passphrase_policy'] ?? null) !== 'encrypted_store') {
            return null;
        }
        $passwordEnc = trim((string) ($credential['encrypted_passphrase'] ?? ''));
        return $passwordEnc !== '' ? $passwordEnc : null;
    }

    /**
     * @param mixed $value
     */
    private function stringOrNull($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function backendFor(SigningProfile $profile): PdfSignatureBackendInterface
    {
        // První implementační iterace podporuje jen nativní backend.
        return $this->nativeBackend;
    }

    private function handleFailure(
        string $tmpPath,
        PdfSignaturePolicy $policy,
        string $documentType,
        int $documentId,
        ?int $supplierId,
        ?int $userId,
        SigningProfile $profile,
        string $backend,
        string $error,
        ?\Throwable $previous = null,
    ): string {
        $this->activity->log('signing.failed', $userId, $documentType, $documentId, [
            'document_type' => $documentType,
            'document_id' => $documentId,
            'status' => $policy->failClosed() ? 'failed' : 'fallback_unsigned',
            'error' => $this->sanitizeError($error),
            'backend' => $backend,
            'profile_code' => $profile->code,
            'failure_policy' => $policy->failurePolicy,
        ], null, null, $supplierId);

        if ($policy->failClosed()) {
            throw new \RuntimeException('PDF podpis selhal.', 0, $previous);
        }

        return $tmpPath;
    }

    private function handleUnconfigured(
        string $tmpPath,
        PdfSignaturePolicy $policy,
        string $documentType,
        int $documentId,
        ?int $supplierId,
        ?int $userId,
        ?SigningProfile $profile,
    ): string {
        if ($policy->failClosed()) {
            $this->activity->log('signing.failed', $userId, $documentType, $documentId, [
                'document_type' => $documentType,
                'document_id' => $documentId,
                'status' => 'failed',
                'error' => 'Podpisový profil není nakonfigurovaný.',
                'backend' => (string) $this->config->get('pdf_signing.default_backend', 'native'),
                'profile_code' => $profile?->code,
                'failure_policy' => $policy->failurePolicy,
            ], null, null, $supplierId);

            throw new \RuntimeException('PDF podpis není nakonfigurovaný.');
        }

        return $this->handleSkipped(
            tmpPath: $tmpPath,
            policy: $policy,
            documentType: $documentType,
            documentId: $documentId,
            supplierId: $supplierId,
            userId: $userId,
            reason: 'missing_profile',
            profile: $profile,
        );
    }

    private function handleSkipped(
        string $tmpPath,
        PdfSignaturePolicy $policy,
        string $documentType,
        int $documentId,
        ?int $supplierId,
        ?int $userId,
        string $reason,
        ?SigningProfile $profile = null,
    ): string {
        $this->activity->log('signing.skipped', $userId, $documentType, $documentId, [
            'document_type' => $documentType,
            'document_id' => $documentId,
            'status' => 'skipped',
            'reason' => $reason,
            'backend' => (string) $this->config->get('pdf_signing.default_backend', 'native'),
            'profile_code' => $profile?->code,
            'failure_policy' => $policy->failurePolicy,
        ], null, null, $supplierId);

        return $tmpPath;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function testSkipped(?int $userId, ?int $supplierId, array $base, string $reason): array
    {
        $payload = $base + [
            'status' => 'skipped',
            'reason' => $reason,
        ];
        $this->activity->log('signing.test_skipped', $userId, 'supplier', $supplierId ?? 0, $payload, null, null, $supplierId);

        return $payload;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function testFailed(
        ?int $userId,
        ?int $supplierId,
        array $base,
        string $error,
        string $status = 'failed',
    ): array {
        $payload = $base + [
            'status' => $status,
            'error' => $this->sanitizeError($error),
        ];
        $this->activity->log('signing.test_failed', $userId, 'supplier', $supplierId ?? 0, $payload, null, null, $supplierId);

        return $payload;
    }

    /**
     * @param array<string,mixed> $supplierRow
     * @return array<string,mixed>
     */
    public function diagnosticsForSupplier(array $supplierRow): array
    {
        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;
        $outputSetting = $this->outputSetting($supplierId, 'invoice');
        $profile = $this->selectProfile($supplierRow, $outputSetting, null, 'invoice');
        $certPath = $profile?->pdfConfig?->certPath ?? '';
        $hasCert = $certPath !== '' && is_file($certPath);
        $backend = $this->nativeBackend;
        $health = $backend->healthCheck($profile);
        $capabilities = $backend->capabilities();
        $platformEnabled = $this->platformEnabled();
        $supplierEnabled = true;

        $unavailableReason = null;
        if (!$platformEnabled) {
            $unavailableReason = 'platform_disabled';
        } elseif (!$hasCert) {
            $unavailableReason = 'missing_certificate';
        } elseif (!$health->ok) {
            $unavailableReason = 'backend_unhealthy';
        }

        return [
            'platform_enabled' => $platformEnabled,
            'supplier_enabled' => $supplierEnabled,
            'effective_can_sign' => $unavailableReason === null,
            'unavailable_reason' => $unavailableReason,
            'failure_policy' => $this->failurePolicy(),
            'backend' => [
                'configured' => (string) $this->config->get('pdf_signing.default_backend', 'native'),
                'effective' => $backend->id(),
                'health' => [
                    'ok' => $health->ok,
                    'message' => $health->message,
                ],
                'capabilities' => [
                    'supports_invisible' => $capabilities->supportsInvisible,
                    'supports_visible' => $capabilities->supportsVisible,
                    'supports_append_signature_page' => $capabilities->supportsAppendSignaturePage,
                    'supports_timestamp' => $capabilities->supportsTimestamp,
                    'supports_pades' => $capabilities->supportsPades,
                    'requires_external_binary' => $capabilities->requiresExternalBinary,
                    'supported_certificate_types' => $capabilities->supportedCertificateTypes,
                ],
            ],
            'profile' => [
                'code' => $profile?->code,
                'available' => $profile !== null,
                'owner_type' => $profile?->ownerType,
                'owner_id' => $profile?->ownerId,
                'source' => $profile?->metadata['source'] ?? null,
            ],
            'certificate' => [
                'configured' => $certPath !== '',
                'exists' => $hasCert,
                'storage' => $certPath !== '' && !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $certPath)
                    ? 'data_dir_relative'
                    : ($certPath !== '' ? 'absolute' : 'none'),
            ],
            'tsa' => [
                'configured' => !empty($profile?->pdfConfig?->tsaUrl),
                'auth_configured' => !empty($profile?->pdfConfig?->tsaUsername)
                    || !empty($profile?->pdfConfig?->tsaPasswordEnc),
            ],
        ];
    }

    private function sanitizeError(string $error): string
    {
        $error = (string) preg_replace('#(?:[A-Za-z]:)?[/\\\\][^\s]+#', '[path]', $error);
        return mb_substr($error, 0, 300);
    }
}
