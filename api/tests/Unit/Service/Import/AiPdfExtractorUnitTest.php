<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic testy pro privátní helpery v AiPdfExtractoru.
 *
 * Pokrývá:
 *   - detectWeakExtraction — kdy spustit auto-upgrade na Sonnet (vendor=tenant /
 *     katastrofální items mismatch >50 %)
 *   - maybeFlagTotalsMismatch — signed sum (sleva s mínusem, dobropisy se zápornou
 *     qty), žádný `total_with_vat / 1.21` fallback (multi-rate false positive)
 *   - applyRoundingFromAiTotal — Zoner case 84092.58 vs "K úhradě" 84093 = 0.42 rounding
 *
 * Závislosti AiPdfExtractoru jsou mockované přes PHPUnit createMock — testy
 * neběží proti DB / API.
 */
#[AllowMockObjectsWithoutExpectations]
final class AiPdfExtractorUnitTest extends TestCase
{
    private PurchaseInvoiceRepository $repo;
    private AiPdfExtractor $extractor;

    protected function setUp(): void
    {
        // Mock všechny závislosti — privátní metody, které testujeme, používají jen
        // některé z nich. Pro pure-logic checky (detectWeakExtraction) žádné nepotřebujeme,
        // pro maybeFlagTotalsMismatch / applyRoundingFromAiTotal jen $repo.
        $this->repo = $this->createMock(PurchaseInvoiceRepository::class);

        $this->extractor = new AiPdfExtractor(
            $this->createMock(Connection::class),
            $this->createMock(AnthropicClient::class),
            $this->createMock(ClientResolver::class),
            $this->repo,
            $this->createMock(PurchaseInvoiceCalculator::class),
            $this->createMock(PdfIsdocExtractor::class),
            $this->createMock(IsdocParser::class),
            $this->createMock(IsdocToPurchaseInvoiceMapper::class),
            $this->createMock(Config::class),
            $this->createMock(CnbExchangeRateClient::class),
            new ImageToPdfConverter(), // bez závislostí — reálná instance stačí
            new NullLogger(),
        );
    }

    // ── detectWeakExtraction ────────────────────────────────────────────────

    public function testDetectWeak_clean_extraction_returns_null(): void
    {
        $data = [
            'vendor'   => ['ic' => '19774290'],
            'customer' => ['ic' => '21370362'],
            'total_without_vat' => 4113.29,
            'items' => [
                ['quantity' => 1, 'unit_price_without_vat' => 4113.29],
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_vendor_equals_tenant_no_customer_triggers(): void
    {
        $data = [
            'vendor'   => ['ic' => '21370362'], // = tenant
            'customer' => ['ic' => null],
            'total_without_vat' => 5000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 5000]],
        ];
        $this->assertSame('vendor_is_tenant_no_swap_target',
            $this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_vendor_equals_tenant_with_customer_handles_swap(): void
    {
        // Když customer má jiné IČ než tenant, swap-back proběhne normální cestou
        // → není to weakness, nereaguj.
        $data = [
            'vendor'   => ['ic' => '21370362'], // = tenant
            'customer' => ['ic' => '19774290'], // jiný IČ → swap-back funguje
            'total_without_vat' => 5000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 5000]],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_catastrophic_mismatch_triggers(): void
    {
        // NC Auto pattern: AI total 5317, items sum 31057 (6x víc)
        $data = [
            'vendor'   => ['ic' => '19774290'],
            'customer' => ['ic' => '21370362'],
            'total_without_vat' => 5317.34,
            'items' => [
                ['quantity' => 1, 'unit_price_without_vat' => 239],
                ['quantity' => 14, 'unit_price_without_vat' => 1980], // 27720 halucinace
                ['quantity' => 1, 'unit_price_without_vat' => 3098.34],
            ],
        ];
        $this->assertSame('catastrophic_items_mismatch',
            $this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_discount_sign_under_threshold_does_not_trigger(): void
    {
        // Zoner pattern PŘED sleva fix: sleva kladná, items=84942, AI=69498 → 22 %.
        // Pod 50 % prahem → není to weakness, jen warning.
        $data = [
            'vendor'   => ['ic' => '49437381'],
            'customer' => ['ic' => '21370362'],
            'total_without_vat' => 69498.35,
            'items' => [
                ['quantity' => 12, 'unit_price_without_vat' => 5709],
                ['quantity' => 12, 'unit_price_without_vat' => 726],
                ['quantity' => 12, 'unit_price_without_vat' => 643.50], // chybí mínus
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_skips_check2_when_ai_total_without_vat_missing(): void
    {
        // Bez total_without_vat NESPOUŠTÍME items vs total kontrolu (multi-rate
        // by jinak vyžadoval `total_with_vat / 1.21`, což u 21/12/0 % mixu nesedí).
        $data = [
            'vendor'   => ['ic' => '19774290'],
            'customer' => ['ic' => '21370362'],
            'total_with_vat' => 6433.98, // jen s DPH, bez DPH chybí
            'items' => [
                ['quantity' => 14, 'unit_price_without_vat' => 1980], // halucinace
            ],
        ];
        $this->assertNull($this->invokeDetectWeak($data, '21370362'));
    }

    public function testDetectWeak_no_tenant_ic_skips_vendor_check(): void
    {
        // Když nemáme tenant IC (např. supplier bez IČ — zahraniční fyzická osoba),
        // vendor=tenant check se přeskočí.
        $data = [
            'vendor'   => ['ic' => '12345678'],
            'customer' => ['ic' => null],
            'total_without_vat' => 1000,
            'items' => [['quantity' => 1, 'unit_price_without_vat' => 1000]],
        ];
        $this->assertNull($this->invokeDetectWeak($data, null));
    }

    // ── maybeFlagTotalsMismatch ────────────────────────────────────────────

    public function testMismatch_clean_invoice_no_warning(): void
    {
        // Items sum sedí s AI totalem do 2 % → žádný warning.
        $this->repo->expects($this->never())->method('setExtractionWarning');
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 4113.29, 'vat_rate_id' => 1],
        ];
        $data = ['total_without_vat' => 4113.29];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_discount_with_negative_sign_no_warning(): void
    {
        // Zoner #18 po fix: sleva má v unit_price mínus, signed sum sedí s AI.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [
            ['quantity' => 12, 'unit_price_without_vat' => 5709,     'vat_rate_id' => 1], // 68508
            ['quantity' => 12, 'unit_price_without_vat' => 726,      'vat_rate_id' => 1], // 8712
            ['quantity' => 12, 'unit_price_without_vat' => -643.50,  'vat_rate_id' => 1], // -7722
        ];
        $data = ['total_without_vat' => 69498]; // signed sum: 68508+8712-7722 = 69498
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_credit_note_negative_qty_no_warning(): void
    {
        // Dobropis: extractor aplikoval `qty *= -1`. AI total je kladný (per prompt).
        // signed sum bude záporný, ale po abs() musí sednout s AI totalem.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [
            ['quantity' => -1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ['quantity' => -2, 'unit_price_without_vat' => 500,  'vat_rate_id' => 1],
        ];
        $data = ['total_without_vat' => 2000];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_discount_wrong_sign_warns_no_placeholder(): void
    {
        // Zoner PŘED sleva fix: 22 % rozdíl → warning ano, placeholder ne (<50 %).
        $this->repo->expects($this->once())
            ->method('setExtractionWarning')
            ->with(42, 1, $this->stringContains('vyšší než'));
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 12, 'unit_price_without_vat' => 5709,   'vat_rate_id' => 1],
            ['quantity' => 12, 'unit_price_without_vat' => 726,    'vat_rate_id' => 1],
            ['quantity' => 12, 'unit_price_without_vat' => 643.50, 'vat_rate_id' => 1], // chybí mínus
        ];
        $data = ['total_without_vat' => 69498.35];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_catastrophic_triggers_placeholder_with_preserved_descriptions(): void
    {
        // NC Auto >50 % mismatch → placeholder + zachované AI popisy s qty=0/price=0.
        $this->repo->expects($this->once())->method('setExtractionWarning');
        $this->repo->expects($this->once())
            ->method('replaceItems')
            ->with(42, $this->callback(function (array $items): bool {
                // První řádek = KOREKCE s AI totalem
                if (!str_starts_with($items[0]['description'], 'KOREKCE')) return false;
                if ($items[0]['unit_price_without_vat'] !== 5317.34) return false;
                if ($items[0]['quantity'] !== 1.0) return false;
                // Další řádky = AI popisy s qty=0/price=0
                if (count($items) !== 4) return false; // 1 korekce + 3 popisy
                for ($i = 1; $i <= 3; $i++) {
                    if ($items[$i]['quantity'] !== 0.0) return false;
                    if ($items[$i]['unit_price_without_vat'] !== 0.0) return false;
                }
                return $items[1]['description'] === 'Vyvážení kol'
                    && $items[2]['description'] === 'AdBlue'
                    && $items[3]['description'] === 'Závaží';
            }));

        $items = [
            ['quantity' => 14, 'unit_price_without_vat' => 1980, 'vat_rate_id' => 1, 'description' => 'Vyvážení kol'],
            ['quantity' => 15, 'unit_price_without_vat' => 31,   'vat_rate_id' => 1, 'description' => 'AdBlue'],
            ['quantity' => 8,  'unit_price_without_vat' => 52,   'vat_rate_id' => 1, 'description' => 'Závaží'],
        ];
        $data = ['total_without_vat' => 5317.34];
        $this->invokeFlag(42, 1, $data, $items);
    }

    public function testMismatch_skips_when_total_without_vat_missing(): void
    {
        // Bez total_without_vat nepoužíváme `total_with_vat / 1.21` fallback,
        // u multi-rate by dělal false positive. Žádný warning.
        $this->repo->expects($this->never())->method('setExtractionWarning');
        $this->repo->expects($this->never())->method('replaceItems');

        $items = [
            ['quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
        ];
        $data = ['total_with_vat' => 1210]; // jen s DPH; bez DPH chybí
        $this->invokeFlag(42, 1, $data, $items);
    }

    // ── applyRoundingFromAiTotal ───────────────────────────────────────────

    public function testRounding_zoner_84092_to_84093_yields_042(): void
    {
        // Zoner: items sum × 1.21 = 84092.58, AI total_with_vat (z "K úhradě") = 84093.
        // Rozdíl 0.42 → uložit jako rounding.
        $this->repo->method('find')->willReturn(['total_with_vat' => 84092.58]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, 0.42);

        $this->invokeRounding(42, 1, ['total_with_vat' => 84093], false);
    }

    public function testRounding_credit_note_applies_negative_sign(): void
    {
        // Dobropis: total_with_vat v DB záporný, AI totaly kladné (per prompt).
        // Sign aplikujeme v setRounding na záporno.
        $this->repo->method('find')->willReturn(['total_with_vat' => -84092.58]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.42);

        $this->invokeRounding(42, 1, ['total_with_vat' => 84093], true);
    }

    public function testRounding_no_diff_skips(): void
    {
        // Když je AI total = recomputed total, nic se neukládá.
        $this->repo->method('find')->willReturn(['total_with_vat' => 84092.58]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => 84092.58], false);
    }

    public function testRounding_diff_over_1_kc_skips(): void
    {
        // Rozdíl > 1 Kč není zaokrouhlení, je to chyba — ignorujeme.
        $this->repo->method('find')->willReturn(['total_with_vat' => 1000]);
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => 1050], false);
    }

    public function testRounding_missing_ai_total_skips(): void
    {
        $this->repo->expects($this->never())->method('find');
        $this->repo->expects($this->never())->method('setRounding');

        $this->invokeRounding(42, 1, ['total_with_vat' => null], false);
    }

    public function testRounding_prefers_pdf_rounded_over_ai_total(): void
    {
        // Regression: user report Vodafone faktura 1025255728.
        //   Items recompute: 1×1241,34 × 1,21 = 1502,02 (= total_with_vat v DB)
        //   AI total_with_vat (její DPH math): 1502,03  ← se splete o haléř
        //   AI total_with_vat_rounded (PDF "K úhradě"): 1502,00
        //   Reálný rounding má být PDF − recompute = 1502,00 − 1502,02 = -0,02
        //   PŘED FIXEM: computeRounding bral rounded − AI total = -0,03 → "K úhradě" 1501,99
        //   PO FIXU: applyRoundingFromPdfTotal preferuje rounded, počítá vůči
        //   přesnému items totalu z DB → -0,02 → "K úhradě" 1502,00 ✓
        $this->repo->method('find')->willReturn(['total_with_vat' => 1502.02]);
        $this->repo->expects($this->once())
            ->method('setRounding')
            ->with(42, 1, -0.02);

        $this->invokeRounding(42, 1, [
            'total_with_vat'         => 1502.03,  // AI's chybný DPH součet
            'total_with_vat_rounded' => 1502.00,  // PDF "K úhradě" — preferovat
        ], false);
    }

    // ── resolvePricesIncludeVat (režim „ceny s DPH" pro účtenky) ────────────

    public function testPricesInclVat_explicit_true_flag_wins(): void
    {
        // AI explicitně řekla, že ceny jsou s DPH → režim shora, bez ohledu na typ.
        self::assertTrue($this->invokeResolvePricesInclVat(['unit_prices_include_vat' => true], 'invoice'));
    }

    public function testPricesInclVat_explicit_false_flag_wins_even_for_receipt(): void
    {
        // I u účtenky: když AI explicitně řekne false (cena bez DPH na dokladu je),
        // respektujeme to a NEpřepínáme na režim shora.
        self::assertFalse($this->invokeResolvePricesInclVat(['unit_prices_include_vat' => false], 'receipt'));
    }

    public function testPricesInclVat_receipt_defaults_to_true_when_flag_missing(): void
    {
        // Účtenka bez flagu → default true (ceny s DPH, bez DPH cena na dokladu není).
        self::assertTrue($this->invokeResolvePricesInclVat([], 'receipt'));
    }

    public function testPricesInclVat_invoice_defaults_to_false_when_flag_missing(): void
    {
        // Běžná faktura bez flagu → default false (ceny bez DPH, počítá se zdola).
        self::assertFalse($this->invokeResolvePricesInclVat([], 'invoice'));
    }

    public function testMismatch_receipt_gross_sum_matches_total_with_vat_no_warning(): void
    {
        // Režim shora: ceny řádků jsou s DPH → reference je total_with_vat, ne _without_.
        // Účtenka 344 (1×344) sedí s total_with_vat → žádné varování,
        // i když total_without_vat (284,30) by se součtem řádků NEsedělo.
        $this->repo->expects($this->never())->method('setExtractionWarning');

        $items = [['quantity' => 1, 'unit_price_without_vat' => 344.00, 'vat_rate_id' => 1]];
        $data = ['total_with_vat' => 344.00, 'total_without_vat' => 284.30];
        $this->invokeFlag(42, 1, $data, $items, true); // pricesIncludeVat = true
    }

    // ── isVendorNonPayer (dodavatel neplátce → bez nároku na odpočet) ───────

    public function testNonPayer_ares_says_payer_false(): void
    {
        // Autoritativní ARES/VIES výsledek true → plátce → false (je nárok).
        self::assertFalse(AiPdfExtractor::isVendorNonPayer(true, ['is_vat_payer' => false]));
    }

    public function testNonPayer_ares_says_nonpayer_true(): void
    {
        // ARES/VIES říká neplátce (false) → true (bez nároku), přebije i signál z dokladu.
        self::assertTrue(AiPdfExtractor::isVendorNonPayer(false, ['is_vat_payer' => true]));
    }

    public function testNonPayer_unknown_uses_document_signal_false_means_nonpayer(): void
    {
        // Registr nerozhodl (null) → signál z dokladu: AI vendor.is_vat_payer=false ⇒ neplátce.
        self::assertTrue(AiPdfExtractor::isVendorNonPayer(null, ['is_vat_payer' => false]));
    }

    public function testNonPayer_unknown_without_signal_defaults_payer(): void
    {
        // Nezjištěno z registru ani z dokladu → konzervativně NEpředpokládej neplátce.
        self::assertFalse(AiPdfExtractor::isVendorNonPayer(null, []));
        self::assertFalse(AiPdfExtractor::isVendorNonPayer(null, ['is_vat_payer' => true]));
    }

    // ── collapseToSummaryBaseLine (haléřový drift → 1 ks dle rekapitulace) ──

    public function testCollapse_fuelReceipt_matchesVatRecap(): void
    {
        // Reálný případ (faktura 984): 34,29 l × 33,71 = 1 155,92, ale REKAPITULACE
        // dokladu má základ 1 155,94. Sluč na 1 ks × 1 155,94.
        $items = [[
            'description' => 'Prémiová nafta', 'quantity' => 34.29, 'unit' => 'l',
            'unit_price_without_vat' => 33.71, 'vat_rate_id' => 7, 'order_index' => 0,
        ]];
        $out = \MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 1155.94], false);

        self::assertNotNull($out);
        self::assertCount(1, $out);
        self::assertSame(1.0, $out[0]['quantity']);
        self::assertSame(1155.94, $out[0]['unit_price_without_vat']);
        self::assertSame(7, $out[0]['vat_rate_id']);
        self::assertSame('Prémiová nafta', $out[0]['description']);

        // A přes InvoiceMath sedí na rekapitulaci: 1 155,94 / 242,75 / 1 398,69.
        $r = \MyInvoice\Service\Invoice\InvoiceMath::compute([
            ['quantity' => 1.0, 'unit_price_without_vat' => 1155.94, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(1155.94, $r['totals']['without_vat']);
        self::assertSame(242.75,  $r['totals']['vat']);
        self::assertSame(1398.69, $r['totals']['with_vat']);
    }

    public function testCollapse_exactMatch_keepsLines(): void
    {
        // Součet řádků přesně sedí se základem → neslučovat (null).
        $items = [['description' => 'X', 'quantity' => 2.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 200.0], false));
    }

    public function testCollapse_multipleRates_keepsLines(): void
    {
        $items = [
            ['description' => 'A', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0],
            ['description' => 'B', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 50.0,  'vat_rate_id' => 8, 'order_index' => 1],
        ];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 149.99], false));
    }

    public function testCollapse_creditNote_keepsLines(): void
    {
        $items = [['description' => 'X', 'quantity' => 34.29, 'unit' => 'l', 'unit_price_without_vat' => 33.71, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 1155.94], true));
    }

    public function testCollapse_largeDiff_keepsLines(): void
    {
        // Rozdíl > 1 Kč = jiný problém (chybné řádky) → neslučovat, řeší warning.
        $items = [['description' => 'X', 'quantity' => 10.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, ['total_without_vat' => 950.0], false));
    }

    public function testCollapse_missingSummaryBase_keepsLines(): void
    {
        $items = [['description' => 'X', 'quantity' => 34.29, 'unit' => 'l', 'unit_price_without_vat' => 33.71, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::collapseToSummaryBaseLine($items, [], false));
    }

    // ── authoritativeRecapBaseLine (#99 — doklad s rekapitulací = záznam, ne kalkulačka) ──

    /** Reálný případ issue #99: účtenka za naftu, „cena/litr" 35,90 je BRUTTO. */
    private function fuelReceiptData(): array
    {
        return [
            'currency'         => 'CZK',
            'total_without_vat' => 2219.59,
            'total_with_vat'    => 2685.70,
            'vat_recap'         => [['rate' => 21, 'base' => 2219.59, 'vat' => 466.11]],
            'items'             => [['description' => 'DIESEL', 'quantity' => 74.81, 'unit' => 'L', 'unit_price_without_vat' => 35.90, 'vat_rate' => 21]],
        ];
    }

    public function testAuthoritativeRecap_fuelReceipt_grossUnitPrice_usesDocumentBase(): void
    {
        $data = $this->fuelReceiptData();
        // řádek tak, jak ho extractor postaví z AI (35,90 = brutto cena/litr)
        $items = [['description' => 'DIESEL', 'quantity' => 74.81, 'unit' => 'L', 'unit_price_without_vat' => 35.90, 'vat_rate_id' => 7, 'order_index' => 0]];

        $out = \MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false);

        self::assertNotNull($out);
        self::assertCount(1, $out);
        self::assertSame(1.0, $out[0]['quantity']);
        self::assertSame(2219.59, $out[0]['unit_price_without_vat']); // základ z rekapitulace, NE 2 685,68
        self::assertSame('DIESEL', $out[0]['description']);

        // A přes InvoiceMath sedí přesně na doklad: 2 219,59 / 466,11 / 2 685,70 (žádné zaokrouhlení).
        $r = \MyInvoice\Service\Invoice\InvoiceMath::compute([
            ['quantity' => 1.0, 'unit_price_without_vat' => 2219.59, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(2219.59, $r['totals']['without_vat']);
        self::assertSame(466.11,  $r['totals']['vat']);
        self::assertSame(2685.70, $r['totals']['with_vat']);
    }

    public function testAuthoritativeRecap_linesAlreadyMatchBase_keepsLines(): void
    {
        // Běžná faktura: řádky bez DPH sedí na základ z rekapitulace → NEslučovat (zachovej detail).
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 200.0, 'total_with_vat' => 242.0,
            'vat_recap' => [['rate' => 21, 'base' => 200.0, 'vat' => 42.0]],
        ];
        $items = [
            ['description' => 'A', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 120.0, 'vat_rate_id' => 7, 'order_index' => 0],
            ['description' => 'B', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 80.0, 'vat_rate_id' => 7, 'order_index' => 1],
        ];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
    }

    public function testAuthoritativeRecap_inconsistentRecap_returnsNull(): void
    {
        // Rekapitulace NESEDÍ na celkem (200+42 ≠ 999) → nedůvěřuj, ať to vyřeší mismatch varování.
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 200.0, 'total_with_vat' => 999.0,
            'vat_recap' => [['rate' => 21, 'base' => 200.0, 'vat' => 42.0]],
        ];
        $items = [['description' => 'X', 'quantity' => 10.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::singleRateConsistentRecap($data));
    }

    public function testSingleRateConsistentRecap_ignoresEmptyTemplateRows(): void
    {
        // AI běžně vrátí šablonu rekapitulace s nulovými řádky 0 %/12 % vedle reálné 21 %
        // (případ Axigon) — prázdné řádky musí jít stranou, ať zůstane „jednosazbové".
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 1554.88, 'total_with_vat' => 1881.40,
            'vat_recap' => [
                ['rate' => 0, 'base' => 0, 'vat' => 0],
                ['rate' => 12, 'base' => 0, 'vat' => 0],
                ['rate' => 21, 'base' => 1554.88, 'vat' => 326.52],
            ],
        ];
        $recap = \MyInvoice\Service\Import\AiPdfExtractor::singleRateConsistentRecap($data);
        self::assertNotNull($recap);
        self::assertSame(21.0, $recap['rate']);
        self::assertSame(1554.88, $recap['base']);
        self::assertSame(326.52, $recap['vat']);
    }

    public function testAuthoritativeRecap_multiRate_returnsNull(): void
    {
        $data = [
            'currency' => 'CZK', 'total_without_vat' => 150.0, 'total_with_vat' => 180.5,
            'vat_recap' => [['rate' => 21, 'base' => 100.0, 'vat' => 21.0], ['rate' => 12, 'base' => 50.0, 'vat' => 6.0]],
        ];
        $items = [['description' => 'X', 'quantity' => 1.0, 'unit' => 'ks', 'unit_price_without_vat' => 999.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
    }

    public function testAuthoritativeRecap_noRecap_returnsNull(): void
    {
        $data = ['currency' => 'CZK', 'total_without_vat' => 950.0];
        $items = [['description' => 'X', 'quantity' => 10.0, 'unit' => 'ks', 'unit_price_without_vat' => 100.0, 'vat_rate_id' => 7, 'order_index' => 0]];
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine($items, $data, false));
    }

    public function testAuthoritativeRecap_creditNote_returnsNull(): void
    {
        self::assertNull(\MyInvoice\Service\Import\AiPdfExtractor::authoritativeRecapBaseLine(
            [['description' => 'DIESEL', 'quantity' => 74.81, 'unit' => 'L', 'unit_price_without_vat' => 35.90, 'vat_rate_id' => 7, 'order_index' => 0]],
            $this->fuelReceiptData(),
            true,
        ));
    }

    // ── Helper: reflection invokers ────────────────────────────────────────

    private function invokeResolvePricesInclVat(array $data, string $documentKind): bool
    {
        $ref = new \ReflectionMethod($this->extractor, 'resolvePricesIncludeVat');
        return (bool) $ref->invoke(null, $data, $documentKind);
    }

    private function invokeDetectWeak(array $data, ?string $tenantIc): ?string
    {
        $ref = new \ReflectionMethod($this->extractor, 'detectWeakExtraction');
        return $ref->invoke($this->extractor, $data, $tenantIc);
    }

    private function invokeFlag(int $invoiceId, int $supplierId, array $data, array $items, bool $pricesIncludeVat = false): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'maybeFlagTotalsMismatch');
        $ref->invoke($this->extractor, $invoiceId, $supplierId, $data, $items, $pricesIncludeVat);
    }

    private function invokeRounding(int $id, int $supplierId, array $data, bool $isCredit): void
    {
        $ref = new \ReflectionMethod($this->extractor, 'applyRoundingFromPdfTotal');
        $ref->invoke($this->extractor, $id, $supplierId, $data, $isCredit);
    }
}
