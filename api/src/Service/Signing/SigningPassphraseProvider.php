<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Auth\SecretEncryption;

/**
 * Resoluce hesla k podpisovému certifikátu bez ukládání plaintextu do DB.
 *
 * `prompt_on_use` v background/signing service záměrně vrací null: neexistuje
 * interaktivní vstup, takže se použije konfigurovaný fallback/fail-closed.
 */
final class SigningPassphraseProvider implements SigningPassphraseProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SecretEncryption $secrets,
    ) {}

    /**
     * @param array<string,mixed> $credential
     */
    public function encryptedPassphraseForCredential(array $credential): ?string
    {
        $policy = (string) ($credential['passphrase_policy'] ?? 'encrypted_store');
        if ($policy === 'encrypted_store') {
            $stored = trim((string) ($credential['encrypted_passphrase'] ?? ''));
            return $stored !== '' ? $stored : null;
        }

        if ($policy === 'passphrase_file') {
            $profileId = trim((string) ($credential['passphrase_profile_id'] ?? ''));
            if ($profileId === '') {
                return null;
            }
            $passphrase = $this->passphraseFromFile($profileId);
            return $passphrase !== null ? $this->secrets->encrypt($passphrase) : null;
        }

        return null;
    }

    private function passphraseFromFile(string $profileId): ?string
    {
        $path = $this->passphraseFilePath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $this->passphraseFromJson($json, $profileId);
        }

        $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
        if (is_array($ini)) {
            return $this->passphraseFromIni($ini, $profileId);
        }

        return null;
    }

    private function passphraseFilePath(): string
    {
        $path = trim((string) $this->config->get('signing.passphrase_file', ''));
        if ($path === '') {
            $path = trim((string) $this->config->get('pdf_signing.passphrase_file', ''));
        }
        if ($path === '') {
            return '';
        }
        if (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) === 1) {
            return $path;
        }

        return RuntimePaths::base() . '/' . ltrim($path, '/\\');
    }

    /**
     * @param array<mixed> $data
     */
    private function passphraseFromJson(array $data, string $profileId): ?string
    {
        $profiles = isset($data['profiles']) && is_array($data['profiles']) ? $data['profiles'] : $data;
        $entry = $profiles[$profileId] ?? null;
        if (is_string($entry) && $entry !== '') {
            return $entry;
        }
        if (is_array($entry) && isset($entry['passphrase']) && is_scalar($entry['passphrase'])) {
            $passphrase = (string) $entry['passphrase'];
            return $passphrase !== '' ? $passphrase : null;
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     */
    private function passphraseFromIni(array $data, string $profileId): ?string
    {
        $entry = $data[$profileId] ?? null;
        if (is_array($entry) && isset($entry['passphrase']) && is_scalar($entry['passphrase'])) {
            $passphrase = (string) $entry['passphrase'];
            return $passphrase !== '' ? $passphrase : null;
        }

        return null;
    }
}
