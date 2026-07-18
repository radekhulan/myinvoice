<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Tax\TaxOptimizer;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy daňového enginu. Očekávané hodnoty jsou ručně dopočtené ze
 * ZÁKONNÝCH konstant 2025 (ověřené dle Finanční správy / ČSSZ / VZP) — testy tak
 * zafixují jak výpočet, tak správnost konstant. Klíčové: zdravotní vyměřovací
 * základ je 50 % zisku (NE 55 % jako sociální).
 */
final class TaxOptimizerTest extends TestCase
{
    private TaxOptimizer $opt;
    /** @var array<string,mixed> */
    private array $c;

    protected function setUp(): void
    {
        $this->opt = new TaxOptimizer();
        $this->c = TaxConstants::forYear(2025);
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function profile(array $over = []): array
    {
        return $over + [
            'activity_rate' => 60, 'flat_tax_band' => 'band1', 'is_vat_payer' => false,
            'is_secondary' => false, 'spouse_credit' => false, 'children_count' => 0,
            'mortgage_interest' => 0, 'pension_contrib' => 0, 'life_insurance' => 0, 'donations' => 0,
        ];
    }

    public function testRegularBasic60Percent(): void
    {
        $r = $this->opt->compare($this->profile(), 1_200_000, $this->c)['regular'];
        self::assertSame(720000.0, $r['expenses']);     // 60 % paušál
        self::assertSame(480000.0, $r['tax_base']);     // 1,2M − 720k
        self::assertSame(41160.0, $r['income_tax']);    // 72 000 − 30 840 sleva
        self::assertSame(77088.0, $r['social']);        // max(264k, 195 540) × 29,2 %
        self::assertSame(37711.0, $r['health']);        // min. základ 279 342 × 13,5 %
        self::assertSame(155959.0, $r['total']);
        self::assertSame(1044041.0, $r['net_income']);  // příjem − odvody
        self::assertEqualsWithDelta(0.13, $r['effective_rate'], 0.0001);
    }

    /**
     * Klíčový regresní test opravy: zdravotní základ = 50 % zisku, sociální 55 %.
     * Při zisku, kde 50 % > min. základ, se musí použít 540 000 (50 %), ne 594 000 (55 %).
     */
    public function testHealthBaseIs50PercentNot55(): void
    {
        $r = $this->opt->compare($this->profile(['activity_rate' => 40, 'flat_tax_band' => 'none']), 1_800_000, $this->c)['regular'];
        self::assertSame(720000.0, $r['expenses']);     // min(40 %, strop 800k) → 720k
        self::assertSame(173448.0, $r['social']);       // 594 000 (55 %) × 29,2 %
        self::assertSame(72900.0, $r['health']);        // 540 000 (50 %) × 13,5 %  ← NE 80 190
    }

    /** Progresivní 23 % nad hranicí + strop výdajového paušálu. */
    public function testProgressiveRateAndExpenseCap(): void
    {
        $r = $this->opt->compare($this->profile(['activity_rate' => 40, 'flat_tax_band' => 'none']), 2_500_000, $this->c)['regular'];
        self::assertSame(800000.0, $r['expenses']);     // strop 40 % paušálu
        self::assertSame(1700000.0, $r['tax_base']);
        // 1 676 052 × 15 % + (1 700 000 − 1 676 052) × 23 % = 256 916
        self::assertSame(256916.0, $r['tax_gross']);
    }

    /** Daňový bonus na děti — income_tax může jít do mínusu. */
    public function testChildTaxBonusGoesNegative(): void
    {
        $r = $this->opt->compare($this->profile(['children_count' => 3]), 400_000, $this->c)['regular'];
        // daň 24 000 − sleva 30 840 = 0; − (15 204 + 22 320 + 27 840) = −65 364
        self::assertSame(-65364.0, $r['income_tax']);
        self::assertTrue($r['is_bonus']);
        self::assertSame(57098.0, $r['social']);        // min. základ 195 540 (zisk nízký)
    }

    /** Vedlejší činnost používá NIŽŠÍ minimální vyměřovací základ sociálního. */
    public function testSecondaryActivityUsesSecondaryMinBase(): void
    {
        $r = $this->opt->compare($this->profile(['is_secondary' => true]), 100_000, $this->c)['regular'];
        self::assertSame(17951.0, $r['social']);        // 61 476 × 29,2 % (ne 195 540)
    }

    /**
     * estimateMonthly() = anualizace (×12) stejnou logikou jako computeRegular(),
     * pak /12. Použité měsíční příjmy/náklady (100k/60k) anualizované dají přesně
     * roční čísla z testRegularBasic60Percent (1,2M příjem, 720k výdaje), takže
     * roční mezivýsledky (income_tax 41160, social 77088, health 37711) musí sedět.
     */
    public function testEstimateMonthlyMatchesAnnualizedRegular(): void
    {
        $r = $this->opt->estimateMonthly($this->profile(), 100_000.0, 60_000.0, $this->c);
        self::assertSame(100000.0, $r['revenue']);
        self::assertSame(60000.0, $r['expenses']);
        self::assertSame(40000.0, $r['profit']);
        self::assertSame(3430.0, $r['income_tax']);   // 41 160 / 12
        self::assertSame(6424.0, $r['social']);        // 77 088 / 12
        self::assertSame(3143.0, $r['health']);         // round(37 711 / 12)
        self::assertSame(27003.0, $r['net_income']);    // zisk 40 000 − (3430 + 6424 + 3143) odvodů
    }

    /**
     * Regresní test: v paušálním režimu se daň/pojistné musí počítat z výdajového
     * paušálu (% z příjmu), NE ze skutečných zaplacených nákladů — i když jsou
     * skutečné náklady mnohem nižší. Skutečné náklady (10k) se použijí jen pro
     * zobrazení "expenses"/"profit" (reálná hotovost), ne pro daňový základ.
     */
    public function testEstimateMonthlyUsesPausalRateForTaxNotRealExpenses(): void
    {
        $r = $this->opt->estimateMonthly($this->profile(), 100_000.0, 10_000.0, $this->c);
        self::assertSame(10000.0, $r['expenses']);      // reálné náklady, jen pro zobrazení
        self::assertSame(90000.0, $r['profit']);        // 100k − 10k reálný cashflow
        // Daň/pojistné počítané ze 60% paušálu (720k), stejně jako by reálné náklady
        // byly 60k — proto STEJNÉ odvody jako testEstimateMonthlyMatchesAnnualizedRegular.
        self::assertSame(3430.0, $r['income_tax']);
        self::assertSame(6424.0, $r['social']);
        self::assertSame(3143.0, $r['health']);
        // Čistý příjem ale vychází z reálného zisku 90 000 (ne 40 000): 90 000 − odvody.
        self::assertSame(77003.0, $r['net_income']);
    }

    /**
     * Strop paušálu: expense_caps[rate] = rate % × 2 000 000 (viz TaxConstants) — takže
     * i při vysokém anualizovaném příjmu (300k/měsíc = 3,6M/rok) paušální výdaje pro
     * daňový základ nepřekročí 720 000 (60 % z 2M), NE 60 % ze skutečného příjmu (2,16M).
     */
    public function testEstimateMonthlyCapsPausalExpensesAt2MIncome(): void
    {
        $r = $this->opt->estimateMonthly($this->profile(), 300_000.0, 0.0, $this->c);
        // Se stropem (720k, NE 60 % × 3,6M = 2 160 000) je roční daňový základ
        // 3 600 000 − 720 000 = 2 880 000 → měsíční daň 32 256 (ověřeno computeRegular
        // logikou). Bez stropu by výdaje byly 3× vyšší a daň citelně nižší.
        self::assertSame(32256.0, $r['income_tax']);
    }

    /** Skutečné výdaje (daňová evidence) místo paušálu. */
    public function testActualExpensesOverridePausal(): void
    {
        $r = $this->opt->compare(
            $this->profile(['use_actual_expenses' => true, 'actual_expenses' => 300000]),
            1_000_000, $this->c
        )['regular'];
        self::assertTrue($r['use_actual']);
        self::assertSame(300000.0, $r['expenses']);   // skutečné, NE 600 000 paušál
        self::assertSame(74160.0, $r['income_tax']);  // (700k × 15 %) − 30 840
        self::assertSame(112420.0, $r['social']);     // 385 000 (55 %) × 29,2 %
        self::assertSame(47250.0, $r['health']);      // 350 000 (50 %) × 13,5 %
        self::assertSame(766170.0, $r['net_income']);
    }

    /** Paušál: vyšší příjem než strop deklarovaného pásma → posun + doplatek. */
    public function testPausalBandUpgradeSurcharge(): void
    {
        $cmp = $this->opt->compare($this->profile(['activity_rate' => 40, 'flat_tax_band' => 'band1']), 1_200_000, $this->c);
        $p = $cmp['pausal'];
        self::assertTrue($p['applicable']);
        self::assertSame('band2', $p['effective']);     // band1 strop 1M < 1,2M → band2
        self::assertSame(200940.0, $p['total']);
        self::assertSame(96348.0, $p['surcharge']);     // 200 940 − 104 592
    }

    /** Plátce DPH nemůže do paušálního režimu → vítězí standardní. */
    public function testVatPayerHasNoPausal(): void
    {
        $cmp = $this->opt->compare($this->profile(['is_vat_payer' => true]), 800_000, $this->c);
        self::assertFalse($cmp['pausal']['applicable']);
        self::assertSame('vat_payer', $cmp['pausal']['reason']);
        self::assertSame('regular', $cmp['winner']);
        self::assertNull($cmp['delta_regular_minus_pausal']);
    }

    /** Vítěz a delta: pro malého OSVČ je paušál levnější. */
    public function testWinnerIsPausalForSmallTrader(): void
    {
        $cmp = $this->opt->compare($this->profile(), 1_200_000, $this->c);
        self::assertSame('pausal', $cmp['winner']);
        self::assertSame(104592.0, $cmp['pausal']['total']);
        self::assertSame(51367.0, $cmp['delta_regular_minus_pausal']); // 155 959 − 104 592
    }

    /** Predikce: run-rate, projekce a měsíc překročení 2M + rada odložit. */
    public function testPredictProjectionAndVatCrossing(): void
    {
        $pred = $this->opt->predict($this->profile(), 1_000_000, 6, $this->c);
        self::assertSame(2000000.0, $pred['projected']);   // 6 měsíců → ×2
        $vatLow = array_values(array_filter($pred['crossings'], fn ($x) => $x['key'] === 'vat_low'))[0];
        self::assertTrue($vatLow['will_cross']);
        self::assertSame(12, $vatLow['month']);
        self::assertNotNull($pred['defer_advice']);
    }

    /** Vedlejší limit jen při is_secondary; měří se proti ZISKU (60 % paušál → zisk 40 %). */
    public function testPredictSecondarySocialThreshold(): void
    {
        // Bez vedlejší činnosti se blok vůbec nepočítá.
        $pred = $this->opt->predict($this->profile(), 150_000, 6, $this->c);
        self::assertNull($pred['secondary_social']);

        // Vedlejší + 60 % paušál: projekce 300k příjmu → zisk 120k ≥ 111 736 → překročí.
        $cross = $this->opt->predict($this->profile(['is_secondary' => true]), 150_000, 6, $this->c);
        self::assertSame(120000.0, $cross['secondary_social']['projected_profit']); // 300k − 60 %
        self::assertSame(111736.0, $cross['secondary_social']['threshold']);
        self::assertTrue($cross['secondary_social']['will_cross']);
        self::assertSame(12, $cross['secondary_social']['month']); // ceil(111 736 / 10 000)

        // Nízký zisk (projekce 100k → zisk 40k) zůstane pod limitem.
        $under = $this->opt->predict($this->profile(['is_secondary' => true]), 50_000, 6, $this->c);
        self::assertFalse($under['secondary_social']['will_cross']);
        self::assertNull($under['secondary_social']['month']);
    }
}
