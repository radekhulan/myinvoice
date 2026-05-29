<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

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
    ) {}

    /**
     * Vytvoří konfiguraci z řádku tabulky supplier (SELECT s.*).
     *
     * Vrátí null, když podpis NENÍ zapnutý (`pdf_signing_enabled` != 1) nebo
     * chybí cesta k certifikátu — volající (renderer) podpis přeskočí.
     *
     * @param array<string,mixed> $row
     */
    public static function fromSupplierRow(array $row): ?self
    {
        if ((int) ($row['pdf_signing_enabled'] ?? 0) !== 1) {
            return null;
        }
        $certPath = (string) ($row['signing_cert_path'] ?? '');
        if ($certPath === '') {
            return null;
        }
        $tsa = $row['signing_tsa_url'] ?? null;
        return new self(
            certPath:    $certPath,
            passwordEnc: (string) ($row['signing_cert_password_enc'] ?? ''),
            tsaUrl:      ($tsa !== null && $tsa !== '') ? (string) $tsa : null,
            reason:      (string) ($row['signing_reason'] ?? '') ?: 'Faktura',
        );
    }
}
