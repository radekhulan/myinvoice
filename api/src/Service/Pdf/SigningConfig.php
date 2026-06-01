<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Konfigurace podpisu PDF pro jednoho dodavatele (read-only value object).
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

    /**
     * Vytvoří konfiguraci z řádku tabulky supplier (SELECT s.*).
     *
     * Vrátí null, když chybí cesta k certifikátu — volající (renderer) podpis
     * přeskočí nebo použije fallback podle mapování výstupu.
     *
     * @param array<string,mixed> $row
     */
    public static function fromSupplierRow(array $row, string $documentType = 'invoice'): ?self
    {
        // V DB je cesta uložená RELATIVNĚ ke storage (data-dir nezávislá); na absolutní
        // se resolvuje až tady přes RuntimePaths (respektuje MYINVOICE_DATA_DIR), takže
        // přesun data-dir / Docker volume podpis nerozbije.
        $certPath = self::absCertPath((string) ($row['signing_cert_path'] ?? ''));
        if ($certPath === '') {
            return null;
        }
        $tsa = $row['signing_tsa_url'] ?? null;
        $tsaUser = $row['signing_tsa_username'] ?? null;
        return new self(
            certPath:     $certPath,
            passwordEnc:  (string) ($row['signing_cert_password_enc'] ?? ''),
            tsaUrl:       ($tsa !== null && $tsa !== '') ? (string) $tsa : null,
            reason:       self::defaultReason($documentType),
            tsaUsername:  ($tsaUser !== null && $tsaUser !== '') ? (string) $tsaUser : null,
            tsaPasswordEnc: (string) ($row['signing_tsa_password_enc'] ?? ''),
        );
    }

    public static function defaultReason(string $documentType): string
    {
        return match ($documentType) {
            'work_report' => 'Výkaz práce',
            default => 'Faktura',
        };
    }

    /**
     * Relativní (data-dir nezávislá) cesta P12 ukládaná do `supplier.signing_cert_path`.
     * Absolutní cestu z ní složí {@see absCertPath} až za běhu.
     */
    public static function relCertPath(int $supplierId): string
    {
        return "certs/supplier-{$supplierId}.p12";
    }

    /**
     * Resolvne uloženou cestu na absolutní přes {@see RuntimePaths} (respektuje
     * MYINVOICE_DATA_DIR). Prázdný vstup → ''. Snese i starší absolutní hodnotu
     * (passthrough), aby přechod ze staré varianty neztratil cert.
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
