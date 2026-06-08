<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

final class ExportPeriodResolver
{
    /**
     * @param array<string,mixed> $query
     */
    public function resolve(array $query): ExportPeriod
    {
        $period = (string) ($query['period'] ?? '');
        if ($period === 'quarterly') {
            return $this->resolveQuarterly($query);
        }
        if ($period === 'monthly') {
            return $this->resolveMonthly($query);
        }
        if ($period !== '') {
            throw new \InvalidArgumentException('Parametr period musí být monthly nebo quarterly.');
        }

        $legacyMonth = (string) ($query['month'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $legacyMonth, $m)) {
            throw new \InvalidArgumentException('Parametr month musí být YYYY-MM.');
        }

        return $this->monthPeriod((int) $m[1], (int) $m[2]);
    }

    /**
     * @param array<string,mixed> $query
     */
    private function resolveMonthly(array $query): ExportPeriod
    {
        $year = (int) ($query['year'] ?? 0);
        $monthParam = (string) ($query['month'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
        } else {
            $month = (int) $monthParam;
        }

        return $this->monthPeriod($year, $month);
    }

    /**
     * @param array<string,mixed> $query
     */
    private function resolveQuarterly(array $query): ExportPeriod
    {
        $year = (int) ($query['year'] ?? 0);
        $quarter = (int) ($query['quarter'] ?? 0);
        $this->assertYear($year);
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException('Parametr quarter musí být 1 až 4.');
        }

        $startMonth = (($quarter - 1) * 3) + 1;
        $from = $this->date($year, $startMonth);
        $to = $from->modify('+3 months');

        return new ExportPeriod(
            type: 'quarterly',
            year: $year,
            month: null,
            quarter: $quarter,
            dateFrom: $from->format('Y-m-d'),
            dateToExclusive: $to->format('Y-m-d'),
            label: sprintf('%04d-Q%d', $year, $quarter),
        );
    }

    private function monthPeriod(int $year, int $month): ExportPeriod
    {
        $this->assertYear($year);
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Neplatný rok/měsíc.');
        }

        $from = $this->date($year, $month);
        $to = $from->modify('+1 month');

        return new ExportPeriod(
            type: 'monthly',
            year: $year,
            month: $month,
            quarter: null,
            dateFrom: $from->format('Y-m-d'),
            dateToExclusive: $to->format('Y-m-d'),
            label: sprintf('%04d-%02d', $year, $month),
        );
    }

    private function assertYear(int $year): void
    {
        if ($year < 1900 || $year > 2100) {
            throw new \InvalidArgumentException('Neplatný rok/měsíc.');
        }
    }

    private function date(int $year, int $month): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    }
}
