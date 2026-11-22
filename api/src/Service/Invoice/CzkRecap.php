<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Přepočet rekapitulace DPH a celkových součtů faktury z cizí měny do CZK
 * při použití zafixovaného kurzu (invoices.exchange_rate).
 *
 * Zaokrouhlování: HALF_UP, 2 desetinná místa, **zvlášť per VAT skupina** —
 * tj. pro každou sazbu se zaokrouhlí přepočtený základ a DPH samostatně,
 * teprve potom se sčítají do `total_without_vat_czk` / `total_vat_czk`.
 * Tím se vyhneme drobným rozdílům 0,01 CZK způsobeným násobnými přepočty.
 *
 * Položky (line items) se NEPŘEPOČÍTÁVAJÍ — viz spec.
 */
final class CzkRecap
{
    /**
     * @param list<array{rate: float, base: float, vat: float}> $vatBreakdown
     * @return array{
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   breakdown: list<array{rate: float, base_czk: float, vat_czk: float, with_vat_czk: float}>,
     *   total_without_vat_czk: float,
     *   total_vat_czk: float,
     *   total_with_vat_czk: float
     * }
     */
    public static function build(
        array $vatBreakdown,
        float $exchangeRate,
        string $rateDate,
        bool $fallbackUsed = false,
    ): array {
        $rows = [];
        $totalBase = 0.0;
        $totalVat  = 0.0;

        foreach ($vatBreakdown as $b) {
            $baseCzk = self::multiplyHalfUp((float) $b['base'], $exchangeRate);
            $vatCzk  = self::multiplyHalfUp((float) $b['vat'],  $exchangeRate);
            $rows[] = [
                'rate'         => (float) $b['rate'],
                'base_czk'     => $baseCzk,
                'vat_czk'      => $vatCzk,
                'with_vat_czk' => self::roundHalfUp($baseCzk + $vatCzk),
            ];
            $totalBase += $baseCzk;
            $totalVat  += $vatCzk;
        }

        $totalBase = self::roundHalfUp($totalBase);
        $totalVat  = self::roundHalfUp($totalVat);

        return [
            'rate'                  => $exchangeRate,
            'rate_decimals'         => self::rateDecimals($exchangeRate),
            'rate_date'             => $rateDate,
            'fallback_used'         => $fallbackUsed,
            'breakdown'             => $rows,
            'total_without_vat_czk' => $totalBase,
            'total_vat_czk'         => $totalVat,
            'total_with_vat_czk'    => self::roundHalfUp($totalBase + $totalVat),
        ];
    }

    /**
     * Počet desetinných míst pro tisk kurzu: všechna platná místa (min. 3, max. 6),
     * aby PDF ukazovalo přesně ten kurz, kterým se přepočítává (24,9999; 0,06123).
     */
    public static function rateDecimals(float $rate): int
    {
        $s = rtrim(sprintf('%.6F', $rate), '0');
        $dot = strpos($s, '.');
        $decimals = $dot === false ? 0 : strlen($s) - $dot - 1;
        return max(3, min(6, $decimals));
    }

    /**
     * Násobení dvou floatů s HALF_UP zaokrouhlením na 2 dp. Používá bcmath kvůli
     * binární nepřesnosti — např. 21,00 × 24,365 = 511,665, ale ve floatu
     * 511,66499999..., takže `round(...HALF_UP)` vrací 511,66 místo 511,67.
     * S bcmath dostaneme přesný 511,67.
     */
    private static function multiplyHalfUp(float $a, float $b): float
    {
        if (function_exists('bcmul')) {
            $sa = sprintf('%.6F', $a);
            $sb = sprintf('%.6F', $b);
            $product = bcmul($sa, $sb, 10);
            $bump = str_starts_with($product, '-') ? '-0.005' : '0.005';
            return (float) bcadd($product, $bump, 2);
        }
        return round($a * $b, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * HALF_UP zaokrouhlení už zaokrouhleného mezisoučtu (např. base+vat) — float
     * imprecision tu nehrozí, protože sčítáme dvě hodnoty s 2 dp.
     */
    private static function roundHalfUp(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
}
