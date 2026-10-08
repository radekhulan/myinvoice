<?php

declare(strict_types=1);

namespace MyInvoice\Service\Crm;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\OverduePolicy;

/**
 * CRM dashboard aggregation queries.
 *
 * Čte z `crm_monthly_summary` (pre-aggregated přes sp_recompute_crm_monthly_summary).
 * Plus live queries pro top klienti/vendoři (z invoices/purchase_invoices direct).
 *
 * Period filters:
 *   - 'current_month' / 'last_month' / 'ytd' (year-to-date) / 'last_12m'
 *
 * Multi-currency: vrací breakdown per currency. UI nabídne CurrencyPicker.
 */
final class CrmAggregationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly OverduePolicy $overduePolicy,
    ) {}

    // ── Sjednocená metodika s Tržbami/Náklady (Stats/PurchaseSummary) ──────────
    // Tržby/náklady/zisk se prezentují BEZ DPH pro plátce (DPH je průběžná položka),
    // GROSS jen pro neplátce. Datová báze = DUZP s fallbackem na vystavení, ať
    // období sedí s „Obratem" ve Tržbách. Cash metriky (cashflow, aging) zůstávají
    // gross — řeší se vlastními dotazy níže.
    /** Datum pro zařazení tržby do období (DUZP, fallback vystavení) — jako Stats. */
    private const REV_DATE  = "COALESCE(i.tax_date, i.issue_date)";
    /** Datum nákladu (pozdější z DUZP/vystavení) — jako Náklady. */
    private const COST_DATE = "GREATEST(COALESCE(pi.tax_date, pi.issue_date), pi.issue_date)";
    private const REV_STATUS  = "('issued', 'sent', 'reminded', 'paid')";
    // tax_document = daňový doklad k přijaté platbě (#89): patří do tržeb; finál
    // k záloze pak nese jen zbytek (záporné odpočtové řádky) → žádné dvojí započtení.
    private const REV_TYPES   = "('invoice', 'credit_note', 'tax_document')";
    private const COST_STATUS = "('received', 'booked', 'paid')";

    /** Je dodavatel plátce DPH? Určuje net (plátce) vs gross (neplátce) bázi. */
    private function isVatPayer(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Zálohovou (advance) přijatou fakturu vyřaď z nákladů, pokud není zaplacená
     * NEBO je spárovaná s vyúčtovací fakturou (proti dvojímu započtení) — shodně
     * s PurchaseSummaryAction::advanceCostExclude.
     */
    private function advanceCostExclude(): string
    {
        return " AND NOT (COALESCE(pi.document_kind, '') = 'advance'"
             . " AND (pi.status <> 'paid'"
             . " OR EXISTS (SELECT 1 FROM purchase_invoices adv_s"
             . " WHERE adv_s.advance_purchase_invoice_id = pi.id)))";
    }

    /** @return array<string,float|int> nulový akumulátor pro merge tržeb a nákladů per měna */
    private function zeroAcc(): array
    {
        return ['rg' => 0.0, 'rn' => 0.0, 'rgc' => 0.0, 'rnc' => 0.0, 'ic' => 0,
                'cg' => 0.0, 'cn' => 0.0, 'cgc' => 0.0, 'cnc' => 0.0, 'pc' => 0];
    }

    /** Sestaví KPI řádek z akumulátoru — vybere net (plátce) / gross (neplátce) do hlavních polí. */
    private function buildKpi(string $currency, array $a, bool $payer, ?string $period): array
    {
        $rev     = $payer ? $a['rn']  : $a['rg'];
        $revCzk  = $payer ? $a['rnc'] : $a['rgc'];
        $cost    = $payer ? $a['cn']  : $a['cg'];
        $costCzk = $payer ? $a['cnc'] : $a['cgc'];
        return [
            'period'          => $period,
            'currency'        => $currency,
            'revenue'         => $rev,
            'revenue_net'     => $a['rn'],
            'costs'           => $cost,
            'costs_net'       => $a['cn'],
            'profit'          => $rev - $cost,
            'revenue_czk'     => $revCzk,
            'revenue_net_czk' => $a['rnc'],
            'costs_czk'       => $costCzk,
            'costs_net_czk'   => $a['cnc'],
            'profit_czk'      => $revCzk - $costCzk,
            'invoice_count'   => $a['ic'],
            'purchase_count'  => $a['pc'],
            'vat_output'      => $a['rg'] - $a['rn'],
            'vat_input'       => $a['cg'] - $a['cn'],
        ];
    }

    /**
     * Živá agregace tržeb + nákladů za půlotevřený interval [$from, $toExcl) per měna.
     * Net pro plátce, gross pro neplátce; stejné predikáty i datová báze jako stránky
     * Tržby (revenue) a Náklady (costs) → čísla sedí mezi sekcemi.
     *
     * @return list<array<string,mixed>>
     */
    private function aggregateRange(int $supplierId, bool $payer, string $from, string $toExcl, ?string $period = null): array
    {
        $pdo = $this->db->pdo();
        $acc = [];

        $rev = $pdo->prepare(
            "SELECT cur.code AS currency,
                    SUM(COALESCE(i.total_with_vat, 0))    AS g,
                    SUM(COALESCE(i.total_without_vat, 0)) AS n,
                    SUM(COALESCE(i.total_with_vat, 0)    * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS gc,
                    SUM(COALESCE(i.total_without_vat, 0) * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS nc,
                    COUNT(*) AS cnt
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND " . self::REV_DATE . " >= ? AND " . self::REV_DATE . " < ?
                AND i.status IN " . self::REV_STATUS . "
                AND i.invoice_type IN " . self::REV_TYPES . "
           GROUP BY cur.code"
        );
        $rev->execute([$supplierId, $from, $toExcl]);
        foreach ($rev->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $c = (string) $r['currency'];
            $acc[$c] = $acc[$c] ?? $this->zeroAcc();
            $acc[$c]['rg'] = (float) $r['g'];  $acc[$c]['rn'] = (float) $r['n'];
            $acc[$c]['rgc'] = (float) $r['gc']; $acc[$c]['rnc'] = (float) $r['nc'];
            $acc[$c]['ic'] = (int) $r['cnt'];
        }

        $cost = $pdo->prepare(
            "SELECT cur.code AS currency,
                    SUM(COALESCE(pi.total_with_vat, 0))    AS g,
                    SUM(COALESCE(pi.total_without_vat, 0)) AS n,
                    SUM(COALESCE(pi.total_with_vat, 0)    * COALESCE(IF(cur.code='CZK',1,pi.exchange_rate),1)) AS gc,
                    SUM(COALESCE(pi.total_without_vat, 0) * COALESCE(IF(cur.code='CZK',1,pi.exchange_rate),1)) AS nc,
                    COUNT(*) AS cnt
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND " . self::COST_DATE . " >= ? AND " . self::COST_DATE . " < ?
                AND pi.status IN " . self::COST_STATUS . $this->advanceCostExclude() . "
           GROUP BY cur.code"
        );
        $cost->execute([$supplierId, $from, $toExcl]);
        foreach ($cost->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $c = (string) $r['currency'];
            $acc[$c] = $acc[$c] ?? $this->zeroAcc();
            $acc[$c]['cg'] = (float) $r['g'];  $acc[$c]['cn'] = (float) $r['n'];
            $acc[$c]['cgc'] = (float) $r['gc']; $acc[$c]['cnc'] = (float) $r['nc'];
            $acc[$c]['pc'] = (int) $r['cnt'];
        }

        ksort($acc);
        $out = [];
        foreach ($acc as $c => $a) {
            $out[] = $this->buildKpi((string) $c, $a, $payer, $period);
        }
        return $out;
    }

    /**
     * SQL predikát pro pohledávkové doklady (co nám klienti dluží), alias `i`.
     * Kromě ostrých faktur a dobropisů zahrnuje i NEZAPLACENÉ NESPÁROVANÉ proformy
     * (proforma bez dceřiného ostrého daňového dokladu) — ty jsou reálný dluh.
     * Spárovaná proforma se vynechá, aby se dluh nepočítal dvakrát (nese ho ostrý doklad).
     * Kombinuj VŽDY se statusem IN ('issued','sent','reminded') — vyřadí zaplacené/storno.
     */
    private function receivableDocTypeSql(): string
    {
        // Zachovej původní množinu (vše kromě proforem) a NAVÍC přidej nespárované
        // proformy (bez dceřiného ostrého daňového dokladu) — ty jsou reálný dluh.
        // Záměrně `!= 'proforma'` (ne IN-list), aby se nezahodily případné jiné typy
        // (cancellation apod.), které sem padaly i dosud. Kombinuj se status filtrem.
        return "(i.invoice_type != 'proforma'"
             . " OR NOT EXISTS (SELECT 1 FROM invoices ch"
             . " WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice'))";
    }

    /**
     * Volá sp_recompute_crm_monthly_summary pro daný tenant.
     * Manuálně z admin UI nebo z cron jobu.
     */
    public function recompute(int $supplierId): void
    {
        $this->db->pdo()->prepare('CALL sp_recompute_crm_monthly_summary(?)')->execute([$supplierId]);
    }

    /**
     * Lazy recompute — pokud je crm_monthly_summary starší než $maxAgeSec sekund
     * (nebo prázdná), spustí sp_recompute_crm_monthly_summary.
     * Default 300s = 5 min staleness window.
     */
    public function recomputeIfStale(int $supplierId, int $maxAgeSec = 300): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(computed_at) FROM crm_monthly_summary WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $lastComputed = $stmt->fetchColumn();
        $needsRecompute = $lastComputed === null || $lastComputed === false
            || (time() - (int) strtotime((string) $lastComputed)) > $maxAgeSec;
        if ($needsRecompute) {
            $this->recompute($supplierId);
            return true;
        }
        return false;
    }

    /**
     * Overview KPI (per currency):
     *   - current_month / last_month — aktuální a minulý měsíc
     *   - ytd / prev_year_ytd        — od začátku roku + stejné okno loni (fair YoY)
     *   - last_12m / prev_12m        — klouzavých 12 měsíců + předchozích 12 (trailing YoY)
     *   - prev_year_full             — celý předchozí kalendářní rok
     *
     * @return array{
     *   current_month: array<int, array<string,mixed>>,
     *   last_month: array<int, array<string,mixed>>,
     *   ytd: array<int, array<string,mixed>>,
     *   last_12m: array<int, array<string,mixed>>,
     *   prev_12m: array<int, array<string,mixed>>,
     *   prev_year_full: array<int, array<string,mixed>>,
     *   prev_year_ytd: array<int, array<string,mixed>>,
     *   current_month_pipeline: array<int, array<string,mixed>>,
     *   currencies: list<string>
     * }
     */
    public function overview(int $supplierId): array
    {
        $payer = $this->isVatPayer($supplierId);
        $d = static fn (\DateTimeImmutable $x): string => $x->format('Y-m-d');

        $today          = new \DateTimeImmutable('today');
        $tomorrow       = $today->modify('+1 day');
        $firstThisMonth = $today->modify('first day of this month');
        $firstNextMonth = $firstThisMonth->modify('+1 month');
        $firstPrevMonth = $firstThisMonth->modify('-1 month');
        $first12        = $firstThisMonth->modify('-11 months'); // trailing 12 kal. měsíců vč. aktuálního
        $first24        = $firstThisMonth->modify('-23 months');

        $year     = (int) $today->format('Y');
        $yearStart     = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $prevYearStart = new \DateTimeImmutable(sprintf('%04d-01-01', $year - 1));
        $prevYearYtdEnd = $tomorrow->modify('-1 year'); // loni do stejného dne (půlotevřeně)

        return [
            'current_month'  => $this->aggregateRange($supplierId, $payer, $d($firstThisMonth), $d($firstNextMonth), $firstThisMonth->format('Y-m')),
            'last_month'     => $this->aggregateRange($supplierId, $payer, $d($firstPrevMonth), $d($firstThisMonth), $firstPrevMonth->format('Y-m')),
            'ytd'            => $this->aggregateRange($supplierId, $payer, $d($yearStart), $d($tomorrow)),
            'last_12m'       => $this->aggregateRange($supplierId, $payer, $d($first12), $d($firstNextMonth)),
            'prev_12m'       => $this->aggregateRange($supplierId, $payer, $d($first24), $d($first12)),
            'prev_year_full' => $this->aggregateRange($supplierId, $payer, $d($prevYearStart), $d($yearStart)),
            'prev_year_ytd'  => $this->aggregateRange($supplierId, $payer, $d($prevYearStart), $d($prevYearYtdEnd)),
            'current_month_pipeline' => $this->currentMonthPipeline($supplierId, $payer, $d($firstThisMonth), $d($firstNextMonth)),
            'currencies'     => $this->listCurrencies($supplierId),
        ];
    }

    /**
     * Dopředné („pipeline") tržby aktuálního měsíce, které JEŠTĚ NEjsou v ostrých tržbách
     * ({@see aggregateRange}) — doplňují dlaždici „Tržby tento měsíc" o očekávané příjmy:
     *   - koncepty:           vydané faktury ve stavu draft (invoice/credit_note/tax_document)
     *   - nespárované proformy: otevřené proformy bez navazujícího ostrého daňového dokladu
     *                           (stejná „unmatched" sémantika jako {@see receivableDocTypeSql})
     *
     * Net pro plátce, gross pro neplátce (shodně s revenue). Per měna + CZK přepočet
     * (revenue_czk pole) pro agregaci „Vše" na klientovi.
     *
     * @return list<array{currency:string, draft_revenue:float, draft_revenue_czk:float,
     *                    draft_count:int, proforma_revenue:float, proforma_revenue_czk:float,
     *                    proforma_count:int}>
     */
    private function currentMonthPipeline(int $supplierId, bool $payer, string $from, string $toExcl): array
    {
        $pdo = $this->db->pdo();
        $zero = static fn (): array => ['dg' => 0.0, 'dn' => 0.0, 'dgc' => 0.0, 'dnc' => 0.0, 'dc' => 0,
                                        'pg' => 0.0, 'pn' => 0.0, 'pgc' => 0.0, 'pnc' => 0.0, 'pc' => 0];
        $acc = [];

        // Koncepty — vydané faktury ve stavu draft (proformy řeší druhý dotaz zvlášť).
        $draft = $pdo->prepare(
            "SELECT cur.code AS currency,
                    SUM(COALESCE(i.total_with_vat,0))    AS g, SUM(COALESCE(i.total_without_vat,0)) AS n,
                    SUM(COALESCE(i.total_with_vat,0)    * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS gc,
                    SUM(COALESCE(i.total_without_vat,0) * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS nc,
                    COUNT(*) AS cnt
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND " . self::REV_DATE . " >= ? AND " . self::REV_DATE . " < ?
                AND i.status = 'draft'
                AND i.invoice_type IN " . self::REV_TYPES . "
           GROUP BY cur.code"
        );
        $draft->execute([$supplierId, $from, $toExcl]);
        foreach ($draft->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $c = (string) $r['currency'];
            $acc[$c] = $acc[$c] ?? $zero();
            $acc[$c]['dg'] = (float) $r['g'];  $acc[$c]['dn'] = (float) $r['n'];
            $acc[$c]['dgc'] = (float) $r['gc']; $acc[$c]['dnc'] = (float) $r['nc'];
            $acc[$c]['dc'] = (int) $r['cnt'];
        }

        // Nespárované proformy — otevřené (issued/sent/reminded) bez dceřiného ostrého dokladu.
        $prof = $pdo->prepare(
            "SELECT cur.code AS currency,
                    SUM(COALESCE(i.total_with_vat,0))    AS g, SUM(COALESCE(i.total_without_vat,0)) AS n,
                    SUM(COALESCE(i.total_with_vat,0)    * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS gc,
                    SUM(COALESCE(i.total_without_vat,0) * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS nc,
                    COUNT(*) AS cnt
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND " . self::REV_DATE . " >= ? AND " . self::REV_DATE . " < ?
                AND i.invoice_type = 'proforma'
                AND i.status IN ('issued', 'sent', 'reminded')
                AND NOT EXISTS (SELECT 1 FROM invoices ch
                                 WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice')
           GROUP BY cur.code"
        );
        $prof->execute([$supplierId, $from, $toExcl]);
        foreach ($prof->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $c = (string) $r['currency'];
            $acc[$c] = $acc[$c] ?? $zero();
            $acc[$c]['pg'] = (float) $r['g'];  $acc[$c]['pn'] = (float) $r['n'];
            $acc[$c]['pgc'] = (float) $r['gc']; $acc[$c]['pnc'] = (float) $r['nc'];
            $acc[$c]['pc'] = (int) $r['cnt'];
        }

        ksort($acc);
        $out = [];
        foreach ($acc as $c => $a) {
            $out[] = [
                'currency'             => (string) $c,
                'draft_revenue'        => $payer ? $a['dn']  : $a['dg'],
                'draft_revenue_czk'    => $payer ? $a['dnc'] : $a['dgc'],
                'draft_count'          => $a['dc'],
                'proforma_revenue'     => $payer ? $a['pn']  : $a['pg'],
                'proforma_revenue_czk' => $payer ? $a['pnc'] : $a['pgc'],
                'proforma_count'       => $a['pc'],
            ];
        }
        return $out;
    }

    /**
     * Měsíční breakdown za posledních N měsíců (default 12). Per currency.
     *
     * @return list<array{period:string, currency:string, revenue:float, costs:float,
     *                    profit:float, invoice_count:int, purchase_count:int}>
     */
    public function monthlyHistory(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $payer = $this->isVatPayer($supplierId);
        $firstThisMonth = (new \DateTimeImmutable('today'))->modify('first day of this month');
        $from   = $firstThisMonth->modify('-' . ($monthsBack - 1) . ' months')->format('Y-m-d');
        $toExcl = $firstThisMonth->modify('+1 month')->format('Y-m-d');
        $pdo = $this->db->pdo();
        $acc = []; // "ym|currency" => zeroAcc

        $rev = $pdo->prepare(
            "SELECT DATE_FORMAT(" . self::REV_DATE . ", '%Y-%m') AS ym, cur.code AS currency,
                    SUM(COALESCE(i.total_with_vat,0)) AS g, SUM(COALESCE(i.total_without_vat,0)) AS n,
                    SUM(COALESCE(i.total_with_vat,0)    * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS gc,
                    SUM(COALESCE(i.total_without_vat,0) * COALESCE(IF(cur.code='CZK',1,i.exchange_rate),1)) AS nc,
                    COUNT(*) AS cnt
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND " . self::REV_DATE . " >= ? AND " . self::REV_DATE . " < ?
                AND i.status IN " . self::REV_STATUS . " AND i.invoice_type IN " . self::REV_TYPES . "
           GROUP BY ym, cur.code"
        );
        $rev->execute([$supplierId, $from, $toExcl]);
        foreach ($rev->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = $r['ym'] . '|' . $r['currency'];
            $acc[$k] = $acc[$k] ?? $this->zeroAcc();
            $acc[$k]['rg'] = (float) $r['g'];  $acc[$k]['rn'] = (float) $r['n'];
            $acc[$k]['rgc'] = (float) $r['gc']; $acc[$k]['rnc'] = (float) $r['nc']; $acc[$k]['ic'] = (int) $r['cnt'];
        }

        $cost = $pdo->prepare(
            "SELECT DATE_FORMAT(" . self::COST_DATE . ", '%Y-%m') AS ym, cur.code AS currency,
                    SUM(COALESCE(pi.total_with_vat,0)) AS g, SUM(COALESCE(pi.total_without_vat,0)) AS n,
                    SUM(COALESCE(pi.total_with_vat,0)    * COALESCE(IF(cur.code='CZK',1,pi.exchange_rate),1)) AS gc,
                    SUM(COALESCE(pi.total_without_vat,0) * COALESCE(IF(cur.code='CZK',1,pi.exchange_rate),1)) AS nc,
                    COUNT(*) AS cnt
               FROM purchase_invoices pi JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND " . self::COST_DATE . " >= ? AND " . self::COST_DATE . " < ?
                AND pi.status IN " . self::COST_STATUS . $this->advanceCostExclude() . "
           GROUP BY ym, cur.code"
        );
        $cost->execute([$supplierId, $from, $toExcl]);
        foreach ($cost->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = $r['ym'] . '|' . $r['currency'];
            $acc[$k] = $acc[$k] ?? $this->zeroAcc();
            $acc[$k]['cg'] = (float) $r['g'];  $acc[$k]['cn'] = (float) $r['n'];
            $acc[$k]['cgc'] = (float) $r['gc']; $acc[$k]['cnc'] = (float) $r['nc']; $acc[$k]['pc'] = (int) $r['cnt'];
        }

        $rows = [];
        foreach ($acc as $k => $a) {
            [$ym, $cur] = explode('|', (string) $k, 2);
            if ($currency !== null && $cur !== $currency) {
                continue;
            }
            $rows[] = $this->buildKpi($cur, $a, $payer, $ym);
        }
        usort($rows, static fn ($x, $y) => ($x['period'] <=> $y['period']) ?: strcmp($x['currency'], $y['currency']));
        return $rows;
    }

    /**
     * Top klienti by revenue za posledních N měsíců.
     *
     * @return list<array{client_id:int, company_name:string, revenue:float,
     *                    invoice_count:int, currency:string, percent_share:float}>
     */
    public function topClients(int $supplierId, int $monthsBack = 12, int $limit = 10, ?string $currency = null): array
    {
        // CZK přepočet přes i.exchange_rate — multi-currency klienti se neroztrhnou na N řádků
        // a ranking je správný napříč měnami (1000 EUR > 20 000 CZK).
        // Parametr $currency zachován pro BC (ignoruje se — vždy ranking v CZK).
        unset($currency);
        // Net pro plátce (DPH se vrací), gross pro neplátce — shodně s ostatními tržbami.
        $rev = $this->isVatPayer($supplierId) ? 'i.total_without_vat' : 'i.total_with_vat';
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-01');
        $params = [$supplierId, $start];
        $sql = "
            SELECT i.client_id, c.company_name,
                   SUM(COALESCE($rev, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_czk,
                   COUNT(*) AS invoice_count,
                   GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                   SUM(SUM(COALESCE($rev, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1))) OVER () AS total_all
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
              JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.issue_date >= ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'
          GROUP BY i.client_id, c.company_name
          ORDER BY revenue_czk DESC
             LIMIT " . (int) $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $rev = (float) $r['revenue_czk'];
            $total = (float) $r['total_all'];
            return [
                'client_id'     => (int) $r['client_id'],
                'company_name'  => (string) $r['company_name'],
                'revenue'       => $rev,
                'invoice_count' => (int) $r['invoice_count'],
                'currency'      => 'CZK',
                'currencies'    => (string) $r['currencies'],
                'percent_share' => $total > 0 ? round(($rev / $total) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Yearly aggregation per currency (revenue + costs + profit + counts).
     * Vrací všechny roky, kde tenant měl aspoň jednu řádku v crm_monthly_summary.
     *
     * @return list<array{year:int, currency:string, revenue:float, costs:float, profit:float, invoice_count:int, purchase_count:int}>
     */
    public function yearlyHistory(int $supplierId, ?string $currency = null): array
    {
        $payer = $this->isVatPayer($supplierId);
        $pdo = $this->db->pdo();
        $acc = []; // "year|currency" => zeroAcc

        $rev = $pdo->prepare(
            "SELECT YEAR(" . self::REV_DATE . ") AS yr, cur.code AS currency,
                    SUM(COALESCE(i.total_with_vat,0)) AS g, SUM(COALESCE(i.total_without_vat,0)) AS n,
                    COUNT(*) AS cnt
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN " . self::REV_STATUS . " AND i.invoice_type IN " . self::REV_TYPES . "
           GROUP BY yr, cur.code"
        );
        $rev->execute([$supplierId]);
        foreach ($rev->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = $r['yr'] . '|' . $r['currency'];
            $acc[$k] = $acc[$k] ?? $this->zeroAcc();
            $acc[$k]['rg'] = (float) $r['g']; $acc[$k]['rn'] = (float) $r['n']; $acc[$k]['ic'] = (int) $r['cnt'];
        }

        $cost = $pdo->prepare(
            "SELECT YEAR(" . self::COST_DATE . ") AS yr, cur.code AS currency,
                    SUM(COALESCE(pi.total_with_vat,0)) AS g, SUM(COALESCE(pi.total_without_vat,0)) AS n,
                    COUNT(*) AS cnt
               FROM purchase_invoices pi JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status IN " . self::COST_STATUS . $this->advanceCostExclude() . "
           GROUP BY yr, cur.code"
        );
        $cost->execute([$supplierId]);
        foreach ($cost->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $k = $r['yr'] . '|' . $r['currency'];
            $acc[$k] = $acc[$k] ?? $this->zeroAcc();
            $acc[$k]['cg'] = (float) $r['g']; $acc[$k]['cn'] = (float) $r['n']; $acc[$k]['pc'] = (int) $r['cnt'];
        }

        $rows = [];
        foreach ($acc as $k => $a) {
            [$yr, $cur] = explode('|', (string) $k, 2);
            if ($currency !== null && $cur !== $currency) {
                continue;
            }
            $rv = $payer ? $a['rn'] : $a['rg'];
            $ct = $payer ? $a['cn'] : $a['cg'];
            $rows[] = [
                'year'           => (int) $yr,
                'currency'       => $cur,
                'revenue'        => $rv,
                'costs'          => $ct,
                'profit'         => $rv - $ct,
                'invoice_count'  => $a['ic'],
                'purchase_count' => $a['pc'],
            ];
        }
        usort($rows, static fn ($x, $y) => ($y['year'] <=> $x['year']) ?: strcmp($x['currency'], $y['currency']));
        return $rows;
    }

    /**
     * Top vendors by costs.
     */
    public function topVendors(int $supplierId, int $monthsBack = 12, int $limit = 10, ?string $currency = null): array
    {
        // CZK přepočet přes pi.exchange_rate — multi-currency vendor se neroztrhne.
        // Parametr $currency zachován pro BC (ignoruje se — vždy ranking v CZK).
        unset($currency);
        // Net pro plátce (vstupní DPH je odpočitatelná), gross pro neplátce — jako Náklady.
        $cost = $this->isVatPayer($supplierId) ? 'pi.total_without_vat' : 'pi.total_with_vat';
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-01');
        $params = [$supplierId, $start];
        $sql = "
            SELECT pi.vendor_id, c.company_name,
                   SUM(COALESCE($cost, 0) * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_czk,
                   COUNT(*) AS purchase_count,
                   GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                   SUM(SUM(COALESCE($cost, 0) * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1))) OVER () AS total_all
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.issue_date >= ?
               AND pi.status NOT IN ('draft', 'cancelled')
               -- Spárovaná/zaplacená záloha (advance) nese náklad finální faktura → vyřadit
               AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                        AND (pi.status = 'paid'
                             OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                         WHERE adv_s.advance_purchase_invoice_id = pi.id)))
          GROUP BY pi.vendor_id, c.company_name
          ORDER BY costs_czk DESC
             LIMIT " . (int) $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $costs = (float) $r['costs_czk'];
            $total = (float) $r['total_all'];
            return [
                'vendor_id'      => (int) $r['vendor_id'],
                'company_name'   => (string) $r['company_name'],
                'costs'          => $costs,
                'purchase_count' => (int) $r['purchase_count'],
                'currency'       => 'CZK',
                'currencies'     => (string) ($r['currencies'] ?? 'CZK'),
                'percent_share'  => $total > 0 ? round(($costs / $total) * 100, 2) : 0.0,
            ];
        }, $rows);
    }

    /**
     * Měny, ve kterých má dodavatel relevantní doklady (vydané tržby + přijaté náklady).
     * Živě z faktur, ať se nabídka měn shoduje s tím, co aggregateRange skutečně vrací.
     *
     * @return list<string>
     */
    private function listCurrencies(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT code FROM (
                SELECT cur.code AS code FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ? AND i.status IN " . self::REV_STATUS . " AND i.invoice_type IN " . self::REV_TYPES . "
                UNION
                SELECT cur.code AS code FROM purchase_invoices pi JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ? AND pi.status IN " . self::COST_STATUS . "
             ) t ORDER BY code"
        );
        $stmt->execute([$supplierId, $supplierId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'code');
    }

    /**
     * Aging buckets pro nezaplacené vystavené faktury.
     * Klasifikuje po splatnosti: not_due, 0-30, 31-60, 61-90, 90+
     *
     * @return list<array{bucket:string, currency:string, count:int, total:float}>
     */
    public function agingReceivables(int $supplierId): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $sql = "
            SELECT
                CASE
                    WHEN i.due_date > ? THEN 'not_due'
                    WHEN DATEDIFF(?, i.due_date) <= 30  THEN 'overdue_30'
                    WHEN DATEDIFF(?, i.due_date) <= 60  THEN 'overdue_60'
                    WHEN DATEDIFF(?, i.due_date) <= 90  THEN 'overdue_90'
                    ELSE 'overdue_90_plus'
                END AS bucket,
                COALESCE(c.code, 'CZK') AS currency,
                COUNT(*) AS cnt,
                SUM(COALESCE(i.amount_to_pay, 0) - COALESCE(i.paid_total, 0)) AS total
              FROM invoices i
         LEFT JOIN currencies c ON c.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.status IN ('issued', 'sent', 'reminded')
               AND " . $this->receivableDocTypeSql() . "
               AND (i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)
          GROUP BY bucket, currency
          ORDER BY currency, FIELD(bucket, 'not_due', 'overdue_30', 'overdue_60', 'overdue_90', 'overdue_90_plus')
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$today, $today, $today, $today, $supplierId]);
        return array_map(fn ($r) => [
            'bucket'   => (string) $r['bucket'],
            'currency' => (string) $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => (float) $r['total'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Aging buckets pro nezaplacené přijaté faktury (závazky).
     */
    public function agingPayables(int $supplierId): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $sql = "
            SELECT
                CASE
                    WHEN pi.due_date > ? THEN 'not_due'
                    WHEN DATEDIFF(?, pi.due_date) <= 30  THEN 'overdue_30'
                    WHEN DATEDIFF(?, pi.due_date) <= 60  THEN 'overdue_60'
                    WHEN DATEDIFF(?, pi.due_date) <= 90  THEN 'overdue_90'
                    ELSE 'overdue_90_plus'
                END AS bucket,
                COALESCE(c.code, 'CZK') AS currency,
                COUNT(*) AS cnt,
                SUM(COALESCE(pi.amount_to_pay, pi.total_with_vat, 0)) AS total
              FROM purchase_invoices pi
         LEFT JOIN currencies c ON c.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.status IN ('received', 'booked')
          GROUP BY bucket, currency
          ORDER BY currency, FIELD(bucket, 'not_due', 'overdue_30', 'overdue_60', 'overdue_90', 'overdue_90_plus')
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$today, $today, $today, $today, $supplierId]);
        return array_map(fn ($r) => [
            'bucket'   => (string) $r['bucket'],
            'currency' => (string) $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => (float) $r['total'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * DSO (Days Sales Outstanding) za posledních N měsíců.
     * Vrátí průměrný počet dní mezi issue_date a paid_at u zaplacených faktur.
     *
     * @return array{avg_days:float, sample_size:int, period_months:int}
     */
    public function daysSalesOutstanding(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) AS avg_days, COUNT(*) AS sample
               FROM invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND issue_date >= ?
                AND invoice_type != 'proforma'"
        );
        $stmt->execute([$supplierId, $start]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'avg_days'      => round((float) ($row['avg_days'] ?? 0), 1),
            'sample_size'   => (int) ($row['sample'] ?? 0),
            'period_months' => $monthsBack,
        ];
    }

    /**
     * Payment punctuality — % faktur zaplacených včas (před nebo na due_date).
     */
    public function paymentPunctuality(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN paid_at <= due_date THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN paid_at >  due_date THEN 1 ELSE 0 END) AS late,
                COUNT(*) AS total
             FROM invoices
            WHERE supplier_id = ?
              AND status = 'paid'
              AND paid_at IS NOT NULL
              AND issue_date >= ?
              AND invoice_type != 'proforma'"
        );
        $stmt->execute([$supplierId, $start]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        return [
            'on_time'        => (int) ($row['on_time'] ?? 0),
            'late'           => (int) ($row['late'] ?? 0),
            'total'          => $total,
            'on_time_pct'    => $total > 0 ? round((((int) $row['on_time']) / $total) * 100, 1) : 0.0,
            'period_months'  => $monthsBack,
        ];
    }

    /**
     * Concentration risk — % share top klienta v revenue.
     * "Pareto" warning: pokud TOP 1 klient > 40 %, jeden klient > 30 %, …
     */
    public function clientConcentration(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $clients = $this->topClients($supplierId, $monthsBack, 50, $currency);
        if (empty($clients)) {
            return ['top1_share' => 0, 'top3_share' => 0, 'top5_share' => 0, 'total_clients' => 0,
                    'pareto_80_count' => 0, 'risk_level' => 'low'];
        }
        // Per currency group — vezmu jen first currency (UI volá per měna)
        $cur = $currency ?? $clients[0]['currency'];
        $filtered = array_values(array_filter($clients, fn ($c) => $c['currency'] === $cur));

        $top1 = $filtered[0]['percent_share'] ?? 0;
        $top3 = array_sum(array_slice(array_column($filtered, 'percent_share'), 0, 3));
        $top5 = array_sum(array_slice(array_column($filtered, 'percent_share'), 0, 5));

        // Pareto — kolik klientů dělá 80%
        $pareto80 = 0;
        $cumul = 0;
        foreach ($filtered as $c) {
            $cumul += $c['percent_share'];
            $pareto80++;
            if ($cumul >= 80) break;
        }

        $riskLevel = $top1 > 40 ? 'high' : ($top1 > 25 ? 'medium' : 'low');

        return [
            'top1_share'      => round($top1, 1),
            'top3_share'      => round($top3, 1),
            'top5_share'      => round($top5, 1),
            'total_clients'   => count($filtered),
            'pareto_80_count' => $pareto80,
            'risk_level'      => $riskLevel,
            'currency'        => $cur,
        ];
    }

    /**
     * Vendor concentration risk — % nákladů z top dodavatele.
     * Analogie clientConcentration(), ale nad přijatými fakturami (závislost na dodavateli).
     *
     * @return array{top1_share: float, top3_share: float, top5_share: float, total_vendors: int,
     *               pareto_80_count: int, risk_level: string, currency: string}
     */
    public function vendorConcentration(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        $vendors = $this->topVendors($supplierId, $monthsBack, 50, $currency);
        if (empty($vendors)) {
            return ['top1_share' => 0, 'top3_share' => 0, 'top5_share' => 0, 'total_vendors' => 0,
                    'pareto_80_count' => 0, 'risk_level' => 'low', 'currency' => 'CZK'];
        }
        $cur = $vendors[0]['currency'];

        $top1 = $vendors[0]['percent_share'] ?? 0;
        $top3 = array_sum(array_slice(array_column($vendors, 'percent_share'), 0, 3));
        $top5 = array_sum(array_slice(array_column($vendors, 'percent_share'), 0, 5));

        // Pareto — kolik dodavatelů dělá 80 % nákladů
        $pareto80 = 0;
        $cumul = 0;
        foreach ($vendors as $v) {
            $cumul += $v['percent_share'];
            $pareto80++;
            if ($cumul >= 80) break;
        }

        $riskLevel = $top1 > 40 ? 'high' : ($top1 > 25 ? 'medium' : 'low');

        return [
            'top1_share'      => round($top1, 1),
            'top3_share'      => round($top3, 1),
            'top5_share'      => round($top5, 1),
            'total_vendors'   => count($vendors),
            'pareto_80_count' => $pareto80,
            'risk_level'      => $riskLevel,
            'currency'        => $cur,
        ];
    }

    /**
     * DPO (Days Payable Outstanding) za posledních N měsíců.
     * Průměrný počet dní mezi vystavením přijaté faktury a její úhradou dodavateli.
     * Doplněk k DSO — spolu dávají pracovní kapitálový cyklus (DSO − DPO).
     *
     * @return array{avg_days: float, sample_size: int, period_months: int}
     */
    public function daysPayableOutstanding(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) AS avg_days, COUNT(*) AS sample
               FROM purchase_invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND issue_date >= ?"
        );
        $stmt->execute([$supplierId, $start]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'avg_days'      => round((float) ($row['avg_days'] ?? 0), 1),
            'sample_size'   => (int) ($row['sample'] ?? 0),
            'period_months' => $monthsBack,
        ];
    }

    /**
     * Expense breakdown po kategoriích (vyžaduje expense_categories assignment).
     *
     * @return list<array{category_id:?int, code:?string, label:?string, total:float, count:int, percent:float}>
     */
    public function expenseBreakdown(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        // CZK přepočet — bez něj sumace EUR+CZK ve stejné kategorii dává nesmysl
        // (100 EUR + 50 000 CZK = 50 100). Parametr $currency BC, vždy CZK.
        unset($currency);
        $cost = $this->isVatPayer($supplierId) ? 'pi.total_without_vat' : 'pi.total_with_vat';
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $params = [$supplierId, $start];
        $sql = "
            SELECT pi.expense_category_id, ec.code, ec.label,
                   SUM(COALESCE($cost, 0) * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS total,
                   COUNT(*) AS cnt
              FROM purchase_invoices pi
         LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.issue_date >= ?
               AND pi.status NOT IN ('draft', 'cancelled')
               -- Spárovaná/zaplacená záloha (advance) nese náklad finální faktura → vyřadit (jako topVendors)
               AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                        AND (pi.status = 'paid'
                             OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                         WHERE adv_s.advance_purchase_invoice_id = pi.id)))
          GROUP BY pi.expense_category_id, ec.code, ec.label
          ORDER BY total DESC
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $sum = array_sum(array_column($rows, 'total'));
        return array_map(fn ($r) => [
            'category_id' => $r['expense_category_id'] !== null ? (int) $r['expense_category_id'] : null,
            'code'        => $r['code'] !== null ? (string) $r['code'] : null,
            'label'       => $r['label'] !== null ? (string) $r['label'] : null,
            'total'       => (float) $r['total'],
            'count'       => (int) $r['cnt'],
            'percent'     => $sum > 0 ? round(((float) $r['total'] / $sum) * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * Revenue breakdown po kategoriích tržeb (vyžaduje revenue_categories assignment).
     * Symetrie k {@see expenseBreakdown} pro vydané faktury.
     *
     * @return list<array{category_id:?int, code:?string, label:?string, total:float, count:int, percent:float}>
     */
    public function revenueBreakdown(int $supplierId, int $monthsBack = 12, ?string $currency = null): array
    {
        // CZK přepočet — bez něj sumace EUR+CZK ve stejné kategorii dává nesmysl.
        // Parametr $currency BC, vždy CZK (jako expenseBreakdown).
        unset($currency);
        $rev = $this->isVatPayer($supplierId) ? 'i.total_without_vat' : 'i.total_with_vat';
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m-d');
        $params = [$supplierId, $start];
        $sql = "
            SELECT i.revenue_category_id, rc.code, rc.label,
                   SUM(COALESCE($rev, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS total,
                   COUNT(*) AS cnt
              FROM invoices i
         LEFT JOIN revenue_categories rc ON rc.id = i.revenue_category_id
         LEFT JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.issue_date >= ?
               AND i.status NOT IN ('draft', 'cancelled')
               AND i.invoice_type != 'proforma'  -- proformy vynechat (nejsou daňový doklad)
          GROUP BY i.revenue_category_id, rc.code, rc.label
          ORDER BY total DESC
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $sum = array_sum(array_column($rows, 'total'));
        return array_map(fn ($r) => [
            'category_id' => $r['revenue_category_id'] !== null ? (int) $r['revenue_category_id'] : null,
            'code'        => $r['code'] !== null ? (string) $r['code'] : null,
            'label'       => $r['label'] !== null ? (string) $r['label'] : null,
            'total'       => (float) $r['total'],
            'count'       => (int) $r['cnt'],
            'percent'     => $sum > 0 ? round(((float) $r['total'] / $sum) * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * Customer churn risk — klienti, kteří neměli fakturu 60+ dní.
     *
     * @return list<array{client_id:int, company_name:string, last_invoice_date:string,
     *                    days_since:int, total_revenue:float, currency:string}>
     */
    public function churnRisk(int $supplierId, int $thresholdDays = 60, int $limit = 20): array
    {
        // GROUP BY jen po klientovi (ne per currency) — multi-currency klient byl rozdělen
        // do N řádků (jedna měna = jedna pozice v listu, partial revenue). CZK přepočet.
        $rev = $this->isVatPayer($supplierId) ? 'i.total_without_vat' : 'i.total_with_vat';
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id AS client_id, c.company_name,
                    MAX(i.issue_date) AS last_invoice_date,
                    DATEDIFF(?, MAX(i.issue_date)) AS days_since,
                    SUM(COALESCE($rev, 0) * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS total_revenue,
                    GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies
               FROM clients c
               JOIN invoices i ON i.client_id = c.id AND i.status NOT IN ('draft', 'cancelled')
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE c.supplier_id = ?
                AND i.invoice_type != 'proforma'
                AND c.is_customer = 1
           GROUP BY c.id, c.company_name
             HAVING DATEDIFF(?, MAX(i.issue_date)) > ?
           ORDER BY days_since ASC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$today, $supplierId, $today, $thresholdDays]);
        return array_map(fn ($r) => [
            'client_id'         => (int) $r['client_id'],
            'company_name'      => (string) $r['company_name'],
            'last_invoice_date' => (string) $r['last_invoice_date'],
            'days_since'        => (int) $r['days_since'],
            'total_revenue'     => (float) $r['total_revenue'],
            'currency'          => 'CZK',
            'currencies'        => (string) ($r['currencies'] ?? 'CZK'),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Action items widget — daily TODO list pro user.
     *
     * Pokud je předán $userId, aplikuje per-user dismissals (day/week/forever/historical).
     * Pro mode=historical: vrátí item jen pokud existuje NOVÉ id, které není v baseline.
     *
     * @return array{
     *   items: list<array{type:string, severity:string, title:string, hint:string, link:string, count?:int, days?:int}>,
     *   total: int
     * }
     */
    public function actionItems(
        int $supplierId,
        ?int $userId = null,
        ?\DateTimeImmutable $now = null,
        ?string $userRole = null,
    ): array {
        $items = [];
        $pdo = $this->db->pdo();
        $nowDt = $now ?? new \DateTimeImmutable();
        $today = $nowDt->format('Y-m-d');

        // Load dismissals once
        $dismissals = $this->loadDismissals($supplierId, $userId);

        // 0. Přechod na MyÚčto — nástupce MyInvoice od stejného autora.
        // Záměrně první: je to nabídka, ne úkol, ale týká se celé instalace
        // a ostatní položky se objevují a mizí podle dat, takže by se v nich
        // ztratila. Jen pro admina — /admin/upgrade je adminOnly a komukoliv
        // jinému by odkaz skončil na redirectu. Zavrhnutelná jako ostatní.
        if ($userRole === 'admin' && !$this->isFullyDismissed($dismissals, 'myucto_upgrade')) {
            $items[] = [
                'type'     => 'myucto_upgrade',
                'severity' => 'low',
                'title'    => 'Přejděte zdarma na MyÚčto',
                'hint'     => 'Nástupce MyInvoice od stejného autora s víc funkcemi a aktivním vývojem',
                'link'     => '/admin/upgrade',
            ];
        }

        $overdueOperator = $this->overduePolicy->comparisonOperator();

        // 1. Overdue vystavené faktury — pošli upomínku.
        // Stejná pohledávková sémantika jako cílový seznam /invoices?overdue=1
        // (InvoiceRepository): vč. nezaplacených NESPÁROVANÝCH proforem, vyřazení
        // finálních dokladů k zaplacené proformě (amount_to_pay = 0). Drží count = seznam.
        $stmt = $pdo->prepare(
            "SELECT i.id FROM invoices i
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded')
                AND i.due_date {$overdueOperator} CURDATE()
                AND (i.invoice_type != 'proforma'
                     OR NOT EXISTS (SELECT 1 FROM invoices ch
                                     WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice'))
                AND (i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)"
        );
        $stmt->execute([$supplierId]);
        $overdueIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $overdueIds = $this->filterByDismissal($overdueIds, $dismissals, 'overdue_invoices');
        $overdueCount = count($overdueIds);
        if ($overdueCount > 0) {
            $items[] = [
                'type'     => 'overdue_invoices',
                'severity' => $overdueCount > 5 ? 'high' : 'medium',
                'title'    => 'Pošli upomínky',
                'hint'     => sprintf('%d %s po splatnosti', $overdueCount,
                    $overdueCount === 1 ? 'faktura' : ($overdueCount < 5 ? 'faktury' : 'faktur')),
                'link'     => '/invoices?overdue=1',
                'count'    => $overdueCount,
            ];
        }

        // 1b. Nespárované příchozí platby z banky — spáruj s vystavenými fakturami.
        // Scope banky je přes account_number z currencies dodavatele (bank_statements
        // nemá supplier_id) — stejný predikát jako BankStatementAction::list().
        // Jen příchozí (amount > 0) za posledních 90 dní, aby se nevynořovaly prastaré
        // vlastní převody/poplatky; starší šum lze i tak skrýt přes dismiss „historická".
        $stmt = $pdo->prepare(
            "SELECT bt.id FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.match_status = 'unmatched'
                AND bt.amount > 0
                AND bt.posted_at >= DATE_SUB(?, INTERVAL 90 DAY)
                AND EXISTS (
                    SELECT 1 FROM currencies cur
                     WHERE cur.supplier_id = ?
                       AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                         = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                       AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                )"
        );
        $stmt->execute([$today, $supplierId]);
        $bankIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $bankIds = $this->filterByDismissal($bankIds, $dismissals, 'bank_unmatched');
        $bankCount = count($bankIds);
        if ($bankCount > 0) {
            $items[] = [
                'type'     => 'bank_unmatched',
                'severity' => $bankCount > 5 ? 'high' : 'medium',
                'title'    => 'Spáruj platby z banky',
                'hint'     => sprintf('%d nespárovaných příchozích %s z výpisů', $bankCount,
                    $bankCount === 1 ? 'platba' : ($bankCount < 5 ? 'platby' : 'plateb')),
                'link'     => '/bank',
                'count'    => $bankCount,
            ];
        }

        // 2. Recurring s next_run_date v <= 3 dnech (nové faktury k vystavení)
        $stmt = $pdo->prepare(
            "SELECT id FROM recurring_invoice_templates
              WHERE supplier_id = ?
                AND (end_date IS NULL OR end_date >= ?)
                AND next_run_date IS NOT NULL
                AND next_run_date <= DATE_ADD(?, INTERVAL 3 DAY)
                AND next_run_date >= ?"
        );
        $stmt->execute([$supplierId, $today, $today, $today]);
        $recurringIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $recurringIds = $this->filterByDismissal($recurringIds, $dismissals, 'recurring_due');
        $recurringCount = count($recurringIds);
        if ($recurringCount > 0) {
            $items[] = [
                'type'     => 'recurring_due',
                'severity' => 'medium',
                'title'    => 'Vystav pravidelné faktury',
                'hint'     => sprintf('%d %s má vystavit v příštích 3 dnech', $recurringCount,
                    $recurringCount === 1 ? 'recurring fakturace' : 'recurring fakturací'),
                'link'     => '/recurring',
                'count'    => $recurringCount,
            ];
        }

        // 3. Přijaté faktury po splatnosti — zaplatit dodavateli
        $stmt = $pdo->prepare(
            "SELECT id FROM purchase_invoices
              WHERE supplier_id = ?
                AND status IN ('received', 'booked')
                AND due_date < ?"
        );
        $stmt->execute([$supplierId, $today]);
        $payablesIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $payablesIds = $this->filterByDismissal($payablesIds, $dismissals, 'overdue_payables');
        $payablesCount = count($payablesIds);
        if ($payablesCount > 0) {
            $items[] = [
                'type'     => 'overdue_payables',
                'severity' => $payablesCount > 3 ? 'high' : 'medium',
                'title'    => 'Zaplať dodavatelům',
                'hint'     => sprintf('%d nezaplacených přijatých %s po splatnosti', $payablesCount,
                    $payablesCount === 1 ? 'faktura' : 'faktur'),
                'link'     => '/purchase-invoices?overdue=1',
                'count'    => $payablesCount,
            ];
        }

        // 3b. Koncepty přijatých faktur — naimportované (API/AI/PDF) zůstávají ve stavu
        // draft kvůli upomínkám/kontrole; vyzvi k revizi a zaúčtování.
        $stmt = $pdo->prepare(
            "SELECT id FROM purchase_invoices WHERE supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$supplierId]);
        $purchaseDraftIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $purchaseDraftIds = $this->filterByDismissal($purchaseDraftIds, $dismissals, 'purchase_drafts');
        $purchaseDraftCount = count($purchaseDraftIds);
        if ($purchaseDraftCount > 0) {
            $items[] = [
                'type'     => 'purchase_drafts',
                'severity' => 'low',
                'title'    => 'Zkontroluj koncepty přijatých faktur',
                'hint'     => sprintf('%d %s čeká na revizi/zaúčtování', $purchaseDraftCount,
                    $purchaseDraftCount === 1 ? 'koncept' : ($purchaseDraftCount < 5 ? 'koncepty' : 'konceptů')),
                'link'     => '/purchase-invoices?status=draft',
                'count'    => $purchaseDraftCount,
            ];
        }

        // 4. Reports deadlines — DPH přiznání + Kontrolní hlášení se podávají 25. dne
        // po skončení zdaňovacího období. Respektuje periodicitu dodavatele
        // (supplier.vat_period + taxpayer_type) — viz taxDeadlineItems().
        // (date-based, historical mode chová se jako forever na aktuální období)
        foreach ($this->taxDeadlineItems($supplierId, $nowDt) as $taxItem) {
            if (!$this->isFullyDismissed($dismissals, (string) $taxItem['type'])) {
                $items[] = $taxItem;
            }
        }

        // 4b. Souhrnné hlášení (SH) — také se podává 25. následujícího měsíce, ale JEN
        // pokud za uplynulý měsíc existují EU B2B plnění (dodání zboží/služeb do JČS
        // plátci s DIČ, kódy 20/22/31 nebo reverse charge na EU odběratele). Bez nich
        // se SH nepodává — proto se akce zobrazí jen když je co reportovat.
        // Pozn.: kontrolujeme měsíční periodu; čtvrtletní filtry (jen služby) mohou být
        // mírně přepomenuty, lze skrýt přes dismiss.
        if (!$this->isFullyDismissed($dismissals, 'shv_deadline')) {
            $now = new \DateTimeImmutable($today);
            // § 33/4 DŘ: víkend/svátek → nejbližší následující pracovní den (jako výkazy).
            $deadlineDate = \MyInvoice\Service\Report\CzechWorkingDays::shiftToWorkingDay(
                new \DateTimeImmutable(sprintf('%04d-%02d-25', (int) $now->format('Y'), (int) $now->format('n')))
            )->format('Y-m-d');
            $daysToDeadline = (int) $now->diff(new \DateTimeImmutable($deadlineDate))->format('%r%a');
            if ($daysToDeadline >= -3 && $daysToDeadline <= 7) {
                $prevMonth = $now->modify('first day of last month');
                $periodStart = $prevMonth->format('Y-m-01');
                $periodEnd = $prevMonth->format('Y-m-t');
                $stmt = $pdo->prepare(
                    "SELECT 1
                       FROM invoices i
                       JOIN clients c ON c.id = i.client_id
                  LEFT JOIN countries co ON co.id = c.country_id
                       JOIN invoice_items ii ON ii.invoice_id = i.id
                      WHERE i.supplier_id = ?
                        AND i.status IN ('issued','sent','reminded','paid')
                        AND i.invoice_type IN ('invoice','credit_note','tax_document')
                        AND COALESCE(co.is_eu, 0) = 1
                        AND COALESCE(co.iso2, 'CZ') <> 'CZ'
                        AND c.dic IS NOT NULL AND c.dic <> ''
                        AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
                        AND (
                              COALESCE(ii.vat_classification_code, i.vat_classification_code) IN ('20','22','31')
                              OR i.reverse_charge = 1
                        )
                      LIMIT 1"
                );
                $stmt->execute([$supplierId, $periodStart, $periodEnd]);
                $hasEuSupplies = (bool) $stmt->fetchColumn();
                if ($hasEuSupplies) {
                    $items[] = [
                        'type'     => 'shv_deadline',
                        'severity' => $daysToDeadline < 0 ? 'high' : ($daysToDeadline <= 2 ? 'high' : 'medium'),
                        'title'    => 'Souhrnné hlášení za uplynulý měsíc',
                        'hint'     => $daysToDeadline < 0
                            ? sprintf('Termín byl %d dní zpět — podej co nejdříve!', abs($daysToDeadline))
                            : sprintf('Máš EU plnění — termín podání za %d %s (do %s)', $daysToDeadline,
                                $daysToDeadline === 1 ? 'den' : ($daysToDeadline < 5 ? 'dny' : 'dní'),
                                $deadlineDate),
                        'link'     => '/reports/shv',
                        'days'     => $daysToDeadline,
                    ];
                }
            }
        }

        // 5. Klienti dlouho bez objednávky (90+ dní)
        $stmt = $pdo->prepare(
            "WITH last_invoice AS (
                SELECT client_id, MAX(issue_date) AS last_date
                  FROM invoices
                 WHERE supplier_id = ?
                   AND status NOT IN ('draft', 'cancelled')
                 GROUP BY client_id
              )
              SELECT li.client_id FROM last_invoice li
              JOIN clients c ON c.id = li.client_id
             WHERE DATEDIFF(?, li.last_date) >= 90"
        );
        $stmt->execute([$supplierId, $today]);
        $churnIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $churnIds = $this->filterByDismissal($churnIds, $dismissals, 'churn_risk');
        $churnCount = count($churnIds);
        if ($churnCount > 0) {
            $items[] = [
                'type'     => 'churn_risk',
                'severity' => 'low',
                'title'    => 'Kontaktuj neaktivní klienty',
                'hint'     => sprintf('%d %s 90+ dní bez objednávky', $churnCount,
                    $churnCount === 1 ? 'klient je' : 'klientů je'),
                'link'     => '/crm#churn-risk',
                'count'    => $churnCount,
            ];
        }

        return [
            'items' => $items,
            'total' => count($items),
            'dismissed_count' => count($dismissals),
        ];
    }

    /**
     * Daňové termíny (DPH přiznání + Kontrolní hlášení) podle periodicity dodavatele.
     *
     * Pravidla (zákon 235/2004 Sb.):
     *   - **DPH přiznání** — měsíční plátce: 25. dne následujícího měsíce za uplynulý
     *     měsíc; čtvrtletní plátce: 25. dne měsíce po skončení čtvrtletí (Q1→25.4,
     *     Q2→25.7, Q3→25.10, Q4→25.1).
     *   - **Kontrolní hlášení (§101e)** — právnická osoba (PO) VŽDY měsíčně; fyzická
     *     osoba (FO) ve lhůtě pro přiznání → kopíruje DPH periodu.
     *
     * DPH a KH se sloučí do jedné položky jen když mají shodné období i termín
     * (měsíční plátce, nebo čtvrtletní FO). U čtvrtletní PO se KH (měsíční) a DPH
     * (čtvrtletní) zobrazí jako dvě samostatné položky s odlišnou periodou.
     *
     * Položka se zobrazí jen v okně −3..+7 dní kolem termínu.
     *
     * @return list<array<string,mixed>>
     */
    private function taxDeadlineItems(int $supplierId, \DateTimeImmutable $now): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_vat_payer, taxpayer_type, vat_period FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        if (!(bool) ($row['is_vat_payer'] ?? false)) {
            return [];
        }

        $taxpayerType = (string) ($row['taxpayer_type'] ?? '');
        $vatPeriod = ((string) ($row['vat_period'] ?? 'monthly')) === 'quarterly' ? 'quarterly' : 'monthly';
        // KH: PO vždy měsíčně; FO kopíruje DPH periodu.
        $khMonthly = $taxpayerType === 'po' || $vatPeriod === 'monthly';

        $m = (int) $now->format('n');
        $y = (int) $now->format('Y');

        // DPH termín + popis období
        if ($vatPeriod === 'monthly') {
            $dphActive = true;
            $dphDeadline = sprintf('%04d-%02d-25', $y, $m);
            $dphPeriod = 'za uplynulý měsíc';
        } else {
            // Čtvrtletní termín existuje jen v měsících 1/4/7/10.
            $dphActive = in_array($m, [1, 4, 7, 10], true);
            $dphDeadline = sprintf('%04d-%02d-25', $y, $m);
            $endedQuarter = $m === 1 ? 4 : (int) (($m - 1) / 3); // 4→Q1, 7→Q2, 10→Q3, 1→Q4
            $quarterYear = $m === 1 ? $y - 1 : $y;
            $dphPeriod = sprintf('za %d. čtvrtletí %d', $endedQuarter, $quarterYear);
        }

        // Sloučit DPH+KH? Jen pokud sdílí periodu (měsíční plátce nebo čtvrtletní FO).
        $combine = $vatPeriod === 'monthly' || !$khMonthly;

        $items = [];
        if ($combine) {
            if ($dphActive) {
                $title = $vatPeriod === 'monthly'
                    ? 'DPH + KH za uplynulý měsíc'
                    : 'DPH + KH ' . $dphPeriod;
                $item = $this->buildDeadlineItem('tax_deadline', $title, $dphDeadline, '/reports/dph', $now);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        } else {
            // Čtvrtletní PO: KH měsíčně + DPH čtvrtletně, dvě samostatné položky.
            $khItem = $this->buildDeadlineItem('kh_deadline', 'Kontrolní hlášení za uplynulý měsíc',
                sprintf('%04d-%02d-25', $y, $m), '/reports/kh', $now);
            if ($khItem !== null) {
                $items[] = $khItem;
            }
            if ($dphActive) {
                $dphItem = $this->buildDeadlineItem('tax_deadline', 'DPH ' . $dphPeriod,
                    $dphDeadline, '/reports/dph', $now);
                if ($dphItem !== null) {
                    $items[] = $dphItem;
                }
            }
        }

        return $items;
    }

    /**
     * Sestaví action item pro daňový termín, nebo null mimo okno −3..+7 dní.
     *
     * @return array<string,mixed>|null
     */
    private function buildDeadlineItem(string $type, string $title, string $deadline, string $link, \DateTimeImmutable $now): ?array
    {
        // § 33/4 DŘ: 25. na víkend/svátek → posun na nejbližší pracovní den, jinak
        // dashboard hlásí „po termínu" dřív, než lhůta skutečně uplyne (shodně s výkazy).
        $deadline = \MyInvoice\Service\Report\CzechWorkingDays::shiftToWorkingDay(
            new \DateTimeImmutable($deadline)
        )->format('Y-m-d');
        $days = (int) $now->diff(new \DateTimeImmutable($deadline))->format('%r%a');
        if ($days < -3 || $days > 7) {
            return null;
        }
        return [
            'type'     => $type,
            'severity' => $days < 0 ? 'high' : ($days <= 2 ? 'high' : 'medium'),
            'title'    => $title,
            'hint'     => $days < 0
                ? sprintf('Termín byl %d dní zpět — podej co nejdříve!', abs($days))
                : sprintf('Termín podání za %d %s (do %s)', $days,
                    $days === 1 ? 'den' : ($days < 5 ? 'dny' : 'dní'),
                    $deadline),
            'link'     => $link,
            'days'     => $days,
        ];
    }

    /**
     * Vrátí dismissals pro daného user (klíčované item_type).
     * Auto-promaže expirované záznamy day/week.
     *
     * @return array<string, array{mode:string, dismissed_until:?string, baseline_ids:?array<int>}>
     */
    private function loadDismissals(int $supplierId, ?int $userId): array
    {
        if ($userId === null || $userId <= 0) {
            return [];
        }
        $pdo = $this->db->pdo();
        // Auto-cleanup expired temporary dismissals
        $cleanup = $pdo->prepare(
            "DELETE FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ?
                AND mode IN ('day','week')
                AND dismissed_until IS NOT NULL
                AND dismissed_until < NOW()"
        );
        $cleanup->execute([$supplierId, $userId]);

        $stmt = $pdo->prepare(
            "SELECT item_type, mode, dismissed_until, baseline_ids
               FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ?"
        );
        $stmt->execute([$supplierId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $baseline = null;
            if ($row['baseline_ids'] !== null) {
                $decoded = json_decode((string) $row['baseline_ids'], true);
                $baseline = is_array($decoded) ? array_map('intval', $decoded) : null;
            }
            $out[(string) $row['item_type']] = [
                'mode'            => (string) $row['mode'],
                'dismissed_until' => $row['dismissed_until'] !== null ? (string) $row['dismissed_until'] : null,
                'baseline_ids'    => $baseline,
            ];
        }
        return $out;
    }

    /**
     * Vrací jen ID, která mají být zobrazená.
     * - day/week: pokud dismissed_until je v budoucnosti → []
     * - forever: → []
     * - historical: vrátí ID, která NEJSOU v baseline (= "nová")
     *
     * @param list<int> $currentIds
     * @param array<string, array{mode:string, dismissed_until:?string, baseline_ids:?array<int>}> $dismissals
     * @return list<int>
     */
    private function filterByDismissal(array $currentIds, array $dismissals, string $itemType): array
    {
        if (!isset($dismissals[$itemType])) {
            return $currentIds;
        }
        $d = $dismissals[$itemType];
        $mode = $d['mode'];
        if ($mode === 'day' || $mode === 'week') {
            // dismissed_until v budoucnosti = hide; jinak by byl už vyčištěn loadDismissals
            if ($d['dismissed_until'] !== null && strtotime($d['dismissed_until']) > time()) {
                return [];
            }
            return $currentIds;
        }
        if ($mode === 'forever') {
            return [];
        }
        if ($mode === 'historical') {
            $baseline = $d['baseline_ids'] ?? [];
            $baselineSet = array_flip($baseline);
            return array_values(array_filter($currentIds, static fn(int $id) => !isset($baselineSet[$id])));
        }
        return $currentIds;
    }

    /**
     * True pokud daný item type je plně skrytý (forever / historical bez baseline nebo s aktivním day/week).
     */
    private function isFullyDismissed(array $dismissals, string $itemType): bool
    {
        if (!isset($dismissals[$itemType])) {
            return false;
        }
        $d = $dismissals[$itemType];
        if ($d['mode'] === 'forever' || $d['mode'] === 'historical') {
            return true;
        }
        if (($d['mode'] === 'day' || $d['mode'] === 'week')
            && $d['dismissed_until'] !== null
            && strtotime($d['dismissed_until']) > time()) {
            return true;
        }
        return false;
    }

    /**
     * Uloží dismissal pro user + item_type.
     * Mode 'historical' → snapshotuje aktuální set ID daného typu.
     * Mode 'day' / 'week' → spočítá dismissed_until.
     * Mode 'forever' → dismissed_until = NULL.
     */
    public function dismissActionItem(int $supplierId, int $userId, string $itemType, string $mode): void
    {
        $validTypes = ['overdue_invoices', 'bank_unmatched', 'recurring_due', 'overdue_payables',
            'purchase_drafts', 'tax_deadline', 'kh_deadline', 'shv_deadline', 'churn_risk',
            'myucto_upgrade'];
        $validModes = ['day', 'week', 'forever', 'historical'];
        if (!in_array($itemType, $validTypes, true)) {
            throw new \InvalidArgumentException("Invalid item_type: {$itemType}");
        }
        if (!in_array($mode, $validModes, true)) {
            throw new \InvalidArgumentException("Invalid mode: {$mode}");
        }

        $dismissedUntil = null;
        if ($mode === 'day') {
            $dismissedUntil = (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s');
        } elseif ($mode === 'week') {
            $dismissedUntil = (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
        }

        $baselineJson = null;
        if ($mode === 'historical') {
            $ids = $this->snapshotCurrentIds($supplierId, $itemType);
            $baselineJson = json_encode($ids, JSON_THROW_ON_ERROR);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO crm_action_item_dismissals
                (supplier_id, user_id, item_type, mode, dismissed_until, baseline_ids)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                mode = VALUES(mode),
                dismissed_until = VALUES(dismissed_until),
                baseline_ids = VALUES(baseline_ids),
                created_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$supplierId, $userId, $itemType, $mode, $dismissedUntil, $baselineJson]);
    }

    /**
     * Smaže dismissal pro user + item_type (restore notification).
     */
    public function restoreActionItem(int $supplierId, int $userId, string $itemType): void
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ? AND item_type = ?"
        );
        $stmt->execute([$supplierId, $userId, $itemType]);
    }

    /**
     * Smaže VŠECHNY dismissals pro user — full restore.
     */
    public function restoreAllActionItems(int $supplierId, int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM crm_action_item_dismissals WHERE supplier_id = ? AND user_id = ?"
        );
        $stmt->execute([$supplierId, $userId]);
        return $stmt->rowCount();
    }

    /**
     * Vrátí počet aktivních dismissals pro user (pro UI: zobrazit "obnovit X skrytých").
     */
    public function dismissedCount(int $supplierId, int $userId): int
    {
        // Auto-cleanup expirovaných day/week
        $this->db->pdo()->prepare(
            "DELETE FROM crm_action_item_dismissals
              WHERE supplier_id = ? AND user_id = ?
                AND mode IN ('day','week') AND dismissed_until IS NOT NULL AND dismissed_until < NOW()"
        )->execute([$supplierId, $userId]);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM crm_action_item_dismissals WHERE supplier_id = ? AND user_id = ?"
        );
        $stmt->execute([$supplierId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private function snapshotCurrentIds(int $supplierId, string $itemType): array
    {
        $pdo = $this->db->pdo();
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        switch ($itemType) {
            case 'overdue_invoices':
                $overdueOperator = $this->overduePolicy->comparisonOperator();
                // Musí přesně zrcadlit dotaz v actionItems() (vč. nespárovaných proforem),
                // jinak by se po „historická" dismiss vynořily proformy jako „nové".
                $stmt = $pdo->prepare(
                    "SELECT i.id FROM invoices i
                      WHERE i.supplier_id = ?
                        AND i.status IN ('issued','sent','reminded')
                        AND i.due_date {$overdueOperator} CURDATE()
                        AND (i.invoice_type != 'proforma'
                             OR NOT EXISTS (SELECT 1 FROM invoices ch
                                             WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice'))
                        AND (i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)"
                );
                $stmt->execute([$supplierId]);
                break;
            case 'bank_unmatched':
                $stmt = $pdo->prepare(
                    "SELECT bt.id FROM bank_transactions bt
                       JOIN bank_statements bs ON bs.id = bt.statement_id
                      WHERE bt.match_status = 'unmatched'
                        AND bt.amount > 0
                        AND bt.posted_at >= DATE_SUB(?, INTERVAL 90 DAY)
                        AND EXISTS (
                            SELECT 1 FROM currencies cur
                             WHERE cur.supplier_id = ?
                               AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                                 = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                               AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                        )"
                );
                $stmt->execute([$today, $supplierId]);
                break;
            case 'recurring_due':
                $stmt = $pdo->prepare(
                    "SELECT id FROM recurring_invoice_templates
                      WHERE supplier_id = ?
                        AND (end_date IS NULL OR end_date >= ?)
                        AND next_run_date IS NOT NULL
                        AND next_run_date <= DATE_ADD(?, INTERVAL 3 DAY)
                        AND next_run_date >= ?"
                );
                $stmt->execute([$supplierId, $today, $today, $today]);
                break;
            case 'overdue_payables':
                $stmt = $pdo->prepare(
                    "SELECT id FROM purchase_invoices
                      WHERE supplier_id = ?
                        AND status IN ('received','booked')
                        AND due_date < ?"
                );
                $stmt->execute([$supplierId, $today]);
                break;
            case 'purchase_drafts':
                $stmt = $pdo->prepare(
                    "SELECT id FROM purchase_invoices WHERE supplier_id = ? AND status = 'draft'"
                );
                $stmt->execute([$supplierId]);
                break;
            case 'churn_risk':
                $stmt = $pdo->prepare(
                    "WITH last_invoice AS (
                        SELECT client_id, MAX(issue_date) AS last_date
                          FROM invoices
                         WHERE supplier_id = ?
                           AND status NOT IN ('draft','cancelled')
                         GROUP BY client_id
                      )
                      SELECT li.client_id FROM last_invoice li
                      JOIN clients c ON c.id = li.client_id
                     WHERE DATEDIFF(?, li.last_date) >= 90"
                );
                $stmt->execute([$supplierId, $today]);
                break;
            case 'tax_deadline':
            case 'kh_deadline':
            case 'shv_deadline':
                return []; // date-based, žádné ID
            default:
                return [];
        }
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Cash flow forecast — predicted in/out per week dopředu.
     *
     * @return array{
     *   currency: string,
     *   weeks: list<array{week_start:string, week_end:string, in:float, out:float, net:float, running:float}>,
     *   total_in: float, total_out: float, total_net: float
     * }
     */
    public function cashFlowForecast(int $supplierId, int $weeksAhead = 4, string $currency = 'CZK'): array
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');

        // Build week buckets
        $weeks = [];
        $running = 0.0;
        $totalIn = 0.0;
        $totalOut = 0.0;

        for ($w = 0; $w < $weeksAhead; $w++) {
            $weekStart = $today->modify("+{$w} weeks")->modify('Monday this week');
            if ($w === 0) {
                // První týden začíná dneškem (ne pondělím)
                $weekStart = $today;
            }
            $weekEnd = $weekStart->modify('Sunday this week');
            if ($w === 0 && $weekEnd < $weekStart) {
                $weekEnd = $weekStart->modify('+6 days');
            }

            // In: nezaplacené vystavené faktury s due_date v tomto týdnu — očekávané
            // inkaso = zbývající částka (amount_to_pay - paid_total): částečné úhrady
            // i odpočty záloh už dorazily, podruhé nepřitečou (#89).
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(i.amount_to_pay - i.paid_total), 0) AS amt
                   FROM invoices i
              LEFT JOIN currencies c ON c.id = i.currency_id
                  WHERE i.supplier_id = ?
                    AND i.status IN ('issued', 'sent', 'reminded')
                    AND i.invoice_type != 'proforma'
                    AND (i.invoice_type NOT IN ('invoice','tax_document') OR i.amount_to_pay - i.paid_total > 0)
                    AND i.due_date BETWEEN ? AND ?
                    AND COALESCE(c.code, 'CZK') = ?"
            );
            $stmt->execute([$supplierId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'), $currency]);
            $in = (float) $stmt->fetchColumn();

            // Out: nezaplacené přijaté faktury s due_date v tomto týdnu
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(pi.total_with_vat), 0) AS amt
                   FROM purchase_invoices pi
              LEFT JOIN currencies c ON c.id = pi.currency_id
                  WHERE pi.supplier_id = ?
                    AND pi.status IN ('received', 'booked')
                    AND pi.due_date BETWEEN ? AND ?
                    AND COALESCE(c.code, 'CZK') = ?"
            );
            $stmt->execute([$supplierId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'), $currency]);
            $out = (float) $stmt->fetchColumn();

            $net = $in - $out;
            $running += $net;
            $totalIn += $in;
            $totalOut += $out;

            $weeks[] = [
                'week_start' => $weekStart->format('Y-m-d'),
                'week_end'   => $weekEnd->format('Y-m-d'),
                'in'         => round($in, 2),
                'out'        => round($out, 2),
                'net'        => round($net, 2),
                'running'    => round($running, 2),
            ];
        }

        return [
            'currency'  => $currency,
            'weeks'     => $weeks,
            'total_in'  => round($totalIn, 2),
            'total_out' => round($totalOut, 2),
            'total_net' => round($totalIn - $totalOut, 2),
        ];
    }

    /**
     * Late payment risk score per klient — kdo platí pozdě.
     *
     * Score 0-100:
     *   0 = nikdy late
     *   100 = vždy late, dlouho
     *
     * Formula: late_rate * 50 + min(50, avg_days_late) → cap 100
     *
     * @return list<array{
     *   client_id:int, company_name:string, total_paid:int, late_count:int,
     *   late_rate:float, avg_days_late:float, score:int, risk_level:string
     * }>
     */
    public function lateRisk(int $supplierId, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH paid_invoices AS (
                SELECT i.client_id,
                       i.due_date,
                       i.paid_at,
                       DATEDIFF(i.paid_at, i.due_date) AS days_late
                  FROM invoices i
                 WHERE i.supplier_id = ?
                   AND i.status = 'paid'
                   AND i.paid_at IS NOT NULL
                   AND i.due_date IS NOT NULL
                   AND i.invoice_type != 'proforma'
              )
              SELECT pi.client_id,
                     c.company_name,
                     COUNT(*) AS total_paid,
                     SUM(CASE WHEN pi.days_late > 0 THEN 1 ELSE 0 END) AS late_count,
                     AVG(CASE WHEN pi.days_late > 0 THEN pi.days_late ELSE NULL END) AS avg_days_late
                FROM paid_invoices pi
                JOIN clients c ON c.id = pi.client_id
            GROUP BY pi.client_id, c.company_name
              HAVING total_paid >= 2
            ORDER BY (SUM(CASE WHEN pi.days_late > 0 THEN 1 ELSE 0 END) / COUNT(*)) DESC,
                     avg_days_late DESC
              LIMIT ?"
        );
        $stmt->execute([$supplierId, $limit]);
        return array_map(function ($r) {
            $totalPaid = (int) $r['total_paid'];
            $lateCount = (int) $r['late_count'];
            $lateRate = $totalPaid > 0 ? ($lateCount / $totalPaid) : 0.0;
            $avgDaysLate = $r['avg_days_late'] !== null ? (float) $r['avg_days_late'] : 0.0;
            $score = (int) min(100, $lateRate * 50 + min(50, $avgDaysLate));
            $riskLevel = $score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low');
            return [
                'client_id'     => (int) $r['client_id'],
                'company_name'  => (string) $r['company_name'],
                'total_paid'    => $totalPaid,
                'late_count'    => $lateCount,
                'late_rate'     => round($lateRate, 3),
                'avg_days_late' => round($avgDaysLate, 1),
                'score'         => $score,
                'risk_level'    => $riskLevel,
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Reminder effectiveness — funnel jaké % faktur platí po 1./2./3. upomínce.
     *
     * @return array{
     *   total_paid: int,
     *   no_reminder: int,        — zaplaceno bez upomínky
     *   after_first: int,        — zaplaceno po 1. upomínce
     *   after_second: int,       — po 2. (eskalovaně)
     *   after_third_plus: int,   — po 3+ (vážně problémové)
     *   never_paid: int,         — odeslané upomínky, ale dosud nezaplaceno
     *   avg_reminders_to_paid: float
     * }
     */
    public function reminderEffectiveness(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify("-{$monthsBack} months")->format('Y-m-d');
        $pdo = $this->db->pdo();

        // Count reminders per invoice (z `invoices.reminder_count` denorm sloupce)
        $stmt = $pdo->prepare(
            "SELECT i.id AS invoice_id,
                    i.status,
                    COALESCE(i.reminder_count, 0) AS reminder_count
               FROM invoices i
              WHERE i.supplier_id = ?
                AND i.status IN ('paid', 'sent', 'reminded')
                -- tax_document je auto-paid bez upomínek — do statistiky účinnosti nepatří
                AND i.invoice_type NOT IN ('proforma', 'tax_document')
                AND i.issue_date >= ?"
        );
        $stmt->execute([$supplierId, $start]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [
            'total_paid' => 0, 'no_reminder' => 0, 'after_first' => 0,
            'after_second' => 0, 'after_third_plus' => 0, 'never_paid' => 0,
        ];
        $reminderCountSumPaid = 0;
        foreach ($rows as $r) {
            $cnt = (int) $r['reminder_count'];
            if ($r['status'] === 'paid') {
                $result['total_paid']++;
                $reminderCountSumPaid += $cnt;
                if ($cnt === 0) $result['no_reminder']++;
                elseif ($cnt === 1) $result['after_first']++;
                elseif ($cnt === 2) $result['after_second']++;
                else $result['after_third_plus']++;
            } elseif ($cnt > 0) {
                $result['never_paid']++;
            }
        }
        $result['avg_reminders_to_paid'] = $result['total_paid'] > 0
            ? round($reminderCountSumPaid / $result['total_paid'], 2)
            : 0.0;

        return $result;
    }

    /**
     * Invoice → paid time histogram — distribuce (paid_at - issue_date) v dnech.
     *
     * Buckets: 0-7, 8-14, 15-30, 31-60, 61+
     *
     * @return array{
     *   buckets: list<array{label:string, count:int, percent:float, min:int, max:?int}>,
     *   total_invoices: int,
     *   median_days: ?int,
     *   p90_days: ?int
     * }
     */
    public function paymentTimeHistogram(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify("-{$monthsBack} months")->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATEDIFF(paid_at, issue_date) AS days
               FROM invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND invoice_type != 'proforma'
                AND issue_date >= ?
                AND paid_at >= issue_date"
        );
        $stmt->execute([$supplierId, $start]);
        $days = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $total = count($days);
        if ($total === 0) {
            return ['buckets' => [], 'total_invoices' => 0, 'median_days' => null, 'p90_days' => null];
        }

        $buckets = [
            ['label' => '0–7 dní', 'min' => 0, 'max' => 7, 'count' => 0],
            ['label' => '8–14 dní', 'min' => 8, 'max' => 14, 'count' => 0],
            ['label' => '15–30 dní', 'min' => 15, 'max' => 30, 'count' => 0],
            ['label' => '31–60 dní', 'min' => 31, 'max' => 60, 'count' => 0],
            ['label' => '61+ dní', 'min' => 61, 'max' => null, 'count' => 0],
        ];
        foreach ($days as $d) {
            foreach ($buckets as $i => $b) {
                if ($d >= $b['min'] && ($b['max'] === null || $d <= $b['max'])) {
                    $buckets[$i]['count']++;
                    break;
                }
            }
        }
        foreach ($buckets as $i => $b) {
            $buckets[$i]['percent'] = round(($b['count'] / $total) * 100, 1);
        }

        sort($days);
        $medianIdx = (int) floor($total / 2);
        $p90Idx = (int) floor($total * 0.9);
        return [
            'buckets'        => $buckets,
            'total_invoices' => $total,
            'median_days'    => $days[$medianIdx] ?? null,
            'p90_days'       => $days[$p90Idx] ?? null,
        ];
    }
}
