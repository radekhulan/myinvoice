<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $client = $this->repo->find($id);
        if ($client === null || (int) ($client['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }
        $client['projects'] = $this->repo->projectsForClient($id);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
        $stmt->execute([$id]);
        $client['invoices_count'] = (int) $stmt->fetchColumn();

        // Pro lock UI v ClientForm: víme-li že klient má vydané/přijaté faktury, nelze
        // vypnout odpovídající role flag (server stejně refusne, ale UI to disable proaktivně).
        $stmtP = $pdo->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE vendor_id = ?');
        $stmtP->execute([$id]);
        $client['purchase_invoices_count'] = (int) $stmtP->fetchColumn();

        // VAT-aware obrat — plátci DPH vidí čísla bez DPH (relevantní pro DPH limit),
        // neplátci s DPH (fakturované částky odpovídají reálnému inkasu).
        $vatStmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $vatStmt->execute([$sid]);
        $rev = ((bool) $vatStmt->fetchColumn()) ? 'i.total_without_vat' : 'i.total_with_vat';

        // CZK přepočet: pro klienty s multi-currency obratem chceme graf v jedné měně.
        // i.exchange_rate je CZK / 1 jednotka cizí měny, fixovaná k DUZP (CNB).
        // CZK řádky mají exchange_rate buď NULL nebo 1 → COALESCE(.., 1) ošetří oba případy.
        $revCzk = "$rev * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)";

        // Obrat po měsících za posledních 24 měsíců.
        // Zahrnuje invoice + credit_note (dobropis má záporné částky, automaticky odečte).
        // Vyloučeno: koncepty (draft), zálohovky (proforma), storno (cancelled), interní cancellation.
        $stmtM = $pdo->prepare(
            "SELECT DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS month,
                    cur.code AS currency, SUM($rev) AS total, SUM($revCzk) AS total_czk
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.client_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
              GROUP BY month, cur.code
              ORDER BY month"
        );
        $stmtM->execute([$id]);
        $client['revenue_by_month'] = array_map(
            fn (array $r) => [
                'month'     => $r['month'],
                'currency'  => $r['currency'],
                'total'     => (float) $r['total'],
                'total_czk' => (float) $r['total_czk'],
            ],
            $stmtM->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Obrat po letech — stejná pravidla jako revenue_by_month: invoice + credit_note,
        // vyloučí draft/proforma/cancelled/cancellation.
        $stmtY = $pdo->prepare(
            "SELECT YEAR(COALESCE(i.tax_date, i.issue_date)) AS year,
                    cur.code AS currency, SUM($rev) AS total, SUM($revCzk) AS total_czk, COUNT(*) AS count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.client_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
              GROUP BY year, cur.code
              ORDER BY year DESC"
        );
        $stmtY->execute([$id]);
        $client['revenue_by_year'] = array_map(
            fn (array $r) => [
                'year'      => (int) $r['year'],
                'currency'  => $r['currency'],
                'total'     => (float) $r['total'],
                'total_czk' => (float) $r['total_czk'],
                'count'     => (int) $r['count'],
            ],
            $stmtY->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Obrat po zakázkách — pro graf "Obrat podle zakázek" v detailu klienta.
        // Stejná pravidla jako revenue_by_month / revenue_by_year.
        // Faktury bez project_id se agregují pod label "(bez zakázky)" (project_id NULL).
        $stmtP = $pdo->prepare(
            "SELECT i.project_id, p.name AS project_name,
                    cur.code AS currency, SUM($rev) AS total, SUM($revCzk) AS total_czk, COUNT(*) AS count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
          LEFT JOIN projects p ON p.id = i.project_id
              WHERE i.client_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
              GROUP BY i.project_id, p.name, cur.code
              ORDER BY total DESC"
        );
        $stmtP->execute([$id]);
        $client['revenue_by_project'] = array_map(
            fn (array $r) => [
                'project_id'   => $r['project_id'] !== null ? (int) $r['project_id'] : null,
                'project_name' => $r['project_name'],
                'currency'     => $r['currency'],
                'total'        => (float) $r['total'],
                'total_czk'    => (float) $r['total_czk'],
                'count'        => (int) $r['count'],
            ],
            $stmtP->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Nezaplaceno (issued/sent + invoice/credit_note) + Po splatnosti per měna.
        // _czk fieldy: stejný přepočet jako revenue_by_*, pro UI agregaci v multi-ccy scénáři.
        $stmtU = $pdo->prepare(
            "SELECT cur.code AS currency,
                    SUM(i.amount_to_pay) AS unpaid_total,
                    SUM(i.amount_to_pay * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS unpaid_total_czk,
                    COUNT(*) AS unpaid_count,
                    SUM(CASE WHEN i.due_date <= CURDATE() THEN i.amount_to_pay ELSE 0 END) AS overdue_total,
                    SUM(CASE WHEN i.due_date <= CURDATE()
                             THEN i.amount_to_pay * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)
                             ELSE 0 END) AS overdue_total_czk,
                    SUM(CASE WHEN i.due_date <= CURDATE() THEN 1 ELSE 0 END) AS overdue_count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.client_id = ?
                AND i.status IN ('issued','sent','reminded')
                AND i.invoice_type IN ('invoice','credit_note')
                -- Finální doklad k zaplacené proformě má amount_to_pay = 0 by design;
                -- není pohledávka (dobropisy se záporným totálem ponecháváme).
                AND (i.invoice_type NOT IN ('invoice','proforma') OR i.amount_to_pay > 0)
              GROUP BY cur.code"
        );
        $stmtU->execute([$id]);
        // Náklady (přijaté faktury od tohoto klienta v roli vendor).
        // Agregujeme server-side aby čísla nebyla závislá na paginaci listu — frontend
        // dříve počítal z prvních N načtených purchase faktur, takže při per_page=20
        // (cfg.php nastavení) ukazoval jen 3 z 11 faktur pro rok 2024.
        //
        // Pravidla: total_with_vat (jak jsme platili), vyloučit draft/cancelled.
        // Spárované/zaplacené zálohy (advance) vyřazujeme — náklad nese vyúčtovací
        // faktura, jinak by se započítaly 2× (shoda s CRM sp_recompute_crm_monthly_summary
        // a CrmAggregationService::topVendors).
        // total_czk přes pi.exchange_rate (CZK fakturám necháme 1 přes COALESCE).
        $stmtCM = $pdo->prepare(
            "SELECT DATE_FORMAT(pi.issue_date, '%Y-%m') AS month,
                    cur.code AS currency,
                    SUM(pi.total_with_vat) AS total,
                    SUM(pi.total_with_vat * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS total_czk
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.vendor_id = ?
                AND pi.supplier_id = ?
                AND pi.status NOT IN ('draft', 'cancelled')
                AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                         AND (pi.status = 'paid'
                              OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                          WHERE adv_s.advance_purchase_invoice_id = pi.id)))
                AND pi.issue_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
              GROUP BY month, cur.code
              ORDER BY month"
        );
        $stmtCM->execute([$id, $sid]);
        $client['costs_by_month'] = array_map(
            fn (array $r) => [
                'month'     => $r['month'],
                'currency'  => $r['currency'],
                'total'     => (float) $r['total'],
                'total_czk' => (float) $r['total_czk'],
            ],
            $stmtCM->fetchAll(\PDO::FETCH_ASSOC)
        );

        $stmtCY = $pdo->prepare(
            "SELECT YEAR(pi.issue_date) AS year,
                    cur.code AS currency,
                    SUM(pi.total_with_vat) AS total,
                    SUM(pi.total_with_vat * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)) AS total_czk,
                    COUNT(*) AS count
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.vendor_id = ?
                AND pi.supplier_id = ?
                AND pi.status NOT IN ('draft', 'cancelled')
                AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                         AND (pi.status = 'paid'
                              OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                          WHERE adv_s.advance_purchase_invoice_id = pi.id)))
              GROUP BY year, cur.code
              ORDER BY year DESC"
        );
        $stmtCY->execute([$id, $sid]);
        $client['costs_by_year'] = array_map(
            fn (array $r) => [
                'year'      => (int) $r['year'],
                'currency'  => $r['currency'],
                'total'     => (float) $r['total'],
                'total_czk' => (float) $r['total_czk'],
                'count'     => (int) $r['count'],
            ],
            $stmtCY->fetchAll(\PDO::FETCH_ASSOC)
        );

        $client['unpaid_summary'] = array_map(
            fn (array $r) => [
                'currency'           => $r['currency'],
                'unpaid_total'       => (float) $r['unpaid_total'],
                'unpaid_total_czk'   => (float) $r['unpaid_total_czk'],
                'unpaid_count'       => (int) $r['unpaid_count'],
                'overdue_total'      => (float) $r['overdue_total'],
                'overdue_total_czk'  => (float) $r['overdue_total_czk'],
                'overdue_count'      => (int) $r['overdue_count'],
            ],
            $stmtU->fetchAll(\PDO::FETCH_ASSOC)
        );

        return Json::ok($response, $client);
    }
}
