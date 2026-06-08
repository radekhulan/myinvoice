<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Export;

use MyInvoice\Service\Export\ExportPeriodResolver;
use PHPUnit\Framework\TestCase;

final class ExportPeriodResolverTest extends TestCase
{
    private ExportPeriodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ExportPeriodResolver();
    }

    public function testLegacyMonthParameterResolvesMonthlyPeriod(): void
    {
        $period = $this->resolver->resolve(['month' => '2026-06']);

        self::assertSame('monthly', $period->type);
        self::assertSame(2026, $period->year);
        self::assertSame(6, $period->month);
        self::assertNull($period->quarter);
        self::assertSame('2026-06-01', $period->dateFrom);
        self::assertSame('2026-07-01', $period->dateToExclusive);
        self::assertSame('2026-06', $period->label);
    }

    public function testExplicitMonthlyPeriodResolvesNumericMonth(): void
    {
        $period = $this->resolver->resolve([
            'period' => 'monthly',
            'year' => '2026',
            'month' => '4',
        ]);

        self::assertSame('monthly', $period->type);
        self::assertSame(2026, $period->year);
        self::assertSame(4, $period->month);
        self::assertSame('2026-04-01', $period->dateFrom);
        self::assertSame('2026-05-01', $period->dateToExclusive);
        self::assertSame('2026-04', $period->label);
    }

    public function testQuarterlyPeriodResolvesWholeQuarter(): void
    {
        $period = $this->resolver->resolve([
            'period' => 'quarterly',
            'year' => '2026',
            'quarter' => '2',
        ]);

        self::assertSame('quarterly', $period->type);
        self::assertSame(2026, $period->year);
        self::assertNull($period->month);
        self::assertSame(2, $period->quarter);
        self::assertSame('2026-04-01', $period->dateFrom);
        self::assertSame('2026-07-01', $period->dateToExclusive);
        self::assertSame('2026-Q2', $period->label);
    }

    public function testInvalidQuarterFailsValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parametr quarter musí být 1 až 4.');

        $this->resolver->resolve([
            'period' => 'quarterly',
            'year' => '2026',
            'quarter' => '5',
        ]);
    }
}
