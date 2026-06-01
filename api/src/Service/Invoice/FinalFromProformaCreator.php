<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;

/**
 * Vytvoří DRAFT finální faktury (typu `invoice`) k zaplacené proformě.
 *
 * Caller je zodpovědný za:
 *   - ověření vlastnictví (SupplierGuard)
 *   - ověření stavu (proforma musí být `paid` v okamžiku volání nebo v rámci
 *     stejné transakce před voláním)
 *
 * Idempotence: pokud už existuje child faktura (`parent_invoice_id = proformaId`,
 * `invoice_type = 'invoice'`), vrátí její id a nevytvoří duplikát.
 *
 * Bezpečné vůči vnořeným transakcím — pokud caller už má otevřenou transakci,
 * neotevírá vlastní a neflushuje.
 */
final class FinalFromProformaCreator
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly InvoiceCalculator $calc,
    ) {}

    /**
     * @param int         $proformaId  ID proformy (musí mít invoice_type='proforma')
     * @param int         $userId      created_by; 0 = systémová akce (auto-match)
     * @param string|null $taxDate     YYYY-MM-DD; default = dnes
     * @param string|null $dueDate     YYYY-MM-DD; default = dnes
     * @param float|null  $advance     Výše odečtu zálohy; default = total_with_vat proformy
     * @return int  ID nového draftu (nebo již existující final faktury)
     */
    public function create(
        int $proformaId,
        int $userId = 0,
        ?string $taxDate = null,
        ?string $dueDate = null,
        ?float $advance = null,
    ): int {
        $proforma = $this->repo->find($proformaId);
        if ($proforma === null) {
            throw new \RuntimeException("Proforma {$proformaId} nenalezena.");
        }
        if (($proforma['invoice_type'] ?? '') !== 'proforma') {
            throw new \RuntimeException("Faktura {$proformaId} není zálohová.");
        }

        $pdo = $this->db->pdo();

        // Idempotence — pokud už existuje child final, vrátit její id
        $existing = $pdo->prepare(
            "SELECT id FROM invoices
              WHERE parent_invoice_id = ? AND invoice_type = 'invoice'
              ORDER BY id LIMIT 1"
        );
        $existing->execute([$proformaId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $taxDate = $taxDate ?? date('Y-m-d');
        $dueDate = $dueDate ?? date('Y-m-d');
        $advance = $advance ?? (float) $proforma['total_with_vat'];
        if ($advance < 0) {
            throw new \RuntimeException('Záloha nesmí být záporná.');
        }

        $noteAbove = ($proforma['language'] ?? 'cs') === 'en'
            ? "Tax document for advance invoice {$proforma['varsymbol']}"
            : "Daňový doklad k zálohové faktuře {$proforma['varsymbol']}";

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, note_below_items, advance_paid_amount, discount_percent, payment_method,
                    revenue_category_id, status, created_by)
                 VALUES ("invoice", ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $proformaId,
                $proforma['client_id'],
                $proforma['project_id'],
                (int) $proforma['supplier_id'],
                $taxDate,
                $dueDate,
                (int) $proforma['currency_id'],
                $proforma['reverse_charge'] ? 1 : 0,
                // Režim „ceny s DPH" musí dědit z proformy — jinak by se zkopírované brutto
                // jednotkové ceny přepočítaly jako netto a daňový doklad by měl nafouknuté totály.
                !empty($proforma['prices_include_vat']) ? 1 : 0,
                $proforma['language'],
                $noteAbove,
                // Poznámku „pod položkami" zdědíme z proformy (text nad položkami nahrazuje
                // marker daňového dokladu, ale spodní poznámka uživatele se má zachovat).
                $proforma['note_below_items'] ?? null,
                $advance,
                (float) ($proforma['discount_percent'] ?? 0),
                (string) ($proforma['payment_method'] ?? 'bank_transfer'),
                // Kategorii tržby zdědíme z proformy (daňový doklad patří do stejné kategorie).
                $proforma['revenue_category_id'] ?? null,
                $userId ?: null,
            ]);
            $finalId = (int) $pdo->lastInsertId();

            // Položky kopírujeme včetně případné slevové (item_kind='discount') —
            // zachová částku po slevě. Marker item_kind umožní pozdější re-save přepočítat.
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index, item_kind)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)'
            );
            foreach ($proforma['items'] as $item) {
                $itemStmt->execute([
                    $finalId,
                    $item['description'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                    (string) ($item['item_kind'] ?? 'standard'),
                ]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->calc->recompute($finalId);
        return $finalId;
    }
}
