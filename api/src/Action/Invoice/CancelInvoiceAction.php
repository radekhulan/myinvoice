<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Oss\OssPeriod;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Storno vystavené faktury. Dvě varianty:
 *
 *  - mode=internal      → vytvoří `cancellation` záznam s parent. Původní invoice
 *                         dostane `cancelled_at` a status='cancelled'. Žádný doklad
 *                         pro klienta.
 *
 *  - mode=credit_note   → vytvoří DRAFT typu `credit_note` se zápornými položkami.
 *                         User musí v editoru zkontrolovat a zavolat /issue.
 *                         Původní status zůstává až do vystavení dobropisu.
 */
final class CancelInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded', 'paid'], true)) {
            return Json::error($response, 'invalid_state', 'Lze zrušit jen vystavenou/odeslanou/zaplacenou fakturu.', 409);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'invalid_type', 'Stornovací doklad nelze stornovat.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? '');
        $reason = (string) ($body['reason'] ?? '');

        // Dobropisu nelze vystavit další dobropis — povolen jen interní storno.
        if ($invoice['invoice_type'] === 'credit_note' && $mode !== 'internal') {
            return Json::error($response, 'invalid_mode', 'Dobropis lze stornovat pouze interně.', 409);
        }

        if ($mode === 'internal') {
            return $this->internalCancel($request, $response, $invoice, $reason);
        }
        if ($mode === 'credit_note') {
            return $this->createCreditNote($request, $response, $invoice, $reason);
        }

        return Json::error($response, 'invalid_mode', 'mode musí být "internal" nebo "credit_note".', 400);
    }

    private function internalCancel(Request $request, Response $response, array $invoice, string $reason): Response
    {
        $pdo = $this->db->pdo();
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $pdo->beginTransaction();
        try {
            // 1. Vytvoř cancellation záznam (interní, bez varsymbolu)
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, language,
                    note_above_items, revenue_category_id, status, created_by)
                 VALUES ("cancellation", ?, ?, ?, ?, ?, CURDATE(), NULL, CURDATE(), ?, ?, ?, ?, "issued", ?)'
            );
            $stmt->execute([
                $invoice['id'],
                $invoice['client_id'],
                $invoice['project_id'],
                (int) $invoice['supplier_id'],
                $invoice['branding_profile_id'] ?? null,
                (int) $invoice['currency_id'],
                $invoice['language'],
                $reason !== '' ? "Storno faktury {$invoice['varsymbol']}: $reason" : null,
                // Storno mirror dědí kategorii tržby původní faktury.
                $invoice['revenue_category_id'] ?? null,
                $userId,
            ]);
            $cancellationId = (int) $pdo->lastInsertId();

            // 2. Označ původní fakturu jako cancelled
            $pdo->prepare('UPDATE invoices SET status = "cancelled", cancelled_at = NOW() WHERE id = ?')
                ->execute([$invoice['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'cancel_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.cancelled', $userId, 'invoice', $invoice['id'], [
            'mode' => 'internal',
            'cancellation_id' => $cancellationId,
            'reason' => $reason,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Stornovaná faktura odejde z revenue cache
        $this->stats->recomputeForInvoiceId((int) $invoice['id']);

        return Json::ok($response, [
            'cancellation_id' => $cancellationId,
            'invoice'         => $this->repo->find($invoice['id']),
        ]);
    }

    private function createCreditNote(Request $request, Response $response, array $invoice, string $reason): Response
    {
        $pdo = $this->db->pdo();
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $supportsOss = $this->db->hasColumn('invoice_items', 'oss_applicable');
        $sourcePeriod = OssPeriod::quarterCode((string) ($invoice['tax_date'] ?? $invoice['issue_date'] ?? ''));
        $creditPeriod = OssPeriod::quarterCode(date('Y-m-d'));
        $defaultOriginalPeriod = $sourcePeriod !== null && $creditPeriod !== null && $sourcePeriod < $creditPeriod
            ? $sourcePeriod
            : null;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, revenue_category_id, status, created_by)
                 VALUES ("credit_note", ?, ?, ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $invoice['id'],
                $invoice['client_id'],
                $invoice['project_id'],
                (int) $invoice['supplier_id'],
                $invoice['branding_profile_id'] ?? null,
                (int) $invoice['currency_id'],
                $invoice['reverse_charge'] ? 1 : 0,
                // Dobropis musí dědit režim „ceny s DPH" — jinak by se zkopírované brutto
                // jednotkové ceny přepočítaly jako netto (nafouknuté totály dobropisu).
                !empty($invoice['prices_include_vat']) ? 1 : 0,
                $invoice['language'],
                $reason !== '' ? "Dobropis k faktuře {$invoice['varsymbol']}: $reason" : "Dobropis k faktuře {$invoice['varsymbol']}",
                // Dobropis dědí kategorii tržby původní faktury (záporná tržba ve stejné kategorii).
                $invoice['revenue_category_id'] ?? null,
                $userId,
            ]);
            $creditNoteId = (int) $pdo->lastInsertId();

            // Zkopíruj položky se zápornými quantities — zachovává vat_classification_code
            // (dobropis má jít na stejné DPH řádky jako originální faktura, ale se zápornými hodnotami)
            $itemStmt = $supportsOss
                ? $pdo->prepare(
                    'INSERT INTO invoice_items
                       (invoice_id, description, quantity, unit, unit_price_without_vat,
                        vat_rate_id, vat_rate_snapshot,
                        total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code,
                        oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type,
                        oss_exchange_rate, oss_exchange_rate_date, oss_taxable_amount_return,
                        oss_vat_amount_return, oss_original_period)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )
                : $pdo->prepare(
                    'INSERT INTO invoice_items
                       (invoice_id, description, quantity, unit, unit_price_without_vat,
                        vat_rate_id, vat_rate_snapshot,
                        total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)'
                );
            foreach ($invoice['items'] as $item) {
                $code = $item['vat_classification_code']
                    ?? \MyInvoice\Repository\InvoiceRepository::defaultSaleClassificationCode(
                        (float) $item['vat_rate_snapshot'],
                        (bool) ($invoice['reverse_charge'] ?? false),
                    );
                $params = [
                    $creditNoteId,
                    $item['description'],
                    -1 * (float) $item['quantity'],   // záporné množství
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                    $code !== null ? (string) $code : null,
                ];
                if ($supportsOss) {
                    $ossApplicable = !empty($item['oss_applicable']);
                    $originalPeriod = trim((string) ($item['oss_original_period'] ?? '')) ?: $defaultOriginalPeriod;
                    if ($originalPeriod !== null && $creditPeriod !== null && $originalPeriod >= $creditPeriod) {
                        $originalPeriod = $defaultOriginalPeriod;
                    }
                    array_push(
                        $params,
                        $ossApplicable ? 1 : 0,
                        $ossApplicable ? ($item['oss_consumer_country'] ?? null) : null,
                        $ossApplicable ? ($item['oss_rate_type'] ?? null) : null,
                        $ossApplicable ? ($item['oss_supply_type'] ?? null) : null,
                        $ossApplicable ? ($item['oss_exchange_rate'] ?? null) : null,
                        $ossApplicable ? ($item['oss_exchange_rate_date'] ?? null) : null,
                        $ossApplicable && ($item['oss_taxable_amount_return'] ?? null) !== null
                            ? -1 * (float) $item['oss_taxable_amount_return']
                            : null,
                        $ossApplicable && ($item['oss_vat_amount_return'] ?? null) !== null
                            ? -1 * (float) $item['oss_vat_amount_return']
                            : null,
                        $ossApplicable ? $originalPeriod : null,
                    );
                }
                $itemStmt->execute($params);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'credit_note_failed', $e->getMessage(), 500);
        }

        // Recompute sumy nového dobropisu
        $this->calc->recompute($creditNoteId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.credit_note_created', $userId, 'invoice', $invoice['id'], [
            'credit_note_id' => $creditNoteId,
            'reason'         => $reason,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Credit note je draft (revenue se nepočítá), ale parent dostal cancelled stav až po issue dobropisu — nepřepočítáváme tady.
        return Json::ok($response, [
            'credit_note_id' => $creditNoteId,
            'edit_url'       => "/invoices/$creditNoteId/edit",
        ], 201);
    }

}
