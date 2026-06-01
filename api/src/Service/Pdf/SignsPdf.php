<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Signing\Pdf\PdfSigningService;

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
        PdfSigningService $signing,
        string $docType,
        int $docId,
        ?int $userId = null,
    ): string {
        return $signing->signSupplierPdfIfEnabled($tmpPath, $supplierRow, $docType, $docId, $userId);
    }
}
