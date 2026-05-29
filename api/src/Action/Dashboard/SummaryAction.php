<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agregace pro Dashboard:
 *  - KPI: letošní obrat per měna, YoY change, počet vystavených, průměrná doba úhrady
 *  - Po splatnosti: tabulka faktur s due_date < today, status issued/sent
 *  - Nezaplacené (před splatností)
 *  - Top klienti YTD
 *  - Obrat po měsících (12 měsíců současný + minulý rok)
 *
 * Storno (cancelled) a interní cancellation se z obratu vyřazují.
 */
final class SummaryAction
{
    /**
     * Pásma paušální daně (§ 7a ZDP) — roční limit příjmů. Hodnoty jsou stabilní
     * (zákonné stropy), drží se v kódu mimo DB. Měsíční zálohy se mění ročně a
     * záměrně je tu nesledujeme (informativní limit příjmů stačí pro varování).
     */
    private const FLAT_TAX_BANDS = [
        'band1' => 1_000_000,
        'band2' => 1_500_000,
        'band3' => 2_000_000,
    ];

    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $prevYear = $year - 1;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $isVatPayer = $this->fetchIsVatPayer($pdo, $sid);
        // revenueByYear počítáme jednou a sdílíme s forecastem (CAGR trend) — žádný druhý dotaz.
        $revenueByYear = $this->revenueByYear($pdo, $sid, $isVatPayer);

        return Json::ok($response, [
            'kpi'                    => $this->kpi($pdo, $year, $prevYear, $sid, $isVatPayer),
            'overdue'                => $this->overdue($pdo, $sid),
            'unpaid_upcoming'        => $this->unpaidUpcoming($pdo, $sid),
            'top_clients_ytd'        => $this->topClients($pdo, $year, $sid, $isVatPayer),
            'top_clients_prev_year'  => $this->topClients($pdo, $prevYear, $sid, $isVatPayer),
            'top_clients_12m'        => $this->topClientsRolling12m($pdo, $sid, $isVatPayer),
            'revenue_by_month'       => $this->revenueByMonth($pdo, $sid, $isVatPayer),
            'purchase_costs_by_month'=> $this->purchaseCostsByMonth($pdo, $sid),
            'revenue_by_year'        => $revenueByYear,
            'rolling_12m'            => $this->rolling12mRevenue($pdo, $sid, $isVatPayer),
            'cashflow_ytd'           => $this->cashflowYtd($pdo, $year, $prevYear, $sid),
            'payment_days_histogram' => $this->paymentDaysHistogram($pdo, $sid),
            'vat_breakdown_12m'      => $isVatPayer ? $this->vatBreakdown12m($pdo, $sid) : [],
            'cashflow_forecast'      => $this->cashflowForecast($pdo, $sid),
            'due_buckets'            => $this->dueBuckets($pdo, $sid),
            'aging_report'           => $this->agingReport($pdo, $sid),
            'revenue_forecast'       => $this->revenueForecast($pdo, $year, $prevYear, $sid, $isVatPayer, $revenueByYear),
            'invoice_size_histogram' => $this->invoiceSizeHistogram($pdo, $sid, $isVatPayer),
            'revenue_last_30d'       => $this->revenueLast30d($pdo, $sid, $isVatPayer),
            'active_recurring_count' => $this->activeRecurringCount($pdo, $sid),
            'active_clients_count'   => $this->activeClientsCount($pdo, $sid),
            'pending_approvals'      => $this->pendingApprovals($pdo, $sid),
            'flat_tax_threshold'     => $this->flatTaxThreshold($pdo, $year, $sid),
            'today'                  => $today->format('Y-m-d'),
            'year'                   => $year,
            'prev_year'              => $prevYear,
            'is_vat_payer'           => $isVatPayer,
        ]);
    }

    /** Počet aktivních (neaarchivovaných) klientů v rámci aktuálního dodavatele. */
    private function activeClientsCount(\PDO $pdo, int $sid): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND archived_at IS NULL');
        $stmt->execute([$sid]);
        return (int) $stmt->fetchColumn();
    }

    /** Počet aktivních pravidelných fakturací (status='active'). */
    private function activeRecurringCount(\PDO $pdo, int $sid): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM recurring_invoice_templates WHERE supplier_id = ? AND status = 'active'");
        $stmt->execute([$sid]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Obrat za posledních 30 dní per měna — live indikátor aktuální fakturace.
     * @return list<array{currency: string, total: float, invoice_count: int}>
     */
    private function revenueLast30d(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT cur.code AS currency, SUM($rev) AS total, COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'      => (string) $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Obrat po rocích — všechny roky, ve kterých existují fakturované doklady (invoice + credit_note),
     * VAT-aware sloupec. Pro tabulkové zobrazení v Grafech.
     *
     * @return list<array{year: int, currency: string, total: float, invoice_count: int}>
     */
    private function revenueByYear(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT YEAR(COALESCE(i.tax_date, i.issue_date)) AS year,
                       cur.code AS currency,
                       SUM($rev) AS total,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY year, cur.code
                 ORDER BY year DESC, total DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'year'          => (int) $r['year'],
            'currency'      => (string) $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Zjistí, zda je aktuální dodavatel plátce DPH — určuje, který sloupec použijeme
     * pro agregaci obratu (`total_without_vat` pro plátce, `total_with_vat` pro neplátce).
     */
    private function fetchIsVatPayer(\PDO $pdo, int $sid): bool
    {
        $stmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Paušální daň (§ 7a ZDP) — sledování blížícího se ročního limitu příjmů pro
     * zvolené pásmo. Příjmy = zaplacené vystavené faktury (kasová metoda, paid_at
     * v aktuálním kalendářním roce), přepočet na CZK. Pokud supplier není v paušálu
     * (flat_tax_band='none'), vrací applicable=false.
     *
     * @return array{applicable: bool, band: ?string, current_czk: float, limit_czk: ?int, percent: ?int, status: ?string, year: int}
     */
    private function flatTaxThreshold(\PDO $pdo, int $year, int $sid): array
    {
        // Defenzivně: sloupec flat_tax_band nemusí existovat, pokud ještě neproběhla
        // migrace 0062 — v tom případě nesmí spadnout celý dashboard summary.
        try {
            $stmt = $pdo->prepare('SELECT flat_tax_band FROM supplier WHERE id = ?');
            $stmt->execute([$sid]);
            $band = (string) ($stmt->fetchColumn() ?: 'none');
        } catch (\Throwable) {
            $band = 'none';
        }

        if (!isset(self::FLAT_TAX_BANDS[$band])) {
            return ['applicable' => false, 'band' => null, 'current_czk' => 0.0,
                    'limit_czk' => null, 'percent' => null, 'status' => null, 'year' => $year];
        }

        $limit = self::FLAT_TAX_BANDS[$band];
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(i.total_with_vat * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)), 0) AS sum_czk
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status = 'paid'
                AND i.paid_at IS NOT NULL
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND YEAR(i.paid_at) = ?"
        );
        $stmt->execute([$sid, $year]);
        $current = round((float) $stmt->fetchColumn(), 2);

        $percent = $limit > 0 ? (int) round($current / $limit * 100) : 0;
        $status = match (true) {
            $percent >= 95 => 'danger',
            $percent >= 80 => 'warning',
            $percent >= 60 => 'notice',
            default        => 'ok',
        };

        return [
            'applicable'  => true,
            'band'        => $band,
            'current_czk' => $current,
            'limit_czk'   => $limit,
            'percent'     => $percent,
            'status'      => $status,
            'year'        => $year,
        ];
    }

    /** SQL fragment vybírající správný sloupec obratu dle plátcovství DPH. */
    private function revenueCol(bool $isVatPayer): string
    {
        return $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';
    }

    /**
     * Schvalování výkazu zákazníkem — count requested + overdue (>5 dní).
     * Klik na tile → /admin/approvals.
     * @return array{requested: int, overdue: int}
     */
    private function pendingApprovals(\PDO $pdo, int $sid): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN approval_status = 'requested' THEN 1 ELSE 0 END) AS requested,
                SUM(CASE WHEN approval_status = 'requested'
                          AND COALESCE(approval_reminder_at, approval_requested_at)
                              <= DATE_SUB(NOW(), INTERVAL 5 DAY) THEN 1 ELSE 0 END) AS overdue
              FROM invoices
             WHERE supplier_id = ?"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['requested' => 0, 'overdue' => 0];
        return [
            'requested' => (int) ($row['requested'] ?? 0),
            'overdue'   => (int) ($row['overdue'] ?? 0),
        ];
    }

    private function kpi(\PDO $pdo, int $year, int $prevYear, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Obrat per měna pro YTD (letošní vs. minulý rok)
        // Záměrně počítáme i NEZAPLACENÉ faktury, pokud jsou vystavené (status: issued / sent / paid).
        // Dobropisy (credit_note) ZAHRNUJEME — mají záporné total_with_vat (viz CancelInvoiceAction),
        // takže se SUMou automaticky odečtou od obratu. Koncepty (draft) a zálohovky (proforma) nezapočítáváme.
        //
        // change_pct: porovnává this_year (YTD) s prev_year_ytd — tj. minulý rok jen do stejné kalendářní
        // pozice (DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) — fair YoY pro nedokončený aktuální rok.
        // prev_year zůstává jako celoroční total pro kontext (zobrazení v UI / fallback grafy).
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS this_year,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS prev_year,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                  AND COALESCE(i.tax_date, i.issue_date) <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $rev ELSE 0 END) AS prev_year_ytd,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN 1 ELSE 0 END) AS this_year_invoice_count,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN 1 ELSE 0 END) AS prev_year_invoice_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.client_id END) AS this_year_client_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.client_id END) AS prev_year_client_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.project_id END) AS this_year_project_count,
                       COUNT(DISTINCT CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                            THEN i.project_id END) AS prev_year_project_count
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                   AND cur.is_active = 1
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $year, $prevYear, $prevYear,
            $year, $prevYear,
            $year, $prevYear,
            $year, $prevYear,
            $sid, $year, $prevYear,
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $perCurrency = [];
        foreach ($rows as $r) {
            $thisYear = (float) $r['this_year'];
            $prevYearTotal = (float) $r['prev_year'];
            $prevYearYtd = (float) $r['prev_year_ytd'];
            $changePct = null;
            if ($prevYearYtd > 0) {
                $changePct = round((($thisYear - $prevYearYtd) / $prevYearYtd) * 100, 1);
            }
            $perCurrency[(string) $r['currency']] = [
                'currency'                 => (string) $r['currency'],
                'this_year'                => round($thisYear, 2),
                'prev_year'                => round($prevYearTotal, 2),
                'prev_year_ytd'            => round($prevYearYtd, 2),
                'change_pct'               => $changePct,
                'this_year_invoice_count'  => (int) $r['this_year_invoice_count'],
                'prev_year_invoice_count'  => (int) $r['prev_year_invoice_count'],
                'this_year_client_count'   => (int) $r['this_year_client_count'],
                'prev_year_client_count'   => (int) $r['prev_year_client_count'],
                'this_year_project_count'  => (int) $r['this_year_project_count'],
                'prev_year_project_count'  => (int) $r['prev_year_project_count'],
            ];
        }

        // Počet vystavených YTD — proformy se nezapočítávají (nejde o finální daňový doklad).
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type IN ('invoice', 'credit_note')"
        );
        $stmt->execute([$sid, $year]);
        $issuedCount = (int) $stmt->fetchColumn();

        // Po splatnosti — počet a celkem k úhradě
        $stmt = $pdo->prepare(
            "SELECT cur.code AS currency, COUNT(*) AS cnt, SUM(i.amount_to_pay) AS total
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()
                AND i.invoice_type IN ('invoice','credit_note')
              GROUP BY cur.code"
        );
        $stmt->execute([$sid]);
        $overdue = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $overduePerCurrency = array_map(fn (array $r) => [
            'currency' => $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => round((float) $r['total'], 2),
        ], $overdue);
        $overdueTotalCount = array_sum(array_column($overduePerCurrency, 'count'));

        // Průměrná doba úhrady (paid_at - issue_date) ve dnech, pro letošní zaplacené
        $stmt = $pdo->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) FROM invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
                AND YEAR(COALESCE(tax_date, issue_date)) = ?"
        );
        $stmt->execute([$sid, $year]);
        $avgPaymentDays = $stmt->fetchColumn();
        $avgPaymentDays = $avgPaymentDays !== null && $avgPaymentDays !== false
            ? round((float) $avgPaymentDays, 1)
            : null;

        // Stav faktur YTD (počet) — pro fallback chart když není prev year
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
               FROM invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND invoice_type = 'invoice'
              GROUP BY status"
        );
        $stmt->execute([$sid, $year]);
        $statusCounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $statusCounts[$r['status']] = (int) $r['cnt'];
        }

        // Přijaté faktury YTD — náklady, počet, nezaplacené, po splatnosti
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(pi.total_with_vat * IF(cur.code = 'CZK' OR pi.exchange_rate IS NULL, 1, pi.exchange_rate)), 0) AS costs_czk
               FROM purchase_invoices pi
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN ('draft', 'cancelled')
                AND YEAR(pi.issue_date) = ?"
        );
        $stmt->execute([$sid, $year]);
        $piRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'costs_czk' => 0];

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(pi.total_with_vat * IF(cur.code = 'CZK' OR pi.exchange_rate IS NULL, 1, pi.exchange_rate)), 0) AS unpaid_czk
               FROM purchase_invoices pi
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status IN ('received', 'booked')"
        );
        $stmt->execute([$sid]);
        $piUnpaid = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'unpaid_czk' => 0];

        $today = date('Y-m-d');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt
               FROM purchase_invoices pi
              WHERE pi.supplier_id = ?
                AND pi.status IN ('received', 'booked')
                AND pi.due_date < ?"
        );
        $stmt->execute([$sid, $today]);
        $piOverdueCount = (int) $stmt->fetchColumn();

        return [
            'per_currency'        => array_values($perCurrency),
            'issued_count_ytd'    => $issuedCount,
            'overdue_count'       => $overdueTotalCount,
            'overdue_per_currency'=> $overduePerCurrency,
            'avg_payment_days'    => $avgPaymentDays,
            'status_counts_ytd'   => $statusCounts,
            // Přijaté faktury YTD
            'purchase_count_ytd'  => (int) $piRow['cnt'],
            'purchase_costs_ytd'  => round((float) $piRow['costs_czk'], 2),
            'purchase_unpaid_count' => (int) $piUnpaid['cnt'],
            'purchase_unpaid_total' => round((float) $piUnpaid['unpaid_czk'], 2),
            'purchase_overdue_count' => $piOverdueCount,
        ];
    }

    /**
     * Měsíční náklady (přijaté faktury) za posledních 12 měsíců v CZK — pro mini graf
     * na dashboardu. Vždy 12 slotů vzestupně, chybějící měsíce = 0.
     *
     * @return list<array{ym:string, total:float}>
     */
    private function purchaseCostsByMonth(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT DATE_FORMAT(pi.issue_date, '%Y-%m') AS ym,
                       SUM(pi.total_with_vat * IF(cur.code = 'CZK' OR pi.exchange_rate IS NULL, 1, pi.exchange_rate)) AS total
                  FROM purchase_invoices pi
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status NOT IN ('draft', 'cancelled')
                   AND pi.issue_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
                 GROUP BY ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $byYm = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $byYm[(string) $r['ym']] = round((float) $r['total'], 2);
        }

        $out = [];
        $cursor = (new \DateTimeImmutable(date('Y-m-01')))->modify('-11 months');
        for ($i = 0; $i < 12; $i++) {
            $ym = $cursor->format('Y-m');
            $out[] = ['ym' => $ym, 'total' => $byYm[$ym] ?? 0.0];
            $cursor = $cursor->modify('+1 month');
        }
        return $out;
    }

    private function overdue(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name,
                       DATEDIFF(CURDATE(), i.due_date) AS days_overdue
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date <= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function unpaidUpcoming(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.client_id, cur.code AS currency,
                       i.issue_date, i.due_date, i.amount_to_pay, i.status,
                       c.company_name AS client_company_name
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.due_date >= CURDATE()
                   AND i.invoice_type IN ('invoice','credit_note')
                 ORDER BY i.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castListItem($r), $rows);
    }

    private function topClients(\PDO $pdo, int $year, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Přepočet na CZK přes i.exchange_rate (CNB k DUZP). CZK řádky multiplier 1.
        // Grupujeme jen po klientovi (ne per currency) — multi-currency klient se neroztrhne
        // a ranking je správný (1000 EUR > 20 000 CZK).
        $revCzk = "$rev * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)";
        $sql = "SELECT c.id, c.company_name,
                       SUM($revCzk) AS total_czk,
                       GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY c.id, c.company_name
                 ORDER BY total_czk DESC
                 LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => [
            'client_id'     => (int) $r['id'],
            'company_name'  => $r['company_name'],
            'currencies'    => (string) $r['currencies'],
            'total_czk'     => round((float) $r['total_czk'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $rows);
    }

    /**
     * Obrat za posledních 12 měsíců (rolling window končící aktuálním měsícem) + porovnávací řada
     * pro stejných 12 měsíců o rok dříve (–1 rok), per měna.
     *
     * Output: [
     *   { currency: 'CZK',
     *     months:    [ { ym: 'YYYY-MM', total: 0.0 }, ... 12 entries ascending ],
     *     prev_year: [ { ym: 'YYYY-MM', total: 0.0 }, ... 12 entries ascending, –12 měsíců ] },
     *   ...
     * ]
     */
    private function revenueByMonth(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Okno aktuálních 12 měsíců + 12 měsíců o rok dříve = celkem 24 měsíců dat.
        // Začátek = (dnes − 23 měsíců, 1. den měsíce).
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS ym,
                       SUM($rev) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 23 MONTH), '%Y-%m-01')
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                   AND cur.is_active = 1
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Sloty: aktuální 12 měsíců (months) + 12 měsíců o rok dříve (prev_year).
        $monthsSlots = [];
        $prevSlots = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01'));
        $cursorThis = $cursor->modify('-11 months');
        $cursorPrev = $cursor->modify('-23 months');
        for ($i = 0; $i < 12; $i++) {
            $monthsSlots[$cursorThis->format('Y-m')] = 0.0;
            $prevSlots[$cursorPrev->format('Y-m')]   = 0.0;
            $cursorThis = $cursorThis->modify('+1 month');
            $cursorPrev = $cursorPrev->modify('+1 month');
        }

        // Skupina per měna — totaly přiřaď do správného slotu (current vs. prev) dle YYYY-MM klíče.
        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $monthsSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }

        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );

        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = [
                'currency'  => $cur,
                'months'    => $toList($data['months']),
                'prev_year' => $toList($data['prev_year']),
            ];
        }
        return $out;
    }

    /**
     * Top klienti za posledních 12 měsíců (rolling window) — robustní vůči začátku roku,
     * kdy by YTD bylo téměř prázdné.
     */
    private function topClientsRolling12m(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Stejné jako topClients() — CZK přepočet + grupování po klientovi pro správný ranking
        // napříč měnami (1000 EUR > 20 000 CZK).
        $revCzk = "$rev * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)";
        $sql = "SELECT c.id, c.company_name,
                       SUM($revCzk) AS total_czk,
                       GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                       COUNT(*) AS invoice_count
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY c.id, c.company_name
                 ORDER BY total_czk DESC
                 LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'client_id'     => (int) $r['id'],
            'company_name'  => $r['company_name'],
            'currencies'    => (string) $r['currencies'],
            'total_czk'     => round((float) $r['total_czk'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Plovoucí 12měsíční obrat (rolling) — součet posledních 12 ukončených měsíců + aktuální měsíc.
     * Relevantní pro sledování limitu DPH (2 mil. CZK / 12 měsíců). Per měna.
     *
     * @return list<array{currency: string, total: float, prev_period_total: float}>
     */
    private function rolling12mRevenue(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $this->revenueCol($isVatPayer);
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $rev ELSE 0 END) AS total_12m,
                       SUM(CASE WHEN COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                                  AND COALESCE(i.tax_date, i.issue_date) <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $rev ELSE 0 END) AS total_prev_12m
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'          => (string) $r['currency'],
            'total'             => round((float) $r['total_12m'], 2),
            'prev_period_total' => round((float) $r['total_prev_12m'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Kumulativní cash-flow (skutečné inkasované platby) per měsíc — letošek + minulý rok.
     * Bere `paid_at` (kdy byla faktura zaplacena), ne issue_date. Per měna.
     *
     * @return list<array{currency: string, months: list<array{ym: string, total: float}>, prev_year: list<array{ym: string, total: float}>}>
     */
    private function cashflowYtd(\PDO $pdo, int $year, int $prevYear, int $sid): array
    {
        // Cash-flow je vždy v měnové hodnotě s DPH (klient zaplatil reálnou částku).
        // Filtr i.status = 'paid' a paid_at NOT NULL.
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(i.paid_at, '%Y-%m') AS ym,
                       SUM(i.total_with_vat) AS total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status = 'paid'
                   AND i.paid_at IS NOT NULL
                   AND YEAR(i.paid_at) IN (?, ?)
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $year, $prevYear]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $thisSlots = [];
        $prevSlots = [];
        for ($m = 1; $m <= 12; $m++) {
            $thisSlots[sprintf('%04d-%02d', $year, $m)] = 0.0;
            $prevSlots[sprintf('%04d-%02d', $prevYear, $m)] = 0.0;
        }
        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $thisSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }
        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );
        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = [
                'currency'  => $cur,
                'months'    => $toList($data['months']),
                'prev_year' => $toList($data['prev_year']),
            ];
        }
        return $out;
    }

    /**
     * Histogram doby úhrady — kolik faktur bylo zaplaceno v jakém časovém okně po vystavení.
     * Okno = posledních 12 měsíců (rolling) ohledně paid_at.
     *
     * Buckets: 0-7 dní (zaplaceno do týdne), 8-14, 15-30, 30+.
     * Záporné dny (paid_at < issue_date — zaplaceno předem) sjednoceně do bucketu "0-7".
     *
     * @return array{buckets: list<array{key: string, label: string, count: int}>, total: int, avg_days: float|null}
     */
    private function paymentDaysHistogram(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT DATEDIFF(paid_at, issue_date) AS days
                  FROM invoices
                 WHERE supplier_id = ?
                   AND status = 'paid'
                   AND paid_at IS NOT NULL
                   AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND invoice_type IN ('invoice', 'credit_note')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $days = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $buckets = [
            '0-7'   => ['key' => '0-7',   'label' => '0–7 dní',  'count' => 0],
            '8-14'  => ['key' => '8-14',  'label' => '8–14 dní', 'count' => 0],
            '15-30' => ['key' => '15-30', 'label' => '15–30 dní', 'count' => 0],
            '30+'   => ['key' => '30+',   'label' => '30+ dní',   'count' => 0],
        ];
        $sum = 0;
        foreach ($days as $d) {
            $d = (int) $d;
            $sum += max(0, $d);
            if ($d <= 7)       $buckets['0-7']['count']++;
            elseif ($d <= 14)  $buckets['8-14']['count']++;
            elseif ($d <= 30)  $buckets['15-30']['count']++;
            else               $buckets['30+']['count']++;
        }
        $total = count($days);
        $avg = $total > 0 ? round($sum / $total, 1) : null;

        return [
            'buckets'  => array_values($buckets),
            'total'    => $total,
            'avg_days' => $avg,
        ];
    }

    /**
     * Rozpad obratu (bez DPH) podle sazby DPH — pro plátce DPH posledních 12 měsíců.
     * Bere `invoice_items.vat_rate_snapshot` jako kotvu. Reverse-charge řádky mají rate=0
     * a vykazují se odděleně přes invoice.reverse_charge.
     *
     * @return list<array{label: string, base: float, currency: string}>
     */
    private function vatBreakdown12m(\PDO $pdo, int $sid): array
    {
        // RC řádky se vyznačují tím, že celá faktura má `reverse_charge = 1`. Identifikujeme je
        // odděleně, aby uživatel rozlišil "skutečné 0 %" (osvobozeno) od "0 % RC".
        $sql = "SELECT cur.code AS currency,
                       CASE WHEN i.reverse_charge = 1 THEN 'RC' ELSE CAST(ii.vat_rate_snapshot AS CHAR) END AS rate_label,
                       SUM(ii.total_without_vat) AS base
                  FROM invoice_items ii
                  JOIN invoices i ON i.id = ii.invoice_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                   AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY cur.code, rate_label
                 ORDER BY cur.code, base DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r): array {
            $label = $r['rate_label'] === 'RC' ? 'RC (reverse charge)' : (rtrim(rtrim((string) $r['rate_label'], '0'), '.')) . ' %';
            return [
                'label'    => $label,
                'base'     => round((float) $r['base'], 2),
                'currency' => (string) $r['currency'],
            ];
        }, $rows);
    }

    /**
     * Kumulativní cash-flow forecast — kolik se očekává inkasovat v příštích 30/60/90 dnech
     * z neuhrazených faktur (status issued/sent/reminded, due_date v daném okně). Per měna.
     *
     * @return list<array{currency: string, in_30: float, in_60: float, in_90: float, count_30: int, count_60: int, count_90: int}>
     */
    private function cashflowForecast(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN i.amount_to_pay ELSE 0 END) AS in_30,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN i.amount_to_pay ELSE 0 END) AS in_60,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN i.amount_to_pay ELSE 0 END) AS in_90,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS count_30,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS count_60,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS count_90
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.invoice_type IN ('invoice','credit_note')
                   AND i.due_date >= CURDATE()
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency' => (string) $r['currency'],
            'in_30'    => round((float) $r['in_30'], 2),
            'in_60'    => round((float) $r['in_60'], 2),
            'in_90'    => round((float) $r['in_90'], 2),
            'count_30' => (int) $r['count_30'],
            'count_60' => (int) $r['count_60'],
            'count_90' => (int) $r['count_90'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Splatnost bucket — kolik faktur je splatných **dnes / tento týden / tento měsíc**.
     * Týden = Po–Ne. Měsíc = do LAST_DAY. Bucket je inkluzivní (today včetně).
     *
     * Buckets jsou **kumulativní** — week zahrnuje today; month zahrnuje week.
     *
     * @return list<array{currency: string, today_count: int, today_total: float, week_count: int, week_total: float, month_count: int, month_total: float}>
     */
    private function dueBuckets(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN i.due_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
                       SUM(CASE WHEN i.due_date = CURDATE() THEN i.amount_to_pay ELSE 0 END) AS today_total,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY) THEN 1 ELSE 0 END) AS week_count,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY) THEN i.amount_to_pay ELSE 0 END) AS week_total,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN 1 ELSE 0 END) AS month_count,
                       SUM(CASE WHEN i.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN i.amount_to_pay ELSE 0 END) AS month_total
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.invoice_type IN ('invoice','credit_note')
                   AND i.due_date >= CURDATE()
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'     => (string) $r['currency'],
            'today_count'  => (int) $r['today_count'],
            'today_total'  => round((float) $r['today_total'], 2),
            'week_count'   => (int) $r['week_count'],
            'week_total'   => round((float) $r['week_total'], 2),
            'month_count'  => (int) $r['month_count'],
            'month_total'  => round((float) $r['month_total'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Aging report — stáří pohledávek (neuhrazených faktur) podle počtu dní po splatnosti.
     * Bucket 'current' = ještě před splatností. Klasický finanční report.
     *
     * @return list<array{currency: string, current: float, b1_30: float, b31_60: float, b61_90: float, b90_plus: float, current_n: int, b1_30_n: int, b31_60_n: int, b61_90_n: int, b90_plus_n: int}>
     */
    private function agingReport(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN i.due_date >= CURDATE() THEN i.amount_to_pay ELSE 0 END) AS current_amt,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.amount_to_pay ELSE 0 END) AS b1_30,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.amount_to_pay ELSE 0 END) AS b31_60,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.amount_to_pay ELSE 0 END) AS b61_90,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.amount_to_pay ELSE 0 END) AS b90_plus,
                       SUM(CASE WHEN i.due_date >= CURDATE() THEN 1 ELSE 0 END) AS current_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN 1 ELSE 0 END) AS b1_30_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS b31_60_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS b61_90_n,
                       SUM(CASE WHEN i.due_date < CURDATE() AND DATEDIFF(CURDATE(), i.due_date) > 90 THEN 1 ELSE 0 END) AS b90_plus_n
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued','sent','reminded')
                   AND i.invoice_type IN ('invoice','credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'  => (string) $r['currency'],
            'current'   => round((float) $r['current_amt'], 2),
            'b1_30'     => round((float) $r['b1_30'], 2),
            'b31_60'    => round((float) $r['b31_60'], 2),
            'b61_90'    => round((float) $r['b61_90'], 2),
            'b90_plus'  => round((float) $r['b90_plus'], 2),
            'current_n' => (int) $r['current_n'],
            'b1_30_n'   => (int) $r['b1_30_n'],
            'b31_60_n'  => (int) $r['b31_60_n'],
            'b61_90_n'  => (int) $r['b61_90_n'],
            'b90_plus_n'=> (int) $r['b90_plus_n'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Forecast ročního obratu — robustní odhad ze tří nezávislých signálů.
     *
     * Starý model měřil růst jako YTD_letos / YTD_loni — tj. z pár měsíců na začátku roku.
     * Krátké okno = šum: slabý loňský start nafoukl růst na stovky % a forecast přestřelil.
     *
     * Nově počítáme tři projekce celého roku a bereme jejich MEDIÁN (odolný vůči jednomu
     * odlehlému signálu); navíc vracíme rozpětí min–max:
     *
     *  1. run-rate                       — ytd / uplynulé_dny × dní_v_roce
     *  2. sezonalita × krátkodobý růst    — ytd + loňský_zbytek × (rolling 12m / předch. 12m)
     *  3. sezonalita × dlouhodobý trend   — ytd + loňský_zbytek × CAGR (posledních ≤5 let)
     *
     * Sezonalita (= loňský zbytek roku) drží tvar roku; růst se měří z dlouhých oken, takže
     * odráží strukturální růst (rostoucí sazby/objem), ne sezónní výkyv krátkého YTD okna.
     * Jak rok postupuje, loňský_zbytek → 0 a všechny tři signály konvergují ke skutečnosti.
     *
     * @param list<array{year: int, currency: string, total: float, invoice_count: int}> $revenueByYear sdílený výsledek revenueByYear() — zdroj CAGR trendu
     * @return list<array{currency: string, ytd: float, prev_year_remainder: float, prev_year_full: float, growth_short: float, growth_trend: float, forecast: float, forecast_low: float, forecast_high: float}>
     */
    private function revenueForecast(\PDO $pdo, int $year, int $prevYear, int $sid, bool $isVatPayer, array $revenueByYear): array
    {
        $rev = $this->revenueCol($isVatPayer);
        // Scope musí pokrýt i rok−2, protože rolling-24m okno (prev_roll_12m) sahá dva roky zpět.
        $prevPrevYear = $prevYear - 1;
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS ytd,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                  AND COALESCE(i.tax_date, i.issue_date) > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $rev ELSE 0 END) AS prev_year_remainder,
                       SUM(CASE WHEN YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
                                 THEN $rev ELSE 0 END) AS prev_year_full,
                       SUM(CASE WHEN COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $rev ELSE 0 END) AS roll_12m,
                       SUM(CASE WHEN COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                                  AND COALESCE(i.tax_date, i.issue_date) <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $rev ELSE 0 END) AS prev_roll_12m
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN (?, ?, ?)
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'credit_note')
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$year, $prevYear, $prevYear, $sid, $year, $prevYear, $prevPrevYear]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Roční totály ukončených let pro CAGR — přetvořené z už načteného revenueByYear (žádný druhý dotaz).
        $yearly = [];
        foreach ($revenueByYear as $r) {
            if ($r['year'] < $year) {
                $yearly[$r['currency']][$r['year']] = $r['total'];
            }
        }

        // Pozice v roce — pro run-rate a přirozenou konvergenci k realitě ke konci roku.
        $today = new \DateTimeImmutable('today');
        $daysElapsed = (int) $today->format('z') + 1;
        $daysInYear = $today->format('L') === '1' ? 366 : 365;

        return array_map(function (array $r) use ($daysElapsed, $daysInYear, $yearly): array {
            $cur = (string) $r['currency'];
            $ytd = round((float) $r['ytd'], 2);
            $rem = round((float) $r['prev_year_remainder'], 2);
            $prevFull = round((float) $r['prev_year_full'], 2);
            $roll12 = (float) $r['roll_12m'];
            $prevRoll12 = (float) $r['prev_roll_12m'];

            // Krátkodobý růst: rolling 12m vs. předchozích 12m. Plná okna → bez sezónního zkreslení.
            // Cap [0.5, 2.5] drží projekci v rozumných mezích i při raketovém/propadovém roce.
            $gShort = $prevRoll12 > 0 ? max(0.5, min(2.5, $roll12 / $prevRoll12)) : 1.0;
            // Dlouhodobý trend (CAGR). Cap [0.8, 1.8] je užší než u krátkodobého růstu — trend z více
            // let je hladší, takže extrémy nečekáme. Bez dostatku historie fallback na krátkodobý růst.
            $gTrendRaw = $this->trendGrowth($yearly[$cur] ?? []);
            $gTrend = $gTrendRaw !== null ? max(0.8, min(1.8, $gTrendRaw)) : $gShort;

            $runRate = $daysElapsed > 0 ? $ytd / $daysElapsed * $daysInYear : $ytd;

            // Bez loňských dat nemá sezonalita o co se opřít → jediný smysluplný signál je run-rate.
            if ($prevFull <= 0.0) {
                $fc = round($runRate, 2);
                return [
                    'currency' => $cur, 'ytd' => $ytd, 'prev_year_remainder' => $rem,
                    'prev_year_full' => $prevFull, 'growth_short' => round($gShort, 3),
                    'growth_trend' => round($gTrend, 3), 'forecast' => $fc,
                    'forecast_low' => $fc, 'forecast_high' => $fc,
                ];
            }

            $signals = [
                $runRate,                // run-rate (lineární extrapolace)
                $ytd + $rem * $gShort,   // sezonalita × krátkodobý růst
                $ytd + $rem * $gTrend,   // sezonalita × dlouhodobý trend
            ];
            sort($signals);

            return [
                'currency'            => $cur,
                'ytd'                 => $ytd,
                'prev_year_remainder' => $rem,
                'prev_year_full'      => $prevFull,
                'growth_short'        => round($gShort, 3),
                'growth_trend'        => round($gTrend, 3),
                'forecast'            => round($signals[1], 2),
                'forecast_low'        => round($signals[0], 2),
                'forecast_high'       => round($signals[2], 2),
            ];
        }, $rows);
    }

    /**
     * CAGR (geometrický průměrný roční růst) z posledních ≤5 ukončených let.
     * Vrací růstový faktor (1.25 = +25 %/rok), nebo null když není dost dat (< 2 roky).
     *
     * @param array<int, float> $byYear year => total
     */
    private function trendGrowth(array $byYear): ?float
    {
        $byYear = array_filter($byYear, static fn (float $t): bool => $t > 0);
        if (count($byYear) < 2) {
            return null;
        }
        ksort($byYear);
        $window = array_slice($byYear, -5, null, true);
        $firstYear = array_key_first($window);
        $lastYear = array_key_last($window);
        $span = $lastYear - $firstYear;
        if ($span < 1 || $window[$firstYear] <= 0) {
            return null;
        }
        return ($window[$lastYear] / $window[$firstYear]) ** (1.0 / $span);
    }

    /**
     * Histogram velikosti faktur — distribuce za posledních 12 měsíců.
     * Buckets jsou pevné v primární měně (CZK): 0-5k / 5-25k / 25-100k / 100k+.
     * Pro non-CZK fakturu se použije `total_with_vat` převedený přes uložený `exchange_rate`
     * na CZK-ekvivalent.
     *
     * @return array{buckets: list<array{key: string, label: string, count: int, total_czk: float}>, total: int}
     */
    private function invoiceSizeHistogram(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $rev = $isVatPayer ? 'total_without_vat' : 'total_with_vat';
        // Pro non-CZK fakturu = total * COALESCE(exchange_rate, 1).
        $sql = "SELECT $rev * COALESCE(exchange_rate, 1) AS size_czk
                  FROM invoices
                 WHERE supplier_id = ?
                   AND status IN ('issued', 'sent', 'reminded', 'paid')
                   AND invoice_type IN ('invoice', 'credit_note')
                   AND COALESCE(tax_date, issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $sizes = array_map(static fn ($v) => (float) $v, $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $buckets = [
            '0-5'    => ['key' => '0-5',   'label' => '0–5 k', 'count' => 0, 'total_czk' => 0.0],
            '5-25'   => ['key' => '5-25',  'label' => '5–25 k', 'count' => 0, 'total_czk' => 0.0],
            '25-100' => ['key' => '25-100','label' => '25–100 k', 'count' => 0, 'total_czk' => 0.0],
            '100+'   => ['key' => '100+',  'label' => '100 k+', 'count' => 0, 'total_czk' => 0.0],
        ];
        foreach ($sizes as $s) {
            $abs = abs($s);
            if      ($abs <  5000)   $key = '0-5';
            elseif  ($abs <  25000)  $key = '5-25';
            elseif  ($abs <  100000) $key = '25-100';
            else                     $key = '100+';
            $buckets[$key]['count']++;
            $buckets[$key]['total_czk'] += $s;
        }
        foreach ($buckets as &$b) { $b['total_czk'] = round($b['total_czk'], 2); }
        unset($b);

        return [
            'buckets' => array_values($buckets),
            'total'   => count($sizes),
        ];
    }

    private function castListItem(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'varsymbol'           => $r['varsymbol'],
            'invoice_type'        => $r['invoice_type'],
            'client_id'           => (int) $r['client_id'],
            'client_company_name' => $r['client_company_name'],
            'currency'            => $r['currency'],
            'issue_date'          => $r['issue_date'],
            'due_date'            => $r['due_date'],
            'amount_to_pay'       => (float) $r['amount_to_pay'],
            'status'              => $r['status'],
            'days_overdue'        => isset($r['days_overdue']) ? (int) $r['days_overdue'] : null,
        ];
    }
}
