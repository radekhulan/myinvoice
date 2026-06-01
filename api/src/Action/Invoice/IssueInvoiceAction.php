<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přechod draft → issued:
 *  1. Vygeneruje varsymbol (atomicky)
 *  2. Zapíše snapshots (client, supplier, bank)
 *  3. Status = issued
 *
 * Po issued už faktura nelze editovat — jen storno/dobropis/mark-paid.
 */
final class IssueInvoiceAction
{
    use HandlesVarsymbolDuplicate;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly WorkReportRepository $workReports,
        private readonly InvoicePdfRenderer $pdfRenderer,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] !== 'draft') {
            return Json::error($response, 'not_draft', 'Lze vystavit jen draft fakturu.', 409);
        }
        if (count($invoice['items']) === 0) {
            return Json::error($response, 'no_items', 'Faktura musí obsahovat alespoň jednu položku.', 422);
        }
        if (
            InvoiceAmountPolicy::requiresPositiveDraftAmountToPay(
                (string) ($invoice['invoice_type'] ?? 'invoice'),
                $invoice['parent_invoice_id'] ?? null,
            )
            && !InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)
        ) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE, 409);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'invalid_type', 'Storno nedostává varsymbol.', 422);
        }

        // Pokud projekt vyžaduje schválení výkazu A faktura má výkaz, musí být approved.
        // Faktury bez výkazu (např. fixní paušál) lze vystavit i u projektu s requires_approval.
        if (!empty($invoice['project_requires_approval'])
            && ($invoice['approval_status'] ?? 'none') !== 'approved'
            && $this->workReports->findByInvoice($id) !== null
        ) {
            return Json::error(
                $response,
                'approval_required',
                'Tato zakázka vyžaduje schválení výkazu zákazníkem před vystavením faktury.',
                409,
            );
        }

        $issueDate = new \DateTimeImmutable($invoice['issue_date']);

        $supplierId = (int) $invoice['supplier_id'];

        // Pokud byl draft ručně očíslován (varsymbol zadaný v editoru), respektuj override
        // a NEinkremenetuj counter. Jen ověříme unikátnost v rámci supplier scope.
        $manualVarsymbol = trim((string) ($invoice['varsymbol'] ?? ''));
        if ($manualVarsymbol !== '') {
            $dup = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? AND id != ? LIMIT 1'
            );
            $dup->execute([$supplierId, $manualVarsymbol, $id]);
            if ($dup->fetchColumn()) {
                return Json::error(
                    $response,
                    'varsymbol_duplicate',
                    "Číslo '{$manualVarsymbol}' už existuje u jiné faktury tohoto dodavatele.",
                    409,
                );
            }
            $varsymbol = $manualVarsymbol;
        } else {
            try {
                $varsymbol = $this->varsymbol->next($supplierId, $invoice['invoice_type'], $issueDate, (int) $invoice['client_id']);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                return Json::error($response, 'varsymbol_failed', $e->getMessage(), 500);
            }
        }

        try {
            $snapshots = $this->snapshots->build((int) $invoice['client_id'], (int) $invoice['currency_id'], $supplierId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'snapshot_failed', $e->getMessage(), 500);
        }

        // Finální daňový doklad plně pokrytý zálohou (amount_to_pay <= 0) je fakticky
        // zaplacený už při vystavení — záloha dorazila dřív. Označíme ho rovnou jako
        // 'paid' (paid_at = issue_date dokladu, datum se váže na daňový doklad, ne na
        // proformu), jinak by zbytečně visel jako nezaplacený/po splatnosti a reálné
        // inkaso by chybělo v kasových reportech (cash-flow, limit paušální daně), které
        // sčítají daňové doklady, ne proformy. Detail podmínky viz InvoiceAmountPolicy.
        $autoPaid = InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue($invoice);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = ?,
                paid_at           = ?
             WHERE id = ? AND status = "draft"'
        );
        try {
            $stmt->execute([
                $varsymbol,
                json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
                json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
                $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
                $autoPaid ? 'paid' : 'issued',
                $autoPaid ? $invoice['issue_date'] : null,
                $id,
            ]);
        } catch (\PDOException $e) {
            // Poslední pojistka proti porušení unique indexu (supplier_id, varsymbol) — typicky
            // souběžné vystavení nebo číslo, které proklouzlo kontrolami. Generátor se sice
            // duplicitám aktivně vyhýbá, ale DB constraint je definitivní ochrana proti race.
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $varsymbol)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'race_condition', 'Faktura byla mezitím změněna.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.issued', $user['id'] ?? null, 'invoice', $id, [
            'varsymbol' => $varsymbol,
            'type'      => $invoice['invoice_type'],
            'total'     => $invoice['total_with_vat'],
            'currency'  => $invoice['currency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        if ($autoPaid) {
            $this->logger->log('invoice.paid', $user['id'] ?? null, 'invoice', $id, [
                'paid_at' => $invoice['issue_date'],
                'trigger' => 'advance_fully_covered',
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        $this->stats->recomputeForInvoiceId($id);
        // Smaž cached draft PDF (Faktura-draft-NN.pdf) — po vystavení má faktura nový
        // varsymbol a snapshoty, takže staré cached PDF už neodpovídá.
        $this->pdfRenderer->invalidate($id, 'invalidate_issue');

        return Json::ok($response, $this->repo->find($id));
    }
}
