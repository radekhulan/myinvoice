<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Konfigurace PDF podpisu pro jeden podpisový profil (read-only value object).
 *
 * Heslo k certifikátu zůstává ZAŠIFROVANÉ (enc:v1:...) — dešifruje ho až
 * {@see PdfSigner} těsně před použitím, nikdy se nedrží v plaintextu déle než nutno.
 */
final class SigningConfig
{
    public function __construct(
        public readonly string $certPath,
        public readonly string $passwordEnc,
        public readonly ?string $tsaUrl,
        public readonly string $reason,
        public readonly ?string $tsaUsername = null,
        public readonly string $tsaPasswordEnc = '',
    ) {}

    public static function defaultReason(string $documentType): string
    {
        return match ($documentType) {
            'work_report' => 'Výkaz práce',
            default => 'Faktura',
        };
    }

    /**
     * Resolvne uloženou cestu na absolutní přes {@see RuntimePaths} (respektuje
     * MYINVOICE_DATA_DIR). Prázdný vstup → ''. Snese i starší absolutní hodnotu
     * (passthrough), aby migrace legacy certů neztratila uložený soubor.
     */
    public static function absCertPath(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        // Už absolutní (POSIX /… nebo Windows C:\…) → ponech beze změny.
        if (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $stored) === 1) {
            return $stored;
        }
        return RuntimePaths::storage(ltrim($stored, '/\\'));
    }
}
