<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\ActivityLogger;

/**
 * Sdílený podpisový hook pro PDF renderery (faktura, výkaz víceprací).
 *
 * Voláno po `$mpdf->Output($tmpPath)` a PŘED atomickým rename na cílovou cestu.
 * Měkký fallback: jakákoli chyba podpisu (chybějící/expirovaný cert, špatné heslo,
 * nedostupná TSA) se zaloguje do `activity_log` a vrátí se PŮVODNÍ nepodepsané PDF —
 * generování faktury se nikdy nezablokuje.
 */
trait SignsPdf
{
    /**
     * Podepíše PDF, má-li dodavatel zapnutý podpis. Vrátí cestu k výslednému PDF
     * (podepsanému, nebo původnímu při vypnutém podpisu / fallbacku).
     *
     * @param array<string,mixed> $supplierRow řádek tabulky supplier (SELECT s.*)
     */
    private function signPdfIfEnabled(
        string $tmpPath,
        array $supplierRow,
        PdfSigner $signer,
        ActivityLogger $activity,
        string $docType,
        int $docId,
    ): string {
        $cfg = SigningConfig::fromSupplierRow($supplierRow);
        if ($cfg === null) {
            return $tmpPath; // podpis vypnutý nebo bez certu
        }
        $supplierId = (int) ($supplierRow['id'] ?? 0) ?: null;
        try {
            $signed = $signer->signFile($tmpPath, $cfg);
            @unlink($tmpPath);
            $activity->log('signing.pdf_signed', null, $docType, $docId, [
                'level'   => $cfg->tsaUrl !== null ? 'PAdES-T' : 'PAdES-B',
                'tsa_url' => $cfg->tsaUrl,
                'status'  => 'signed',
            ], null, null, $supplierId);
            return $signed;
        } catch (\Throwable $e) {
            $activity->log('signing.failed', null, $docType, $docId, [
                'status' => 'fallback_unsigned',
                'error'  => $e->getMessage(),
            ], null, null, $supplierId);
            return $tmpPath; // měkký fallback — nepodepsané PDF
        }
    }
}
