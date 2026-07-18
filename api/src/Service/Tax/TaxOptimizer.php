<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Daňový optimalizátor (CZ OSVČ) — srovnání režimů a predikce vůči limitům.
 *
 * Stateless, čisté funkce: vstup = profil (pole) + roční příjem + konstanty roku
 * (z {@see TaxConstants} nebo tabulky `tax_constants`). Žádná DB závislost, aby
 * šlo jednotkově testovat a spouštět z CLI.
 *
 * Profil (pole, vše volitelné kromě activity_rate):
 *   activity_rate    int   30|40|60|80  — sazba výdajového paušálu (typ činnosti)
 *   flat_tax_band    string none|band1|band2|band3
 *   is_vat_payer     bool
 *   is_secondary     bool  — vedlejší činnost (jiná minima pojistného)
 *   spouse_credit    bool  — splněny podmínky slevy na manželku (příjem <68k & dítě <3)
 *   children_count   int
 *   mortgage_interest float
 *   pension_contrib  float
 *   life_insurance   float
 *   donations        float
 */
final class TaxOptimizer
{
    private const BAND_ORDER = ['band1', 'band2', 'band3'];

    /**
     * Hlavní vstup pro retrospektivu: srovná dostupné režimy za uzavřený rok.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c konstanty roku
     * @return array<string,mixed>
     */
    public function compare(array $profile, float $income, array $c): array
    {
        $pausal  = $this->computePausal($profile, $income, $c);
        $regular = $this->computeRegular($profile, $income, $c);

        $candidates = [];
        if ($pausal['applicable']) {
            $candidates['pausal'] = $pausal['total'];
        }
        $candidates['regular'] = $regular['total'];

        asort($candidates);
        $winner = array_key_first($candidates);

        $delta = null;
        if (isset($candidates['pausal'])) {
            $delta = round($regular['total'] - $pausal['total'], 0); // +: paušál levnější
        }

        return [
            'year'    => $c['year'],
            'income'  => round($income, 0),
            'pausal'  => $pausal,
            'regular' => $regular,
            'winner'  => $winner,
            'delta_regular_minus_pausal' => $delta,
        ];
    }

    /**
     * Určí efektivní pásmo paušálu dle příjmu × typu činnosti a deklarovaného pásma.
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function effectiveBand(string $declared, int $activityRate, float $income, array $c): array
    {
        if ($declared === 'none' || !isset($c['pausal_annual'][$declared])) {
            return ['applicable' => false, 'reason' => 'not_in_pausal'];
        }
        if ($income > $c['vat_limit_low']) {
            return ['applicable' => false, 'reason' => 'over_2m', 'declared' => $declared];
        }

        $ceilings = $c['band_ceilings'][$activityRate] ?? $c['band_ceilings'][40];
        $idx = array_search($declared, self::BAND_ORDER, true);
        $surcharge = 0.0;

        // Posun nahoru, dokud příjem překračuje strop aktuálního pásma.
        while ($idx < count(self::BAND_ORDER) - 1 && $income > $ceilings[self::BAND_ORDER[$idx]]) {
            $idx++;
        }
        $effective = self::BAND_ORDER[$idx];

        if ($income > $ceilings[$effective]) {
            // I nejvyšší dostupné pásmo nestačí → mimo paušál (de facto >2M).
            return ['applicable' => false, 'reason' => 'over_2m', 'declared' => $declared];
        }
        if ($effective !== $declared) {
            $surcharge = $c['pausal_annual'][$effective] - $c['pausal_annual'][$declared];
        }

        return [
            'applicable' => true,
            'declared'   => $declared,
            'effective'  => $effective,
            'ceiling'    => $ceilings[$effective],
            'surcharge'  => round($surcharge, 0),
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function computePausal(array $profile, float $income, array $c): array
    {
        $declared = (string) ($profile['flat_tax_band'] ?? 'none');
        $rate = (int) ($profile['activity_rate'] ?? 40);

        if (!empty($profile['is_vat_payer'])) {
            return ['applicable' => false, 'reason' => 'vat_payer', 'total' => null];
        }

        $band = $this->effectiveBand($declared, $rate, $income, $c);
        if (!$band['applicable']) {
            return ['applicable' => false, 'reason' => $band['reason'], 'total' => null];
        }

        $total = (float) $c['pausal_annual'][$band['effective']];
        return [
            'applicable'   => true,
            'effective'    => $band['effective'],
            'declared'     => $band['declared'],
            'surcharge'    => $band['surcharge'],
            'monthly'      => round($total / 12, 0),
            'total'        => round($total, 0),
            'note'         => $band['effective'] !== $band['declared'] ? 'doplatek_do_vyssiho_pasma' : null,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function computeRegular(array $profile, float $income, array $c): array
    {
        $rate = (int) ($profile['activity_rate'] ?? 40);
        // Skutečné výdaje (daňová evidence) NEBO výdajový paušál % se stropem.
        $useActual = !empty($profile['use_actual_expenses']);
        $expenses = $useActual
            ? max(0.0, (float) ($profile['actual_expenses'] ?? 0))
            : min($income * $rate / 100, (float) ($c['expense_caps'][$rate] ?? PHP_INT_MAX));

        $deductions = min((float) ($profile['mortgage_interest'] ?? 0), (float) $c['mortgage_cap'])
            + min((float) ($profile['pension_contrib'] ?? 0), (float) $c['pension_cap'])
            + (float) ($profile['life_insurance'] ?? 0)
            + (float) ($profile['donations'] ?? 0);

        $base = max(0.0, $income - $expenses - $deductions);

        // Progresivní daň 15 % / 23 %
        $thr = (float) $c['tax_high_threshold'];
        $tax = $base <= $thr
            ? $base * $c['tax_rate_low']
            : $thr * $c['tax_rate_low'] + ($base - $thr) * $c['tax_rate_high'];

        // Nevratné slevy: poplatník + manželka (jen do nuly)
        $nonRefundable = (float) $c['credit_taxpayer'] + (!empty($profile['spouse_credit']) ? (float) $c['credit_spouse'] : 0.0);
        $taxAfterCredits = max(0.0, $tax - $nonRefundable);

        // Daňové zvýhodnění na děti — smí jít do mínusu (daňový bonus / vratka)
        $childTotal = $this->childCreditTotal((int) ($profile['children_count'] ?? 0), $c['child_credits']);
        $incomeTax = $taxAfterCredits - $childTotal;

        // Pojistné — z vyměřovacího základu (% zisku), s ročními minimy. Slevy neovlivní.
        // Od 2024 se základ liší: sociální 55 % zisku, zdravotní 50 % zisku.
        $profit = $income - $expenses;
        $socMin = !empty($profile['is_secondary']) ? (float) $c['social_min_base_secondary'] : (float) $c['social_min_base_main'];
        $socialBase = max($profit * (float) $c['social_assessment_pct'], $socMin);
        $healthBase = max($profit * (float) $c['health_assessment_pct'], (float) $c['health_min_base']);
        $social = $socialBase * $c['social_rate'];
        $health = $healthBase * $c['health_rate'];

        $total = $incomeTax + $social + $health;

        return [
            'applicable'   => true,
            'expense_rate' => $rate,
            'use_actual'   => $useActual,
            'expenses'     => round($expenses, 0),
            'deductions'   => round($deductions, 0),
            'tax_base'     => round($base, 0),
            'tax_gross'    => round($tax, 0),
            'credit_taxpayer' => (float) $c['credit_taxpayer'],
            'credit_spouse'   => !empty($profile['spouse_credit']) ? (float) $c['credit_spouse'] : 0.0,
            'child_credit'    => round($childTotal, 0),
            'income_tax'   => round($incomeTax, 0), // záporné = daňový bonus
            'is_bonus'     => $incomeTax < 0,
            'social'       => round($social, 0),
            'health'       => round($health, 0),
            'total'        => round($total, 0),
            // Čistý příjem = co reálně zbyde (paušální výdaje nejsou reálný výdaj,
            // proto se neodečítají); efektivní sazba = podíl odvodů na příjmu.
            'net_income'     => round($income - $total, 0),
            'effective_rate' => $income > 0 ? round($total / $income, 4) : 0.0,
        ];
    }

    /**
     * Predikce běžícího roku: projekce příjmu a měsíc překročení limitů.
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public function predict(array $profile, float $ytdIncome, int $monthsElapsed, array $c): array
    {
        $runRate = $monthsElapsed > 0 ? $ytdIncome / $monthsElapsed : 0.0;
        $projected = $runRate * 12;

        $rate = (int) ($profile['activity_rate'] ?? 40);
        $declared = (string) ($profile['flat_tax_band'] ?? 'none');

        // Projekce ZISKU (příjmy − výdaje) pro limit vedlejší činnosti. Výdaje dle
        // profilu: skutečné (roční odhad), nebo výdajový paušál % se stropem — stejně
        // jako computeRegular(). Rozhodná částka se měří proti zisku, ne příjmu.
        $useActual = !empty($profile['use_actual_expenses']);
        $projectedExpenses = $useActual
            ? max(0.0, (float) ($profile['actual_expenses'] ?? 0))
            : min($projected * $rate / 100, (float) ($c['expense_caps'][$rate] ?? PHP_INT_MAX));
        $projectedProfit = max(0.0, $projected - $projectedExpenses);

        // Vedlejší SVČ: rozhodná částka pro povinnou účast na důchodovém pojištění.
        // Měsíc dopočítáme z rovnoměrného tempa zisku (nepotřebuje YTD výdaje).
        $secondarySocial = null;
        if (!empty($profile['is_secondary']) && isset($c['social_secondary_participation_threshold'])) {
            $threshold = (float) $c['social_secondary_participation_threshold'];
            $willCross = $projectedProfit >= $threshold;
            $profitRunRate = $projectedProfit / 12;
            $secondarySocial = [
                'threshold'        => $threshold,
                'projected_profit' => round($projectedProfit, 0),
                'will_cross'       => $willCross,
                'month'            => $willCross && $profitRunRate > 0 ? (int) ceil($threshold / $profitRunRate) : null,
            ];
        }

        $thresholds = [];
        if ($declared !== 'none' && isset($c['band_ceilings'][$rate][$declared])) {
            $thresholds[] = ['key' => 'band_ceiling', 'label' => 'strop pásma ' . $declared, 'value' => (float) $c['band_ceilings'][$rate][$declared]];
        }
        $thresholds[] = ['key' => 'vat_low',  'label' => 'limit DPH / paušálu (2 M)', 'value' => (float) $c['vat_limit_low']];
        $thresholds[] = ['key' => 'vat_high', 'label' => 'okamžitý plátce DPH (2,54 M)', 'value' => (float) $c['vat_limit_high']];

        $crossings = [];
        foreach ($thresholds as $t) {
            if ($runRate <= 0) {
                continue;
            }
            $monthReached = ($t['value'] - $ytdIncome) / $runRate + $monthsElapsed;
            $willCross = $projected >= $t['value'];
            $crossings[] = [
                'key'        => $t['key'],
                'label'      => $t['label'],
                'value'      => $t['value'],
                'will_cross' => $willCross,
                'month'      => $willCross ? (int) ceil($monthReached) : null,
            ];
        }

        // Doporučení „odlož fakturu": překročení 2 M nastane pozdě v roce → posun do ledna.
        $defer = null;
        foreach ($crossings as $cr) {
            if ($cr['key'] === 'vat_low' && $cr['will_cross'] && $cr['month'] !== null && $cr['month'] >= 11 && $cr['month'] <= 12) {
                $defer = [
                    'month'   => $cr['month'],
                    'message' => 'Překročení 2 M nastane na konci roku. Posunutím prosincových faktur do ledna zůstaneš pod limitem (neplátce + paušál).',
                ];
            }
        }

        return [
            'year'           => $c['year'],
            'ytd_income'     => round($ytdIncome, 0),
            'months_elapsed' => $monthsElapsed,
            'run_rate'       => round($runRate, 0),
            'projected'      => round($projected, 0),
            'projected_profit' => round($projectedProfit, 0),
            'crossings'      => $crossings,
            'secondary_social' => $secondarySocial,
            'defer_advice'   => $defer,
        ];
    }

    /**
     * Pravděpodobný čistý příjem za konkrétní (uzavřený) měsíc — odhad.
     *
     * Nejde přesně spočítat: daň z příjmu i minima pojistného se počítají z
     * ROČNÍHO základu, ne po měsících. Proto se měsíc anualizuje (× 12) a
     * spočítá stejnou logikou jako {@see computeRegular()} (výdajový paušál
     * NEBO skutečné náklady, odpočty, dětské slevy, minimální vyměřovací
     * základy pojistného), odvody se pak vydělí 12. Čistý příjem = zisk (tržby
     * − skutečné zaplacené náklady) minus tyto měsíční odvody — reálná hotovost,
     * co zbyde. Je to "kdyby tenhle měsíc reprezentoval celý rok" — nikdy ne
     * přesná částka, jen orientační odhad (`$monthExpenses` = skutečné zaplacené
     * náklady daného měsíce, ne paušál).
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c konstanty roku
     * @return array<string,mixed>
     */
    public function estimateMonthly(array $profile, float $monthIncome, float $monthExpenses, array $c): array
    {
        $annualIncome = $monthIncome * 12;

        // Výdaje pro DAŇOVÝ ZÁKLAD (a tím i pro vyměřovací základ pojistného):
        // stejná volba jako computeRegular() — buď výdajový paušál % z příjmu (se
        // stropem), nebo skutečné výdaje. Paušál není reálný cashflow, proto se pro
        // zobrazení "náklady"/"zisk" (informativní, reálná hotovost) níže vždy bere
        // $monthExpenses (skutečně zaplacené přijaté faktury), bez ohledu na režim.
        $rate = (int) ($profile['activity_rate'] ?? 40);
        $useActual = !empty($profile['use_actual_expenses']);
        $taxExpensesAnnual = $useActual
            ? $monthExpenses * 12
            : min($annualIncome * $rate / 100, (float) ($c['expense_caps'][$rate] ?? PHP_INT_MAX));

        $deductions = min((float) ($profile['mortgage_interest'] ?? 0), (float) $c['mortgage_cap'])
            + min((float) ($profile['pension_contrib'] ?? 0), (float) $c['pension_cap'])
            + (float) ($profile['life_insurance'] ?? 0)
            + (float) ($profile['donations'] ?? 0);

        $base = max(0.0, $annualIncome - $taxExpensesAnnual - $deductions);

        $thr = (float) $c['tax_high_threshold'];
        $tax = $base <= $thr
            ? $base * $c['tax_rate_low']
            : $thr * $c['tax_rate_low'] + ($base - $thr) * $c['tax_rate_high'];

        $nonRefundable = (float) $c['credit_taxpayer'] + (!empty($profile['spouse_credit']) ? (float) $c['credit_spouse'] : 0.0);
        $taxAfterCredits = max(0.0, $tax - $nonRefundable);

        $childTotal = $this->childCreditTotal((int) ($profile['children_count'] ?? 0), $c['child_credits']);
        $annualIncomeTax = $taxAfterCredits - $childTotal;

        $taxProfit = $annualIncome - $taxExpensesAnnual;
        $socMin = !empty($profile['is_secondary']) ? (float) $c['social_min_base_secondary'] : (float) $c['social_min_base_main'];
        $socialBase = max($taxProfit * (float) $c['social_assessment_pct'], $socMin);
        $healthBase = max($taxProfit * (float) $c['health_assessment_pct'], (float) $c['health_min_base']);
        $annualSocial = $socialBase * $c['social_rate'];
        $annualHealth = $healthBase * $c['health_rate'];

        // Měsíční odvody = roční / 12 (daň i minima pojistného se počítají z ročního
        // základu). Zaokrouhlené hodnoty, aby vodopád v UI seděl na korunu.
        $incomeTax = round($annualIncomeTax / 12, 0);
        $social    = round($annualSocial / 12, 0);
        $health    = round($annualHealth / 12, 0);

        $revenue  = round($monthIncome, 0);
        $expenses = round($monthExpenses, 0);
        $profit   = round($monthIncome - $monthExpenses, 0);

        return [
            'revenue'    => $revenue,
            'expenses'   => $expenses,
            'profit'     => $profit,
            'income_tax' => $incomeTax,
            'social'     => $social,
            'health'     => $health,
            // Čistý příjem = reálná hotovost, co zbyde: zisk (tržby − skutečné zaplacené
            // náklady) po odečtení odvodů. Na rozdíl od roční karty (kde jsou „náklady"
            // paušál, tedy fiktivní, a proto se neodečítají) tato měsíční karta pracuje
            // se skutečnými náklady, takže je do čistého příjmu započítává — vodopád sedí.
            'net_income' => $profit - $incomeTax - $social - $health,
        ];
    }

    /** @param array<int,int> $credits */
    private function childCreditTotal(int $n, array $credits): float
    {
        $sum = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $idx = min($i, count($credits)) - 1;
            $sum += $credits[$idx];
        }
        return $sum;
    }
}
