<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Service\Invoice\InvoiceMath;

final class InvoiceAmountPolicy
{
    public const NON_POSITIVE_DRAFT_MESSAGE = 'Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.';
    public const NON_POSITIVE_MARK_PAID_MESSAGE = 'Fakturu s částkou k úhradě 0 nebo méně nelze označit jako zaplacenou.';
    public const NON_POSITIVE_REMINDER_MESSAGE = 'Upomínat lze jen faktury s kladnou částkou k úhradě.';

    public const ITEM_QUANTITY_ZERO_MESSAGE = 'Množství nesmí být 0.';
    public const ITEM_BOTH_NEGATIVE_MESSAGE = 'Záporné množství i záporná cena zároveň nejsou povolené.';

    /** Pod tuto hodnotu považuj qty za 0 (InvoiceMath::round2 stejně zaokrouhlí na 2 desetinná místa). */
    private const QTY_EPSILON = 1e-9;

    public static function requiresPositiveDraftAmountToPay(string $invoiceType, mixed $parentInvoiceId = null): bool
    {
        if (!in_array($invoiceType, ['invoice', 'proforma'], true)) {
            return false;
        }

        // Finální daňový doklad k zaplacené proformě je vedený jako `invoice`
        // s parent_invoice_id a typicky amount_to_pay = 0 po odečtu zálohy.
        if ($invoiceType === 'invoice' && (int) $parentInvoiceId > 0) {
            return false;
        }

        return true;
    }

    public static function requiresPositiveAmountToPay(string $invoiceType): bool
    {
        return in_array($invoiceType, ['invoice', 'proforma'], true);
    }

    /**
     * @param array<int, float> $vatRates
     */
    public static function validatePositiveAmountToPay(array $data, array $vatRates): ?string
    {
        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if (!self::requiresPositiveDraftAmountToPay($type, $data['parent_invoice_id'] ?? null)) {
            return null;
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return null;
        }

        // Malformed items přispějí 0 — per-item errors se reportují jinde (validateItem).
        // Nevracíme null, aby kontrola pozitivity nebyla závislá na pořadí validátorů
        // a uživatel viděl všechny chyby naráz.
        $mathItems = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset($item['quantity'], $item['unit_price_without_vat'], $item['vat_rate_id'])
                || !is_numeric($item['quantity'])
                || !is_numeric($item['unit_price_without_vat'])
                || !is_numeric($item['vat_rate_id'])
            ) {
                continue;
            }

            $vatRateId = (int) $item['vat_rate_id'];
            $mathItems[] = [
                'quantity' => (float) $item['quantity'],
                'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                'vat_rate_snapshot' => $vatRates[$vatRateId] ?? 0.0,
            ];
        }

        if ($mathItems === []) {
            return null;
        }

        $computed = InvoiceMath::compute($mathItems, !empty($data['reverse_charge']));
        // Sleva na úrovni dokladu (0–100 %) sníží celkovou částku — kontrola pozitivity
        // musí počítat s částkou PO slevě (sleva se materializuje až v repo při uložení).
        $discount = max(0.0, min(100.0, (float) ($data['discount_percent'] ?? 0)));
        $withVat = round((float) $computed['totals']['with_vat'] * (1 - $discount / 100.0), 2);
        $advance = round((float) ($data['advance_paid_amount'] ?? 0), 2);
        $amountToPay = round($withVat - $advance, 2);

        return $amountToPay > 0 ? null : self::NON_POSITIVE_DRAFT_MESSAGE;
    }

    /**
     * Strict — vrátí true jen pro doklady s amount_to_pay > 0.
     * Vhodné pro reminder gating (na finální doklad k záloze upomínat nedává smysl).
     */
    public static function hasPositiveAmountToPay(array $invoice): bool
    {
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');
        if (!self::requiresPositiveAmountToPay($type)) {
            return true;
        }

        return (float) ($invoice['amount_to_pay'] ?? 0) > 0;
    }

    /**
     * Vhodné pro mark-paid / bank-match flow:
     * finální daňový doklad k zaplacené proformě má amount_to_pay = 0 by design,
     * ale označit jako zaplacený (manuálně nebo přes bank match) je legitimní bookkeeping.
     */
    public static function canBeMarkedPaid(array $invoice): bool
    {
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');
        if ($type === 'invoice' && (int) ($invoice['parent_invoice_id'] ?? 0) > 0) {
            return true;
        }
        return self::hasPositiveAmountToPay($invoice);
    }

    /**
     * Má se doklad při vystavení (draft → issued) rovnou označit jako zaplacený?
     * Platí pro finální daňový doklad k zaplacené proformě plně pokrytý zálohou
     * (amount_to_pay <= 0): inkaso (kasová metoda — cash-flow, limit paušální daně)
     * se totiž promítá přes daňový doklad, ne přes proformu, a doklad by jinak zbytečně
     * visel jako nezaplacený/po splatnosti. Dobropisy (type=credit_note, rovněž nekladný
     * amount_to_pay) sem NEpatří — automaticky „zaplacené" být nesmí (vrácení peněz).
     */
    public static function shouldAutoMarkPaidOnIssue(array $invoice): bool
    {
        return (string) ($invoice['invoice_type'] ?? '') === 'invoice'
            && (int) ($invoice['parent_invoice_id'] ?? 0) > 0
            && (float) ($invoice['amount_to_pay'] ?? 0) <= 0.0;
    }

    /**
     * Sdílená per-item validace (volaná z InvoiceValidation i RecurringTemplateAction).
     * Caller odpovídá za is_array($item) check.
     *
     * @return array<string, string[]> err keyed by "items.{$index}.{field}"
     */
    public static function validateItem(array $item, int $index): array
    {
        $err = [];

        if (empty($item['description']) || trim((string) $item['description']) === '') {
            $err["items.{$index}.description"][] = 'Popis je povinný';
        }

        $qty = (float) ($item['quantity'] ?? 0);
        if (abs($qty) < self::QTY_EPSILON) {
            $err["items.{$index}.quantity"][] = self::ITEM_QUANTITY_ZERO_MESSAGE;
        }

        if (!isset($item['vat_rate_id']) || !is_numeric($item['vat_rate_id'])) {
            $err["items.{$index}.vat_rate_id"][] = 'DPH sazba je povinná';
        }

        if (!isset($item['unit_price_without_vat']) || !is_numeric($item['unit_price_without_vat'])) {
            $err["items.{$index}.unit_price_without_vat"][] = 'Jednotková cena je povinná';
        } else {
            $price = (float) $item['unit_price_without_vat'];
            if ($qty < 0 && $price < 0) {
                $err["items.{$index}.quantity"][] = self::ITEM_BOTH_NEGATIVE_MESSAGE;
                $err["items.{$index}.unit_price_without_vat"][] = self::ITEM_BOTH_NEGATIVE_MESSAGE;
            }
        }

        return $err;
    }
}
