<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Pdf;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\SigningConfig;
use MyInvoice\Service\Signing\SigningProfile;

final class PdfSigningService
{
    public function __construct(
        private readonly Config $config,
        private readonly ActivityLogger $activity,
        private readonly NativePdfSignatureBackend $nativeBackend,
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
        $policy = new PdfSignaturePolicy($this->failurePolicy());
        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;

        if (!$this->platformEnabled()) {
            return $tmpPath;
        }

        if (!$this->outputEnabled($documentType)) {
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

        if (!$this->supplierSigningEnabled($supplierRow)) {
            return $this->handleSkipped(
                tmpPath: $tmpPath,
                policy: $policy,
                documentType: $documentType,
                documentId: $documentId,
                supplierId: $supplierId,
                userId: $userId,
                reason: 'supplier_disabled',
            );
        }

        $profile = $this->supplierDefaultProfile($supplierRow);
        if ($profile === null || $profile->pdfConfig === null) {
            return $this->handleUnconfigured(
                tmpPath: $tmpPath,
                policy: $policy,
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

    private function platformEnabled(): bool
    {
        return (bool) $this->config->get('pdf_signing.enabled', true);
    }

    private function failurePolicy(): string
    {
        $policy = (string) $this->config->get('pdf_signing.failure_policy', PdfSignaturePolicy::FALLBACK_UNSIGNED);
        return in_array($policy, [
            PdfSignaturePolicy::FALLBACK_UNSIGNED,
            PdfSignaturePolicy::FAIL_CLOSED,
            PdfSignaturePolicy::SKIP_WHEN_UNCONFIGURED,
        ], true) ? $policy : PdfSignaturePolicy::FALLBACK_UNSIGNED;
    }

    private function outputEnabled(string $documentType): bool
    {
        $key = match ($documentType) {
            'invoice' => 'invoices',
            'work_report' => 'work_reports',
            default => $documentType,
        };

        return (bool) $this->config->get('pdf_signing.enabled_outputs.' . $key, true);
    }

    /**
     * @param array<string,mixed> $supplierRow
     */
    private function supplierSigningEnabled(array $supplierRow): bool
    {
        return (int) ($supplierRow['pdf_signing_enabled'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $supplierRow
     */
    private function supplierDefaultProfile(array $supplierRow): ?SigningProfile
    {
        $cfg = SigningConfig::fromSupplierRow($supplierRow);
        if ($cfg === null) {
            return null;
        }

        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;
        return new SigningProfile(
            code: 'supplier_default',
            ownerType: 'supplier',
            ownerId: $supplierId,
            backend: 'native',
            pdfConfig: $cfg,
            metadata: ['source' => 'supplier'],
        );
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
     * @param array<string,mixed> $supplierRow
     * @return array<string,mixed>
     */
    public function diagnosticsForSupplier(array $supplierRow): array
    {
        $storedCert = trim((string) ($supplierRow['signing_cert_path'] ?? ''));
        $certPath = $storedCert !== '' ? SigningConfig::absCertPath($storedCert) : '';
        $hasCert = $certPath !== '' && is_file($certPath);
        $profile = $this->supplierDefaultProfile($supplierRow);
        $backend = $this->nativeBackend;
        $health = $backend->healthCheck($profile);
        $capabilities = $backend->capabilities();
        $platformEnabled = $this->platformEnabled();
        $supplierEnabled = (int) ($supplierRow['pdf_signing_enabled'] ?? 0) === 1;

        $unavailableReason = null;
        if (!$platformEnabled) {
            $unavailableReason = 'platform_disabled';
        } elseif (!$supplierEnabled) {
            $unavailableReason = 'supplier_disabled';
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
                'code' => 'supplier_default',
                'available' => $profile !== null,
                'owner_type' => 'supplier',
                'owner_id' => (int) ($supplierRow['id'] ?? 0) ?: null,
                'source' => 'supplier',
            ],
            'certificate' => [
                'configured' => $storedCert !== '',
                'exists' => $hasCert,
                'storage' => $storedCert !== '' && !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $storedCert)
                    ? 'data_dir_relative'
                    : ($storedCert !== '' ? 'absolute_legacy' : 'none'),
            ],
            'tsa' => [
                'configured' => !empty($supplierRow['signing_tsa_url']),
                'auth_configured' => !empty($supplierRow['signing_tsa_username'])
                    || !empty($supplierRow['signing_tsa_password_enc']),
            ],
        ];
    }

    private function sanitizeError(string $error): string
    {
        $error = (string) preg_replace('#(?:[A-Za-z]:)?[/\\\\][^\s]+#', '[path]', $error);
        return mb_substr($error, 0, 300);
    }
}
