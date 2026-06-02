<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing\Email;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Signing\Pdf\PdfSignaturePolicy;
use MyInvoice\Service\Signing\SigningPassphraseProviderInterface;
use Symfony\Component\Mime\Crypto\SMimeSigner;
use Symfony\Component\Mime\Message;

final class EmailSigningService
{
    private const USAGE = 'email_smime';

    private const TEMPLATE_OUTPUT_TYPES = [
        'invoice_send' => 'email_invoice_send',
        'invoice_reminder' => 'email_invoice_reminder',
        'proforma_reminder' => 'email_proforma_reminder',
        'invoice_payment_thanks' => 'email_invoice_payment_thanks',
        'invoice_approval' => 'email_invoice_approval',
        'recurring_draft_reminder' => 'email_recurring_draft_reminder',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ActivityLogger $activity,
        private readonly SigningProfileRepository $profiles,
        private readonly SigningPassphraseProviderInterface $passphrases,
        private readonly SecretEncryption $secrets,
    ) {}

    /**
     * @param array<string,mixed>|null $supplier
     */
    public function signIfEnabled(Message $message, string $templateCode, ?array $supplier, ?int $userId = null): Message
    {
        $outputType = self::TEMPLATE_OUTPUT_TYPES[$templateCode] ?? null;
        $supplierId = (int) ($supplier['id'] ?? 0);
        if ($outputType === null || $supplierId <= 0 || !$this->platformEnabled()) {
            return $message;
        }

        $outputSetting = $this->profiles->outputSetting($supplierId, $outputType, self::USAGE);
        if (!($outputSetting['enabled'] ?? false)) {
            return $message;
        }

        $profile = $this->selectProfile($supplierId, $outputSetting, $userId);
        if ($profile === null) {
            return $this->handleUnconfigured($message, $outputType, $outputSetting, $supplierId, $userId);
        }

        $policy = new PdfSignaturePolicy($this->failurePolicy($outputSetting));
        try {
            $signed = $this->signWithProfile($message, $profile);
            $this->activity->log('signing.email_signed', $userId, 'supplier', $supplierId, [
                'output_type' => $outputType,
                'usage' => self::USAGE,
                'status' => 'signed',
                'backend' => 'smime',
                'profile_code' => $profile['profile']['code'] ?? null,
                'certificate_subject' => $profile['credential']['certificate_subject'] ?? null,
                'certificate_email' => $profile['credential']['certificate_email'] ?? null,
                'certificate_fingerprint' => $profile['credential']['certificate_fingerprint'] ?? null,
            ], null, null, $supplierId);

            return $signed;
        } catch (\Throwable $e) {
            $this->activity->log('signing.email_failed', $userId, 'supplier', $supplierId, [
                'output_type' => $outputType,
                'usage' => self::USAGE,
                'status' => $policy->failClosed() ? 'failed' : 'fallback_unsigned',
                'backend' => 'smime',
                'profile_code' => $profile['profile']['code'] ?? null,
                'error' => $this->sanitizeError($e->getMessage()),
                'failure_policy' => $policy->failurePolicy,
            ], null, null, $supplierId);

            if ($policy->failClosed()) {
                throw new \RuntimeException('S/MIME podpis e-mailu selhal.', 0, $e);
            }

            return $message;
        }
    }

    private function platformEnabled(): bool
    {
        return (bool) $this->config->get('email_signing.enabled', true);
    }

    /**
     * @param array<string,mixed> $outputSetting
     * @return array{profile:array<string,mixed>,credential:array<string,mixed>,password_enc:string}|null
     */
    private function selectProfile(int $supplierId, array $outputSetting, ?int $userId): ?array
    {
        $source = (string) ($outputSetting['selection_source'] ?? 'admin_profile_settings');

        if ($source === 'admin_profile_settings') {
            return $this->profileById($supplierId, (int) ($outputSetting['default_profile_id'] ?? 0));
        }

        if ($source === 'logged_in_user') {
            $profile = $this->profileForUser($supplierId, $userId, (string) ($outputSetting['output_type'] ?? ''));
            return $profile ?? $this->fallbackProfile($supplierId, $outputSetting);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $outputSetting
     * @return array{profile:array<string,mixed>,credential:array<string,mixed>,password_enc:string}|null
     */
    private function fallbackProfile(int $supplierId, array $outputSetting): ?array
    {
        if (($outputSetting['user_profile_fallback'] ?? null) === 'admin_profile_settings') {
            return $this->profileById($supplierId, (int) ($outputSetting['default_profile_id'] ?? 0));
        }

        return null;
    }

    /**
     * @return array{profile:array<string,mixed>,credential:array<string,mixed>,password_enc:string}|null
     */
    private function profileById(int $supplierId, int $profileId): ?array
    {
        if ($profileId <= 0) {
            return null;
        }

        $profile = $this->profiles->findProfile($supplierId, $profileId);
        if (!is_array($profile) || ($profile['owner_user_id'] ?? null) !== null) {
            return null;
        }

        return $this->storedProfile($supplierId, $profile);
    }

    /**
     * @return array{profile:array<string,mixed>,credential:array<string,mixed>,password_enc:string}|null
     */
    private function profileForUser(int $supplierId, ?int $userId, string $outputType): ?array
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        if ($outputType !== '') {
            $default = $this->profiles->userProfileDefault($supplierId, self::USAGE, $outputType, $userId);
            if ($default !== null) {
                $profile = $this->profiles->findProfile($supplierId, (int) ($default['profile_id'] ?? 0));
                if (is_array($profile)) {
                    $stored = $this->storedProfile($supplierId, $profile);
                    if ($stored !== null) {
                        return $stored;
                    }
                }
            }
        }

        foreach ($this->profiles->listProfilesForOwner($supplierId, $userId) as $profile) {
            $stored = $this->storedProfile($supplierId, $profile);
            if ($stored !== null) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{profile:array<string,mixed>,credential:array<string,mixed>,password_enc:string}|null
     */
    private function storedProfile(int $supplierId, array $profile): ?array
    {
        $profileId = (int) ($profile['id'] ?? 0);
        if ($profileId <= 0 || !($profile['is_active'] ?? false) || !in_array(self::USAGE, $profile['allowed_usages'] ?? [], true)) {
            return null;
        }

        $credential = $this->profiles->credential($supplierId, $profileId);
        if ($credential === null || !($credential['is_active'] ?? false)) {
            return null;
        }

        $passwordEnc = $this->passphrases->encryptedPassphraseForCredential($credential);
        if ($passwordEnc === null) {
            return null;
        }

        return [
            'profile' => $profile,
            'credential' => $credential,
            'password_enc' => $passwordEnc,
        ];
    }

    /**
     * @param array{profile:array<string,mixed>,credential:array<string,mixed>,password_enc:string} $profile
     */
    private function signWithProfile(Message $message, array $profile): Message
    {
        $credential = $profile['credential'];
        $validTo = (string) ($credential['certificate_valid_to'] ?? '');
        if ($validTo !== '' && strtotime($validTo) !== false && strtotime($validTo) < time()) {
            throw new \RuntimeException('S/MIME certifikát je expirovaný.');
        }

        $p12Path = $this->credentialAbsPath((string) ($credential['certificate_path'] ?? ''));
        if ($p12Path === '' || !is_file($p12Path) || !is_readable($p12Path)) {
            throw new \RuntimeException('S/MIME certifikát nelze načíst.');
        }

        $p12 = @file_get_contents($p12Path);
        if ($p12 === false || $p12 === '') {
            throw new \RuntimeException('S/MIME certifikát nelze načíst.');
        }

        $passphrase = $this->secrets->decrypt($profile['password_enc']);
        $certs = [];
        if (!openssl_pkcs12_read($p12, $certs, $passphrase)) {
            throw new \RuntimeException('S/MIME certifikát nelze otevřít zadaným heslem.');
        }

        $certPem = (string) ($certs['cert'] ?? '');
        $keyPem = (string) ($certs['pkey'] ?? '');
        if ($certPem === '' || $keyPem === '') {
            throw new \RuntimeException('S/MIME certifikát neobsahuje certifikát a privátní klíč.');
        }

        $tmpDir = $this->createTempDir();
        $certPath = $tmpDir . '/cert.pem';
        $keyPath = $tmpDir . '/key.pem';
        $extraPath = null;

        try {
            $this->writeSecretFile($certPath, $certPem);
            $this->writeSecretFile($keyPath, $keyPem);
            $extraCerts = $certs['extracerts'] ?? null;
            if (is_array($extraCerts) && $extraCerts !== []) {
                $extraPath = $tmpDir . '/extra.pem';
                $this->writeSecretFile($extraPath, implode("\n", array_map('strval', $extraCerts)));
            }

            $signer = new SMimeSigner($certPath, $keyPath, null, $extraPath);
            return $signer->sign($message);
        } finally {
            $this->removeTempDir($tmpDir);
        }
    }

    /**
     * @param array<string,mixed> $outputSetting
     */
    private function handleUnconfigured(
        Message $message,
        string $outputType,
        array $outputSetting,
        int $supplierId,
        ?int $userId,
    ): Message {
        $policy = new PdfSignaturePolicy($this->unconfiguredFailurePolicy($outputSetting));
        if ($policy->failClosed()) {
            $this->activity->log('signing.email_failed', $userId, 'supplier', $supplierId, [
                'output_type' => $outputType,
                'usage' => self::USAGE,
                'status' => 'failed',
                'backend' => 'smime',
                'profile_code' => null,
                'error' => 'S/MIME podpisový profil není nakonfigurovaný.',
                'failure_policy' => $policy->failurePolicy,
            ], null, null, $supplierId);

            throw new \RuntimeException('S/MIME podpis e-mailu není nakonfigurovaný.');
        }

        $this->activity->log('signing.email_skipped', $userId, 'supplier', $supplierId, [
            'output_type' => $outputType,
            'usage' => self::USAGE,
            'status' => 'skipped',
            'reason' => 'missing_profile',
            'backend' => 'smime',
            'profile_code' => null,
            'failure_policy' => $policy->failurePolicy,
        ], null, null, $supplierId);

        return $message;
    }

    /**
     * @param array<string,mixed> $outputSetting
     */
    private function failurePolicy(array $outputSetting): string
    {
        $policy = (string) ($outputSetting['failure_policy'] ?? PdfSignaturePolicy::FALLBACK_UNSIGNED);
        return in_array($policy, [
            PdfSignaturePolicy::FALLBACK_UNSIGNED,
            PdfSignaturePolicy::FAIL_CLOSED,
            PdfSignaturePolicy::SKIP_WHEN_UNCONFIGURED,
        ], true) ? $policy : PdfSignaturePolicy::FALLBACK_UNSIGNED;
    }

    /**
     * @param array<string,mixed> $outputSetting
     */
    private function unconfiguredFailurePolicy(array $outputSetting): string
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

    private function createTempDir(): string
    {
        $base = RuntimePaths::storage('tmp/email-signing');
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('Nelze vytvořit dočasný adresář pro S/MIME podpis.');
        }

        $dir = $base . '/' . bin2hex(random_bytes(12));
        if (!@mkdir($dir, 0700)) {
            throw new \RuntimeException('Nelze vytvořit dočasný adresář pro S/MIME podpis.');
        }

        return $dir;
    }

    private function writeSecretFile(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Nelze zapsat dočasný soubor pro S/MIME podpis.');
        }
        @chmod($path, 0600);
    }

    private function removeTempDir(string $dir): void
    {
        foreach (['cert.pem', 'key.pem', 'extra.pem'] as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function sanitizeError(string $error): string
    {
        $error = preg_replace('#(/[^\s:]+)+#', '[path]', $error) ?? $error;
        return mb_substr($error, 0, 500);
    }
}
