<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use PHPUnit\Framework\TestCase;

final class InvoiceAmountPolicyTest extends TestCase
{
    public function testInvoiceWithDiscountLineKeepsPositiveAmountToPay(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Služba', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
                ['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertNull($err);
    }

    public function testInvoiceRejectsZeroAmountToPay(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Služba', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
                ['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 0.0]);

        self::assertSame('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.', $err);
    }

    public function testProformaRejectsNegativeAmountToPayAfterAdvance(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'proforma',
            'advance_paid_amount' => 1500,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Záloha', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 0.0]);

        self::assertSame('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.', $err);
    }

    public function testCreditNoteCanStayNonPositive(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'credit_note',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Vrácení', 'quantity' => -1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertNull($err);
    }

    public function testFinalInvoiceFromProformaCanStayAtZeroAmountToPay(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 123,
            'advance_paid_amount' => 1210,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Daňový doklad k záloze', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertNull($err);
    }

    public function testHasPositiveAmountToPayRejectsNonPositiveInvoice(): void
    {
        self::assertFalse(InvoiceAmountPolicy::hasPositiveAmountToPay([
            'invoice_type' => 'invoice',
            'amount_to_pay' => 0,
        ]));
        self::assertFalse(InvoiceAmountPolicy::hasPositiveAmountToPay([
            'invoice_type' => 'proforma',
            'amount_to_pay' => -10,
        ]));
        self::assertTrue(InvoiceAmountPolicy::hasPositiveAmountToPay([
            'invoice_type' => 'credit_note',
            'amount_to_pay' => -10,
        ]));
    }

    public function testRequiresPositiveDraftAmountToPaySkipsFinalInvoiceFromProforma(): void
    {
        self::assertTrue(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('invoice'));
        self::assertTrue(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('proforma'));
        self::assertFalse(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('invoice', 123));
        self::assertFalse(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('credit_note'));
    }

    /**
     * Malformed item (chybějící vat_rate_id) přispěje 0 — kontrola pozitivity
     * se NEpřeskočí. Dříve to short-circuit-ovalo a uživatel viděl chybu částky
     * až v druhém round-tripu.
     */
    public function testMalformedItemDoesNotBypassPositivityCheck(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -1000, 'vat_rate_id' => 1],
                ['description' => 'Chybějící VAT', 'quantity' => 1, 'unit_price_without_vat' => 500 /* žádný vat_rate_id */],
            ],
        ], [1 => 0.0]);

        // Validní řádek (-1000) sám o sobě = nekladná částka → musí se reportnout.
        self::assertSame('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.', $err);
    }

    public function testAllItemsMalformedSkipsAmountCheck(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'X', 'quantity' => 'abc', 'unit_price_without_vat' => 'def', 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        // Nic spočítatelného → per-item errors se reportují jinde, amount check vrací null.
        self::assertNull($err);
    }

    public function testCanBeMarkedPaidAllowsFinalFromProforma(): void
    {
        // Finální daňový doklad k zaplacené proformě má amount_to_pay = 0,
        // ale označit zaplaceným ho lze (bookkeeping).
        self::assertTrue(InvoiceAmountPolicy::canBeMarkedPaid([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 42,
            'amount_to_pay' => 0,
        ]));
    }

    public function testCanBeMarkedPaidRejectsRegularNonPositiveInvoice(): void
    {
        self::assertFalse(InvoiceAmountPolicy::canBeMarkedPaid([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => null,
            'amount_to_pay' => 0,
        ]));
        self::assertFalse(InvoiceAmountPolicy::canBeMarkedPaid([
            'invoice_type' => 'proforma',
            'amount_to_pay' => -5,
        ]));
    }

    public function testShouldAutoMarkPaidOnIssueForFullyCoveredFinal(): void
    {
        // Finální daňový doklad k proformě plně pokrytý zálohou → auto-paid při issue.
        self::assertTrue(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 42,
            'amount_to_pay' => 0,
        ]));
        // Záporný zbytek (přeplatek zálohy) taky → nic se nedoplácí.
        self::assertTrue(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 42,
            'amount_to_pay' => -1.5,
        ]));
    }

    public function testShouldNotAutoMarkPaidWhenRemainderToPay(): void
    {
        // Záloha pokryla jen část → zbytek se doplácí, nesmí být auto-paid.
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 42,
            'amount_to_pay' => 100.0,
        ]));
    }

    public function testShouldNotAutoMarkPaidForRegularInvoiceOrCreditNote(): void
    {
        // Běžná faktura bez vazby na proformu — nikdy auto-paid (a stejně by 0 neprošla issue).
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => null,
            'amount_to_pay' => 0,
        ]));
        // Dobropis má nekladný amount_to_pay, ale automaticky „zaplacený" být nesmí.
        self::assertFalse(InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue([
            'invoice_type' => 'credit_note',
            'parent_invoice_id' => 7,
            'amount_to_pay' => -500.0,
        ]));
    }

    public function testValidateItemRejectsZeroQuantityWithEpsilon(): void
    {
        // 0.0001 přispěje fakticky 0 po round2 v InvoiceMath, takže by mělo být odmítnuto.
        $err = InvoiceAmountPolicy::validateItem(
            ['description' => 'X', 'quantity' => 0.0000000001, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1],
            0,
        );
        self::assertArrayHasKey('items.0.quantity', $err);

        // 0.001 už projde — InvoiceMath::round2 ho zachová.
        $err = InvoiceAmountPolicy::validateItem(
            ['description' => 'X', 'quantity' => 0.001, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1],
            0,
        );
        self::assertArrayNotHasKey('items.0.quantity', $err);
    }

    public function testValidateItemRejectsBothNegative(): void
    {
        $err = InvoiceAmountPolicy::validateItem(
            ['description' => 'X', 'quantity' => -1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1],
            3,
        );
        self::assertArrayHasKey('items.3.quantity', $err);
        self::assertArrayHasKey('items.3.unit_price_without_vat', $err);
    }
}
