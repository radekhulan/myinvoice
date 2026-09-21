<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přepočítá sumy faktury (totals + per-item) a vat breakdown.
 *
 * Pravidla:
 *  - Per item: total_without_vat = round(quantity * unit_price, 2)
 *              total_vat = round(total_without_vat * (vat_rate_snapshot / 100), 2)
 *              total_with_vat = total_without_vat + total_vat
 *  - Reverse charge: items se vat_rate_snapshot = 0 (i kdyby byl jiný)
 *  - Faktura: SUM jednotlivých položek
 *  - amount_to_pay je generated column v DB (total_with_vat + rounding - advance_paid_amount)
 */
final class InvoiceCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočítá faktury sumy a per-item totals. Volá se po každém edit/insert.
     *
     * @return array{totals:array, vat_breakdown:array}
     */
    public function recompute(int $invoiceId): array
    {
        $pdo = $this->db->pdo();

        // Načti hlavičku (pro reverse_charge + režim cen s DPH)
        $stmt = $pdo->prepare('SELECT reverse_charge, prices_include_vat FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            throw new \RuntimeException("Invoice {$invoiceId} not found");
        }
        $reverseCharge    = (bool) $header['reverse_charge'];
        $pricesIncludeVat = (bool) $header['prices_include_vat'];

        // Načti položky
        $stmt = $pdo->prepare(
            'SELECT id, quantity, unit_price_without_vat, vat_rate_snapshot
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Vlastní výpočty delegujeme na pure-function InvoiceMath (testovatelné bez DB).
        $computed = InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat);

        // Persist per-item totals
        $updateItem = $pdo->prepare(
            'UPDATE invoice_items SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
        );
        foreach ($items as $i => $item) {
            $r = $computed['items'][$i];
            $updateItem->execute([$r['base'], $r['vat'], $r['with'], (int) $item['id']]);
        }

        // Persist invoice totals (amount_to_pay je generated column). `rounding` se
        // nepřepisuje: nese zaokrouhlení dokladu převzaté z importu (#258), které se
        // do DPH nepočítá a přičítá se až k částce k úhradě.
        $stmt = $pdo->prepare(
            'UPDATE invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $invoiceId,
        ]);

        return [
            'totals'        => $computed['totals'],
            'vat_breakdown' => $computed['vat_breakdown'],
        ];
    }
}
