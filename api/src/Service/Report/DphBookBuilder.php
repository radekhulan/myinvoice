<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Builder pro **Knihu DPH** (interní VAT žurnál).
 *
 * Není to podání FÚ — je to interní účetní pomůcka která seskupí vystavené
 * i přijaté faktury podle řádků DPH přiznání (DPHDP3) a kombinuje:
 *
 *   - **Vystavené faktury** (invoices) → sekce s prefixem `36.001`, `36.002` …
 *     (řádky 1, 2, … DPHDP3 — uskutečněná plnění)
 *   - **Přijaté faktury** (purchase_invoices) → sekce `15.040`, `15.041` …
 *     (řádky 40, 41 … — přijatá tuzemská), `43.012` + `43.043` (dovoz služby:
 *     primary i mirror pod členěním 43, jako POHODA)
 *
 * Scope = **vystavené + přijaté včetně draftů**. Drafty jsou označeny
 * `is_draft=true` v rows; UI je vizuálně odlišuje (badge "Koncept"). Storno
 * (`status='cancelled'`) je vyloučeno, proformy taky.
 *
 * Section key formát:
 *   - **15.XXX** = řádek pro přijatá plnění (sekce 15)
 *   - **36.XXX** = řádek pro vystavená plnění (sekce 36)
 *   - **43.XXX** = RC/dovozové páry — primary samovyměření (43.003/43.010/43.012 …)
 *     i mirror odpočet ř.43 (43.043); celý pár pohromadě za sekcí 36 jako POHODA
 *
 * Pokud má klasifikační kód `dphdp3_line_secondary` (typicky dovoz služby:
 * ř.12 + ř.43), pak builder generuje DVĚ sekce ze stejné faktury (data se
 * objeví ve dvou tabulkách na PDF — viz reference DPH_LIST_KH 42026.pdf).
 *
 * Periodicita: **měsíční nebo kvartální** (period 'monthly'/'quarterly'; u kvartálu
 * nese $month libovolný měsíc kvartálu, rozsah se natáhne na celé čtvrtletí).
 */
final class DphBookBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * @return array{
     *   period: array{year:int, month:int, period_type:string, quarter:int|null, start:string, end:string, label:string},
     *   supplier: array<string,mixed>,
     *   sections: list<array<string,mixed>>,
     *   totals: array{
     *     issued: array{base:float, vat:float, total:float},
     *     received: array{base:float, vat:float, total:float},
     *     vat_balance: float
     *   }
     * }
     */
    public function build(int $supplierId, int $year, int $month, ?string $period = null): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $period = in_array($period, ['monthly', 'quarterly'], true) ? $period : 'monthly';
        // Kvartální období: $month nese (jako u DPH přiznání) libovolný měsíc kvartálu;
        // kvartál odvodíme přes ceil($month/3) a rozsah natáhneme na celé čtvrtletí.
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;
        if ($quarter !== null) {
            $start = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarter * 3)))
                ->modify('last day of this month')->format('Y-m-d');
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        }

        // Konstanty pro rok období (číselník daňových konstant, admin override).
        $khItemThreshold = $this->taxConstants->khItemThreshold($year);
        $vatBucket = $this->taxConstants->vatBucketThreshold($year);

        // Kanonické řádky ze sdílené VatLedgerService (vč. draftů — Kniha je pracovní
        // žurnál). Seskupíme per (doklad, kód, sazba) do jednoho řádku přehledu a
        // zařadíme do sekcí dle dphdp3_line (+ mirror 43, + ř.47 majetek).
        $sections = [];
        foreach ($this->groupLedgerRows($supplierId, $start, $end) as $g) {
            $scope = $g['source'] === 'sale' ? 'issued' : 'received';
            $line = $g['dphdp3_line'];
            if ($line === null) {
                // Kód s vědomě prázdným řádkem (např. 42 "bez nároku na odpočet") do
                // Knihy DPH nepatří — přeskočíme. Fallback dle sazby použij jen pro
                // doklady úplně bez klasifikace (chybějící, ne vyloučená).
                if (($g['code'] ?? '') !== '') {
                    continue;
                }
                $line = $g['vat_rate'] >= $vatBucket
                    ? ($g['source'] === 'sale' ? '1' : '40')
                    : ($g['vat_rate'] > 0 ? ($g['source'] === 'sale' ? '2' : '41') : null);
            }
            $cls = [
                'code'                  => $g['code'] ?? '',
                'label'                 => $g['label'] !== '' ? $g['label'] : '(bez klasifikace)',
                'dphdp3_line'           => $line,
                'dphdp3_line_secondary' => $g['dphdp3_line_secondary'],
                'kh_section'            => $this->effectiveKhSection($g, $khItemThreshold),
                'vat_rate'              => $g['vat_rate'],
            ];
            $row = $this->toBookRow($g);
            $this->addToSection($sections, $scope, $cls, $row);

            // Secondary (ř.43/44 mirror odpočet u RC / dovozu služby). U plnění bez nároku
            // na odpočet ('none', § 72/4) se mirror POTLAČÍ — konzistentně s DPHDP3
            // (VatClassificationMapper) a KH: výstupní samovyměření zůstává, odpočet ne.
            if (!empty($cls['dphdp3_line_secondary']) && empty($g['vat_deduction_none'])) {
                $this->addToSection($sections, $scope, array_merge($cls, [
                    'dphdp3_line'           => $cls['dphdp3_line_secondary'],
                    'dphdp3_line_secondary' => null,
                    'is_secondary'          => true,
                ]), $row);
            }
            // ř.47 — hodnota pořízeného majetku (primary nebo secondary v 40-45).
            if ($g['is_fixed_asset']) {
                $p = (int) ($cls['dphdp3_line'] ?? 0);
                $s = (int) ($cls['dphdp3_line_secondary'] ?? 0);
                if (($p >= 40 && $p <= 45) || ($s >= 40 && $s <= 45)) {
                    $this->addToSection($sections, $scope, array_merge($cls, [
                        'dphdp3_line'           => '47',
                        'dphdp3_line_secondary' => null,
                        'is_secondary'          => true,
                    ]), $row);
                }
            }
        }

        // Convert sections asociativní mapy → indexované pole, seřazené.
        $sectionList = array_values($sections);
        usort($sectionList, function ($a, $b) {
            // Vzestupně dle čísla členění (15 přijatá → 36 uskutečněná → 43 mirror
            // → 47 majetek) — stejně řadí POHODA (reference DPH_LIST_KH 42026.pdf),
            // ať Kniha DPH sedí vedle výstupu účetní bez přeskakování.
            $oa = $this->sectionOrder($a['key']);
            $ob = $this->sectionOrder($b['key']);
            if ($oa !== $ob) return $oa <=> $ob;
            return strcmp($a['key'], $b['key']);
        });

        // Per-sekce subtotal + global totals.
        //
        // Totals JE NUTNÉ držet odděleně pro uskutečněná (daň na výstupu, sekce 36)
        // a přijatá plnění (odpočet na vstupu, sekce 15) — sčítat je dohromady nedává
        // účetní smysl. Výsledná bilance DPH = daň na výstupu − odpočet na vstupu
        // (kladná = vlastní daňová povinnost, záporná = nadměrný odpočet).
        $issued   = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        $received = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        foreach ($sectionList as &$s) {
            // Řádky uvnitř sekce dle interního čísla dokladu (natural sort) —
            // stejně řadí POHODA (PF 31 před PF 33 i při pozdějším datu plnění);
            // doklady bez čísla (drafty) nakonec, fallback datum plnění + id.
            usort($s['rows'], static function (array $a, array $b): int {
                $da = (string) ($a['doc_number'] ?? '');
                $db = (string) ($b['doc_number'] ?? '');
                if ($da !== '' && $db !== '') {
                    $c = strnatcasecmp($da, $db);
                    if ($c !== 0) return $c;
                } elseif ($da === '' || $db === '') {
                    if (($da === '') !== ($db === '')) {
                        return $da === '' ? 1 : -1;
                    }
                }
                return [(string) ($a['tax_date'] ?? ''), (int) $a['invoice_id']]
                    <=> [(string) ($b['tax_date'] ?? ''), (int) $b['invoice_id']];
            });

            $sb = $sv = $st = 0.0;
            foreach ($s['rows'] as $row) {
                $sb += (float) $row['base'];
                $sv += (float) $row['vat'];
                $st += (float) $row['total'];
            }
            $s['subtotal_base']  = $sb;
            $s['subtotal_vat']   = $sv;
            $s['subtotal_total'] = $st;
            // Bilance DPH = daň na výstupu − odpočet na vstupu. Bucket dle ČÍSLA ŘÁDKU
            // DPHDP3, ne dle prefixu sekce ani is_secondary:
            //   - ř. < 40  = výstup (prodej ř.1/2 I samovyměření reverse charge ř.3–13),
            //   - ř. ≥ 40  = odpočet na vstupu (ř.40/41/42 + zrcadlo ř.43),
            //   - ř. 47    = jen doplňující údaj o hodnotě majetku → mimo bilanci.
            // Tím se reverse charge ve „Výsledné DPH" SPRÁVNĚ vyruší (samovyměřená daň
            // ř.3–13 na výstupu +X, zrcadlový odpočet ř.43 −X = 0) a Kniha sedí s DPH
            // přiznáním. Dřív padal RC primární řádek (43.0xx) do `received`, čímž se
            // bilance o samovyměřenou daň podhodnocovala.
            $lineNo = (int) $s['dphdp3_line'];
            if ($lineNo !== 47) {
                $bucket = $lineNo > 0
                    ? ($lineNo < 40 ? 'issued' : 'received')
                    : (str_starts_with($s['key'], '36.') ? 'issued' : 'received');
                ${$bucket}['base']  += $sb;
                ${$bucket}['vat']   += $sv;
                ${$bucket}['total'] += $st;
            }
        }
        unset($s);

        $label = (new \DateTimeImmutable($start))->format('d.m.Y') . ' - ' . (new \DateTimeImmutable($end))->format('d.m.Y');

        return [
            'period' => [
                'year'        => $year,
                'month'       => $month,
                'period_type' => $period,
                'quarter'     => $quarter,
                'start'       => $start,
                'end'         => $end,
                'label'       => $label,
            ],
            'supplier' => $supplier,
            'sections' => $sectionList,
            'totals' => [
                'issued'      => $issued,
                'received'    => $received,
                // DPH na výstupu − odpočet na vstupu.
                'vat_balance' => $issued['vat'] - $received['vat'],
            ],
        ];
    }

    /**
     * Kanonické řádky ze služby seskupené per (zdroj, doklad, kód, sazba) — jeden
     * řádek přehledu Knihy DPH. Vč. draftů.
     *
     * @return list<array<string,mixed>>
     */
    private function groupLedgerRows(int $supplierId, string $start, string $end): array
    {
        $grouped = [];
        foreach ($this->ledger->rows($supplierId, $start, $end, includeDrafts: true) as $r) {
            $key = $r['source'] . ':' . $r['invoice_id'] . ':' . ($r['code'] ?? '') . ':' . $r['vat_rate'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = $r;
                $grouped[$key]['base_czk'] = 0.0;
                $grouped[$key]['vat_czk'] = 0.0;
            }
            $grouped[$key]['base_czk'] += (float) $r['base_czk'];
            $grouped[$key]['vat_czk']  += (float) $r['vat_czk'];
        }
        return array_values($grouped);
    }

    /**
     * Mapuje kanonický (seskupený) řádek na řádek přehledu Knihy DPH (PDF/UI shape).
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private function toBookRow(array $g): array
    {
        $base = (float) $g['base_czk'];
        $vat = (float) $g['vat_czk'];
        return [
            'invoice_id'              => (int) $g['invoice_id'],
            'direction'               => $g['source'] === 'sale' ? 'issued' : 'received',
            'doc_number'              => $g['doc_number'],
            'original_doc_number'     => $g['source'] === 'purchase' ? $g['vendor_invoice_number'] : null,
            'tax_date'                => $g['tax_date'],
            'accounting_date'         => $g['issue_date'],
            'description'             => (string) ($g['description'] ?? ''),
            'counterparty_name'       => (string) ($g['counterparty_name'] ?? ''),
            'counterparty_dic'        => (string) ($g['counterparty_dic'] ?? ''),
            'vat_classification_code' => $g['code'],
            'vat_rate'                => (float) $g['vat_rate'],
            'currency'                => (string) $g['currency'],
            'exchange_rate'           => (float) $g['exchange_rate'],
            'base'                    => $base,
            'vat'                     => $vat,
            'total'                   => $base + $vat,
            'status'                  => (string) $g['status'],
            'is_draft'                => (bool) $g['is_draft'],
            'is_fixed_asset'          => (bool) $g['is_fixed_asset'],
        ];
    }

    /**
     * Připojí row do správné sekce, vytvoří sekci pokud neexistuje.
     *
     * @param array<string,array<string,mixed>> $sections by-ref
     * @param array<string,mixed> $cls
     * @param array<string,mixed> $row
     */
    private function addToSection(array &$sections, string $directionScope, array $cls, array $row): void
    {
        $sectionPrefix = $directionScope === 'issued' ? '36' : '15';
        // RC/dovozový pár (samovyměření + mirror odpočet ř.43) patří CELÝ pod
        // členění 43 — POHODA (reference DPH_LIST_KH 42026.pdf) řadí "43 ř.012"
        // i "43 ř.043" až ZA sekci 36, ne mezi přijaté 15.xxx. Primary řádek
        // páru proto dostává stejný prefix jako jeho mirror.
        if ($cls['dphdp3_line'] === '43'
            || ($directionScope === 'received' && !empty($cls['dphdp3_line_secondary']))) {
            $sectionPrefix = '43';
        }
        // Sekce ř.47 = doplňující údaj o hodnotě pořízeného majetku
        if ($cls['dphdp3_line'] === '47') {
            $sectionPrefix = '47';
        }
        $line = $cls['dphdp3_line'] ?: '000';
        $linePadded = str_pad($line, 3, '0', \STR_PAD_LEFT);
        // Sekce klíč: NN.LLL (NN=15/36/43, LLL=padded line). Sazba je v label.
        $key = $sectionPrefix . '.' . $linePadded;

        if (!isset($sections[$key])) {
            $sections[$key] = [
                'key'             => $key,
                'direction'       => $directionScope === 'issued' ? 'USKUTEČNĚNÁ' : 'PŘIJATÁ',
                'label'           => $this->buildSectionLabel($sectionPrefix, $line, (float) $cls['vat_rate'], $directionScope),
                'dphdp3_line'     => $line,
                'vat_rate'        => (float) $cls['vat_rate'],
                'is_secondary'    => !empty($cls['is_secondary']),
                'rows'            => [],
                'subtotal_base'   => 0.0,
                'subtotal_vat'    => 0.0,
                'subtotal_total'  => 0.0,
            ];
        }
        // Doplň KH section do row (zobrazuje se v poslední koloně PDF: A.4, B.2, …)
        $rowWithKh = array_merge($row, [
            'kh_section' => $cls['kh_section'],
        ]);
        $sections[$key]['rows'][] = $rowWithKh;
    }

    /**
     * Label sekce — paste-and-modify ze stylu reference PDF:
     *   "15 ř.040 - PŘIJATÁ: Z tuzemska - sazba 21 %"
     *   "36 ř.001 - USKUTEČNĚNÁ: Základ daně"
     *   "43 ř.012 - PŘIJATÁ: Z dovozu služby - sazba"
     *   "43 ř.043 - PŘIJATÁ: Z dovozu služby - sazba"
     */
    private function buildSectionLabel(string $prefix, string $line, float $vatRate, string $directionScope): string
    {
        $direction = $directionScope === 'issued' ? 'USKUTEČNĚNÁ' : 'PŘIJATÁ';
        $rateLabel = $vatRate > 0 ? sprintf(' %g %%', $vatRate) : '';
        if ($prefix === '36') {
            // Vystavené: "Základ daně" nebo sazba podle řádku
            $what = ($line === '1' || $line === '2') ? 'Základ daně' : 'Plnění';
            return sprintf('%s ř.%s - %s: %s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what . $rateLabel);
        }
        if ($prefix === '43') {
            // Pod 43 jsou primary řádky RC/dovozových párů (ř.3/7/10/12 …) i mirror
            // odpočet ř.43 — popis dle řádku, stejně jako POHODA u svého členění.
            $what = match ($line) {
                '5', '6'   => 'Přijetí služby z EU - sazba',
                '12', '13' => 'Z dovozu služby - sazba',
                '3', '4'   => 'Pořízení z EU',
                '7'        => 'Dovoz zboží ze 3. země',
                '10', '11' => 'Tuzemský reverse charge - sazba',
                default    => 'Z reverse charge / dovozu služby - sazba',
            };
            return sprintf('%s ř.%s - %s: %s%s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what, $rateLabel);
        }
        if ($prefix === '47') {
            return sprintf('%s ř.%s - PŘIJATÁ: Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT));
        }
        // 15.XXX = přijaté
        // Speciální popisy pro 40/41 (tuzemsko), 12 (dovoz služby), 7 (dovoz zboží), atd.
        $what = match ($line) {
            '40', '41', '42' => 'Z tuzemska - sazba',
            '12'             => 'Z dovozu služby - sazba',
            '7'              => 'Dovoz zboží ze 3. země',
            '3', '4'         => 'Pořízení z EU',
            default          => 'Plnění',
        };
        return sprintf('%s ř.%s - %s: %s%s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what, $rateLabel);
    }

    /**
     * Efektivní KH sekce pro sloupec Knihy DPH. A.4/A.5 a B.2/B.3 NEJSOU
     * vlastnost klasifikačního kódu, ale dokladu: rozhoduje celková hodnota
     * dokladu vč. DPH (limit 10 000 Kč) a DIČ protistrany — stejná logika jako
     * KontrolniHlaseniBuilder::collectSections. Číselník nese jen statický
     * default (kód 40 → "B.2"), takže by Kniha ukazovala B.2 i u drobných
     * dokladů, které v KH reálně jdou do sumace B.3 (POHODA tiskne efektivní
     * sekci — reference DPH_LIST_KH 42026.pdf: 2026-0010 → B.2, zbytek B.3).
     * A.2 se naopak NEPŘEPOČÍTÁVÁ podle dodavatele: plnění od osoby neusazené
     * v tuzemsku tam patří i bez EU DIČ, jen s prázdnou identifikací (issue #53,
     * {@see KontrolniHlaseniBuilder::a2Identification()}). Kniha proto u dokladu
     * ze 3. země tiskne A.2 — stejně jako výkaz.
     * Ostatní sekce (A.1, A.2, B.1, NULL) se nepřepočítávají.
     *
     * @param array<string,mixed> $g kanonický (seskupený) řádek ledgeru
     * @param float $itemThreshold limit KH pro rok období (číselník daňových konstant)
     */
    private function effectiveKhSection(array $g, float $itemThreshold): ?string
    {
        $kh = $g['kh_section'] ?? null;
        if (!in_array($kh, ['A.4', 'A.5', 'B.2', 'B.3'], true)) {
            return $kh;
        }
        // § 101e: „nad 10 000 Kč" = OSTŘE více → přesně 10 000 jde do sumace (A.5/B.3),
        // ne jednotlivě (A.4/B.2). Proto '>' (ne '>='), shodně s KontrolniHlaseniBuilder.
        $itemized = KontrolniHlaseniBuilder::cleanDic($g['counterparty_dic'] ?? null) !== ''
            && abs((float) $g['total_with_vat_czk']) > $itemThreshold;
        return str_starts_with($kh, 'A.')
            ? ($itemized ? 'A.4' : 'A.5')
            : ($itemized ? 'B.2' : 'B.3');
    }

    private function sectionOrder(string $key): int
    {
        // Vzestupně dle čísla členění jako POHODA: 15.XXX přijaté (0),
        // 36.XXX vystavené (1), 43.XXX RC/dovozové páry vč. primary (2),
        // 47.XXX majetek (3).
        $prefix = substr($key, 0, 2);
        return match ($prefix) {
            '15' => 0,
            '36' => 1,
            '43' => 2,
            '47' => 3,
            default => 9,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }
}
