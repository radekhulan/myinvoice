<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateInvoiceAction
{
    use HandlesVarsymbolDuplicate;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly InvoicePdfRenderer $pdf,
        private readonly VatClassificationDefaulter $vatDefaulter,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isForce = $request->getQueryParams()['force'] ?? null;
        $isAdmin = (($user['role'] ?? '') === 'admin');

        if ($existing['status'] !== 'draft') {
            // Pouze admin smí upravovat vystavenou fakturu, a to jen s explicit ?force=1.
            if (!$isAdmin || !$isForce) {
                return Json::error($response, 'not_editable', 'Vystavenou fakturu nelze editovat.', 409);
            }
            // Cancellation/credit_note jsou implicitně chráněné (auditní stopa)
            if (in_array($existing['invoice_type'], ['cancellation'], true)) {
                return Json::error($response, 'not_editable', 'Storno doklad nelze editovat.', 409);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // parent_invoice_id se nikdy nemění při update (vazba dobropisu na původní doklad).
        $body['parent_invoice_id'] = $existing['parent_invoice_id'];
        // Typ: u VYSTAVENÉ faktury je immutable (číslo + auditní stopa). U DRAFTu ho lze přepnout
        // (faktura ↔ proforma ↔ dobropis), ale nikdy ne na storno/cancellation.
        if ($existing['status'] !== 'draft'
            || !in_array((string) ($body['invoice_type'] ?? ''), ['invoice', 'proforma', 'credit_note'], true)) {
            $body['invoice_type'] = $existing['invoice_type'];
        }
        // Varsymbol lze měnit jen u draftu — vystavená faktura má číslo immutable
        // (součást snapshotu pro účetní evidenci a PDF). Force=1 admin override
        // ho neodemyká — pokud chce změnit číslo, musí vytvořit dobropis nebo storno.
        if ($existing['status'] !== 'draft') {
            unset($body['varsymbol']);
        }
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = InvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Auto-default VAT klasifikace pokud user nezadal (s multi-tenant scope)
        $this->applyVatClassificationDefaults($body, \MyInvoice\Http\SupplierGuard::currentId($request));

        try {
            $this->repo->updateDraft($id, $body);
        } catch (\PDOException $e) {
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);

        // Exchange rate logika:
        //   1. User manuálně nastavil kurz v payloadu → uložit (ruční override má prioritu)
        //   2. Vystavená faktura (force-edit) — NIKDY auto-přefetch (klient ji už má)
        //   3. Draft + změna currency NEBO issue_date → fetch nový kurz
        //   4. Jinak → kurz beze změny, jen ensureRate pro backfill když chybí
        $wasDraft = $existing['status'] === 'draft';
        $currencyChanged = (int) ($existing['currency_id'] ?? 0) !== (int) ($body['currency_id'] ?? 0);
        $issueDateChanged = (string) ($existing['issue_date'] ?? '') !== (string) ($body['issue_date'] ?? '');
        $rateMeta = null;

        $userRate = $body['exchange_rate'] ?? null;
        $userRateProvided = $userRate !== null && $userRate !== '' && is_numeric($userRate) && (float) $userRate > 0;

        if ($userRateProvided) {
            // Manuální override z UI — preserve s issue_date jako rate_date
            $this->repo->setExchangeRate($id, (float) $userRate, (string) $body['issue_date']);
        } elseif ($wasDraft && ($currencyChanged || $issueDateChanged)) {
            $rateMeta = $this->rateApplier->applyToInvoice($id);
        } else {
            $this->rateApplier->ensureRate($id);
        }

        // Force update vystavené faktury → revenue cache musí přijmout nové total/currency
        $this->stats->recomputeForInvoiceId($id);

        // Invalidate cached PDF — data faktury se změnila, starý soubor je nepoužitelný.
        // Cache freshness check v rendereru zohledňuje jen mtime šablon/CSS, ne dat,
        // takže bez explicit invalidate by se starý PDF dál servíroval.
        $this->pdf->invalidate($id, 'invalidate_update');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'invoice.force_updated' : 'invoice.updated';
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id);
        if ($rateMeta !== null) {
            $invoice['_meta'] = ['exchange_rate' => $rateMeta];
        }
        return Json::ok($response, $invoice);
    }

    /**
     * Auto-default vat_classification_code (sale direction) podle vat_rate na řádcích a header.
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);
        // Country-aware RC: tuzemský odběratel → §92a (ř.25), zahraniční EU → dodání do JČS (ř.20).
        $customerEuForeign = $reverseCharge
            && (int) ($body['client_id'] ?? 0) > 0
            && $this->repo->clientIsEuForeign((int) $body['client_id']);

        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as &$item) {
                if (!empty($item['vat_classification_code'])) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign);
            }
            unset($item);
        }

        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100)];
            }, (array) $body['items']);
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'sale',
                $body['tax_date'] ?? $body['issue_date'] ?? null,
                $supplierId,
                $customerEuForeign,
            );
        }
    }
}
