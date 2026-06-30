<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\PurchaseInvoiceCnbApplier;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic test pro privátní helper applyRoundingFromPayable v mapperu.
 *
 * Zrcadlí AiPdfExtractorUnitTest::applyRoundingFromPdfTotal — obě cesty (ISDOC i
 * AI) musí uložit stejný haléřový rounding offset, aby „k úhradě"
 * (amount_to_pay + rounding) sedělo na doklad. Sémantika amount_to_pay se NEmění:
 * rounding se vede mimo a konzumenti (QR / příkaz / PDF / UI) si ho přičtou.
 *
 * Závislosti mapperu jsou mockované přes createMock — test neběží proti DB.
 */
#[AllowMockObjectsWithoutExpectations]
final class IsdocToPurchaseInvoiceMapperRoundingTest extends TestCase
{
    private PurchaseInvoiceRepository $repo;
    private IsdocToPurchaseInvoiceMapper $mapper;

    protected function setUp(): void
    {
        // Z 5 závislostí používá applyRoundingFromPayable jen $repo (find + setRounding).
        $this->repo = $this->createMock(PurchaseInvoiceRepository::class);

        $this->mapper = new IsdocToPurchaseInvoiceMapper(
            $this->createMock(Connection::class),
            $this->repo,
            $this->createMock(PurchaseInvoiceCalculator::class),
            $this->createMock(ClientResolver::class),
            $this->createMock(PurchaseInvoiceCnbApplier::class),
        );
    }

    public function testRounding_payable_above_items_total_yields_haler(): void
    {
        // Doklad: základ+DPH 999,99, PayableAmount 1000,00 → rounding +0,01.
        $this->repo->method('find')->willReturn(['total_with_vat' => 999.99]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, 0.01);

        $this->invoke(42, 1, ['payable_amount' => 1000.00], false);
    }

    public function testRounding_payable_below_items_total_yields_negative_haler(): void
    {
        // Doklad: základ+DPH 1000,03, PayableAmount 1000,00 → rounding −0,03.
        $this->repo->method('find')->willReturn(['total_with_vat' => 1000.03]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.03);

        $this->invoke(42, 1, ['payable_amount' => 1000.00], false);
    }

    public function testRounding_credit_note_applies_negative_sign(): void
    {
        // Dobropis: total_with_vat v DB záporný, PayableAmount kladný (abs na obou).
        // Offset 0,01, sign aplikujeme na záporno (zrcadlí AI cestu).
        $this->repo->method('find')->willReturn(['total_with_vat' => -999.99]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.01);

        $this->invoke(42, 1, ['payable_amount' => 1000.00], true);
    }

    public function testRounding_no_diff_skips(): void
    {
        // PayableAmount = součet položek → žádné zaokrouhlení k uložení.
        $this->repo->method('find')->willReturn(['total_with_vat' => 1000.00]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invoke(42, 1, ['payable_amount' => 1000.00], false);
    }

    public function testRounding_diff_over_1_kc_skips(): void
    {
        // Rozdíl > 1 Kč není zaokrouhlení, je to skutečná nesrovnalost dokladu → ignoruj.
        $this->repo->method('find')->willReturn(['total_with_vat' => 1000.00]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invoke(42, 1, ['payable_amount' => 1002.50], false);
    }

    public function testRounding_missing_payable_skips_without_db_hit(): void
    {
        // Bez PayableAmount se ani nesahá do DB.
        $this->repo->expects($this->never())->method('find');
        $this->repo->expects($this->never())->method('setRounding');

        $this->invoke(42, 1, ['payable_amount' => null], false);
        $this->invoke(42, 1, [], false);
    }

    public function testRounding_zero_payable_skips(): void
    {
        $this->repo->expects($this->never())->method('find');
        $this->repo->expects($this->never())->method('setRounding');

        $this->invoke(42, 1, ['payable_amount' => 0.0], false);
    }

    public function testRounding_zero_items_total_skips(): void
    {
        // Nezjištěný/nulový total_with_vat → nelze počítat offset.
        $this->repo->method('find')->willReturn(['total_with_vat' => 0.0]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invoke(42, 1, ['payable_amount' => 1000.00], false);
    }

    public function testRounding_invoice_not_found_skips(): void
    {
        $this->repo->method('find')->willReturn(null);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invoke(42, 1, ['payable_amount' => 1000.00], false);
    }

    private function invoke(int $id, int $supplierId, array $parsed, bool $isCredit): void
    {
        $ref = new \ReflectionMethod($this->mapper, 'applyRoundingFromPayable');
        $ref->invoke($this->mapper, $id, $supplierId, $parsed, $isCredit);
    }
}
