<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ProjectRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $project = $this->repo->find($id);
        if ($project === null || (int) ($project['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE project_id = ?');
        $stmt->execute([$id]);
        $project['invoices_count'] = (int) $stmt->fetchColumn();

        // VAT-aware obrat — plátci DPH vidí čísla bez DPH (relevantní pro DPH limit),
        // neplátci s DPH (fakturované částky odpovídají reálnému inkasu).
        $vatStmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $vatStmt->execute([$sid]);
        $rev = ((bool) $vatStmt->fetchColumn()) ? 'i.total_without_vat' : 'i.total_with_vat';

        // Obrat po měsících za posledních 24 měsíců.
        // Zahrnuje invoice + credit_note (dobropis má záporné částky, automaticky odečte).
        // Vyloučeno: koncepty (draft), zálohovky (proforma), storno (cancelled), interní cancellation.
        $stmtM = $pdo->prepare(
            "SELECT DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS month,
                    cur.code AS currency, SUM($rev) AS total
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.project_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
                AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
              GROUP BY month, cur.code
              ORDER BY month"
        );
        $stmtM->execute([$id]);
        $project['revenue_by_month'] = array_map(
            fn (array $r) => ['month' => $r['month'], 'currency' => $r['currency'], 'total' => (float) $r['total']],
            $stmtM->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Obrat po letech — stejná pravidla jako revenue_by_month: invoice + credit_note,
        // vyloučí draft/proforma/cancelled/cancellation.
        $stmtY = $pdo->prepare(
            "SELECT YEAR(COALESCE(i.tax_date, i.issue_date)) AS year,
                    cur.code AS currency, SUM($rev) AS total, COUNT(*) AS count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.project_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note')
              GROUP BY year, cur.code
              ORDER BY year DESC"
        );
        $stmtY->execute([$id]);
        $project['revenue_by_year'] = array_map(
            fn (array $r) => [
                'year' => (int) $r['year'],
                'currency' => $r['currency'],
                'total' => (float) $r['total'],
                'count' => (int) $r['count'],
            ],
            $stmtY->fetchAll(\PDO::FETCH_ASSOC)
        );

        // Nezaplaceno + Po splatnosti per měna. _czk fieldy přes i.exchange_rate
        // pro multi-currency projekt — UI agreguje napříč měnami v CZK.
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
              WHERE i.project_id = ?
                AND i.status IN ('issued','sent','reminded')
                AND i.invoice_type IN ('invoice','credit_note')
                -- Finální doklad k zaplacené proformě má amount_to_pay = 0 by design;
                -- není pohledávka (dobropisy se záporným totálem ponecháváme).
                AND (i.invoice_type NOT IN ('invoice','proforma') OR i.amount_to_pay > 0)
              GROUP BY cur.code"
        );
        $stmtU->execute([$id]);
        $project['unpaid_summary'] = array_map(
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

        return Json::ok($response, $project);
    }
}
