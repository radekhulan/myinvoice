<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\CzkRecap;
use PHPUnit\Framework\TestCase;

final class CzkRecapTest extends TestCase
{
    public function testSingleVatGroupBasicConversion(): void
    {
        // 1 000 EUR base + 210 EUR VAT (21 %) × 24,360 = 24 360 + 5 115,60 = 29 475,60 CZK
        $r = CzkRecap::build(
            [['rate' => 21.0, 'base' => 1000.00, 'vat' => 210.00]],
            24.360,
            '2026-05-03',
        );
        self::assertSame(24.360, $r['rate']);
        self::assertSame('2026-05-03', $r['rate_date']);
        self::assertFalse($r['fallback_used']);
        self::assertCount(1, $r['breakdown']);
        self::assertSame(21.0,    $r['breakdown'][0]['rate']);
        self::assertSame(24360.0, $r['breakdown'][0]['base_czk']);
        self::assertSame(5115.60, $r['breakdown'][0]['vat_czk']);
        self::assertSame(29475.60, $r['breakdown'][0]['with_vat_czk']);
        self::assertSame(24360.0, $r['total_without_vat_czk']);
        self::assertSame(5115.60, $r['total_vat_czk']);
        self::assertSame(29475.60, $r['total_with_vat_czk']);
    }

    public function testMultipleVatGroupsRoundedPerGroup(): void
    {
        // Per-group HALF_UP rounding (vs. summing first then rounding)
        // 100 EUR @ 21 % × 24,365 = 2436,50 base + 511,665 VAT → 511,67
        // 50 EUR @ 12 %  × 24,365 = 1218,25 base + 146,19  VAT → 146,19
        $r = CzkRecap::build(
            [
                ['rate' => 21.0, 'base' => 100.00, 'vat' => 21.00],
                ['rate' => 12.0, 'base' => 50.00,  'vat' => 6.00],
            ],
            24.365,
            '2026-05-03',
        );
        self::assertCount(2, $r['breakdown']);
        self::assertSame(2436.50, $r['breakdown'][0]['base_czk']);
        self::assertSame(511.67,  $r['breakdown'][0]['vat_czk']);
        self::assertSame(1218.25, $r['breakdown'][1]['base_czk']);
        self::assertSame(146.19,  $r['breakdown'][1]['vat_czk']);
        self::assertSame(3654.75, $r['total_without_vat_czk']);  // 2436,50 + 1218,25
        self::assertSame(657.86,  $r['total_vat_czk']);          // 511,67 + 146,19
        self::assertSame(4312.61, $r['total_with_vat_czk']);
    }

    public function testFallbackUsedFlagPropagates(): void
    {
        $r = CzkRecap::build(
            [['rate' => 21.0, 'base' => 100.00, 'vat' => 21.00]],
            25.0,
            '2026-05-01',
            true,
        );
        self::assertTrue($r['fallback_used']);
        self::assertSame('2026-05-01', $r['rate_date']);
    }

    public function testHalfUpRounding(): void
    {
        // 1 EUR × 24,005 = 24,005 → HALF_UP → 24,01
        $r = CzkRecap::build(
            [['rate' => 0.0, 'base' => 1.00, 'vat' => 0.00]],
            24.005,
            '2026-05-03',
        );
        self::assertSame(24.01, $r['breakdown'][0]['base_czk']);
    }

    public function testRateDecimalsShowAllSignificantPlaces(): void
    {
        self::assertSame(3, CzkRecap::rateDecimals(25.0));
        self::assertSame(3, CzkRecap::rateDecimals(24.365));
        self::assertSame(4, CzkRecap::rateDecimals(24.9999));
        self::assertSame(5, CzkRecap::rateDecimals(0.06123));
        self::assertSame(6, CzkRecap::rateDecimals(0.0612345678));
        self::assertSame(4, CzkRecap::build([], 24.9999, '2026-05-03')['rate_decimals']);
    }

    public function testEmptyBreakdownReturnsZeros(): void
    {
        $r = CzkRecap::build([], 24.0, '2026-05-03');
        self::assertSame([], $r['breakdown']);
        self::assertSame(0.0, $r['total_without_vat_czk']);
        self::assertSame(0.0, $r['total_vat_czk']);
        self::assertSame(0.0, $r['total_with_vat_czk']);
    }
}
