<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

/**
 * AES-256-GCM šifrování citlivých secretů (TOTP secrets, podobné).
 *
 * Klíč pochází z `cfg.app.secret_encryption_key` (32B base64). Pokud není nastaven,
 * použije se HKDF z `app.pepper` (back-compat — ale s explicitním klíčem je lepší).
 *
 * Format zašifrovaného textu: `enc:v1:{base64(nonce|ciphertext|tag)}`
 * Plain text bez prefixu se ponechá jako legacy (backward compat při migraci).
 */
final class SecretEncryption
{
    private const PREFIX = 'enc:v1:';
    private const NONCE_LEN = 12; // GCM standard

    public function __construct(private readonly Config $config) {}

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::PREFIX . base64_encode($nonce . $ct . $tag);
    }

    public function decrypt(string $stored): string
    {
        // Legacy plaintext — bez prefixu se vrátí jak je
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < self::NONCE_LEN + 16) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $nonce = substr($blob, 0, self::NONCE_LEN);
        $tag   = substr($blob, -16);
        $ct    = substr($blob, self::NONCE_LEN, -16);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($pt === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $pt;
    }

    /** @internal Pomáhá testům / migracím rozpoznat encrypted vs legacy plaintext */
    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * Validace konfigurace klíče pro admin health warning.
     * null = OK (dedikovaný key), string = warning/error v CZ.
     */
    public function validateKey(): ?string
    {
        $b64 = (string) $this->config->get('app.secret_encryption_key', '');
        if ($b64 !== '') {
            $key = base64_decode($b64, true);
            if ($key === false || strlen($key) !== 32) {
                return 'cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.';
            }
            return null;
        }

        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            return 'Ani secret_encryption_key, ani app.pepper nejsou nastaveny.';
        }

        return 'secret_encryption_key chybí, používá se HKDF fallback z app.pepper (méně bezpečné).';
    }

    private function key(): string
    {
        $b64 = (string) $this->config->get('app.secret_encryption_key', '');
        if ($b64 !== '') {
            $key = base64_decode($b64, true);
            if ($key === false || strlen($key) !== 32) {
                throw new \RuntimeException('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.');
            }
            return $key;
        }
        // Fallback: HKDF z pepperu — méně bezpečné než dedikovaný klíč, ale funkční
        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            throw new \RuntimeException('Ani secret_encryption_key, ani app.pepper nejsou nastaveny.');
        }
        return hash_hkdf('sha256', $pepper, 32, 'totp-secret');
    }
}
