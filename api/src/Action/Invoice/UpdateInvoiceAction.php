<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
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
        private readonly VarsymbolGenerator $varsymbol,
        private readonly Connection $db,
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

        $validTypes    = ['invoice', 'proforma', 'credit_note'];
        $requestedType = (string) ($body['invoice_type'] ?? '');
        $isIssued      = $existing['status'] !== 'draft';
        $existingType  = (string) $existing['invoice_type'];

        // Změna TYPU u VYSTAVENÉ faktury (force-edit, admin) = přečíslování.
        // Každý typ má vlastní číselnou řadu, takže staré číslo uvolníme z původní
        // řady (releaseIfLatest — dekrement counteru, je-li poslední) a přidělíme nové
        // v řadě cílového typu (next()). Typické užití: vystavená zálohová faktura
        // (proforma) → faktura. U draftu se typ mění bez přečíslování (číslo se přidělí
        // až při vystavení), takže ten jede beze změny v else větvi.
        $renumber = null;
        if ($isIssued
            && in_array($requestedType, $validTypes, true)
            && $requestedType !== $existingType
            && in_array($existingType, $validTypes, true)  // ne cancellation/tax_document
        ) {
            $supplierId = (int) ($existing['supplier_id'] ?? 0);
            $oldVs      = (string) ($existing['varsymbol'] ?? '');

            // Pojistka proti dvojímu zdanění: zálohovou fakturu s navázaným finálem nebo
            // daňovým dokladem k platbě nelze in-place překlopit na fakturu — § 37a odpočty
            // jsou zafixované a stejná úplata by se zdanila podruhé. Admin musí nejdřív
            // navázané doklady rozpojit/stornovat (nebo použít standardní vyúčtování zálohy).
            if ($existingType === 'proforma') {
                $link = $this->db->pdo()->prepare(
                    "SELECT 1 FROM invoices
                      WHERE parent_invoice_id = ? AND invoice_type IN ('invoice', 'tax_document')
                        AND status <> 'cancelled' LIMIT 1"
                );
                $link->execute([$id]);
                if ($link->fetchColumn() !== false) {
                    return Json::error(
                        $response,
                        'has_linked_documents',
                        'K této zálohové faktuře už existuje finální nebo daňový doklad k platbě — nelze ji překlopit na fakturu (úplata by se zdanila podruhé). Nejdřív rozpoj nebo stornuj navázané doklady.',
                        409,
                    );
                }
            }

            // 1) Uvolni staré číslo z původní řady (jen je-li poslední v counteru).
            $oldDate   = !empty($existing['issue_date']) ? new \DateTimeImmutable((string) $existing['issue_date']) : null;
            $oldClient = (int) ($existing['client_id'] ?? 0);
            if ($supplierId > 0 && $oldVs !== '') {
                $this->varsymbol->releaseIfLatest($supplierId, $existingType, $oldVs, $oldDate, $oldClient);
            }

            // 2) Přiděl nové číslo v řadě cílového typu (datum/klient z payloadu, fallback na staré).
            $newDate   = !empty($body['issue_date']) ? new \DateTimeImmutable((string) $body['issue_date']) : ($oldDate ?? new \DateTimeImmutable('today'));
            $newClient = isset($body['client_id']) ? (int) $body['client_id'] : $oldClient;
            try {
                $newVs = $this->varsymbol->next($supplierId, $requestedType, $newDate, $newClient);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                return Json::error($response, 'varsymbol_failed', $e->getMessage(), 500);
            }

            $body['invoice_type'] = $requestedType;
            $body['varsymbol']    = $newVs;
            $renumber = ['from' => $oldVs, 'to' => $newVs, 'from_type' => $existingType, 'to_type' => $requestedType];
        } else {
            // Bez přečíslování: typ je u vystavené faktury immutable (číslo + auditní stopa),
            // u draftu lze přepnout mezi invoice/proforma/dobropis (ne na storno/cancellation).
            if ($isIssued || !in_array($requestedType, $validTypes, true)) {
                $body['invoice_type'] = $existingType;
            }
            // Varsymbol vystavené faktury je immutable bez změny typu (snapshot pro účetní
            // evidenci a PDF); ruční přepis čísla se řeší dobropisem/stornem.
            if ($isIssued) {
                unset($body['varsymbol']);
            }
        }
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = InvoiceValidation::invoice($body, $this->repo->vatRateMap(), $this->repo->vatRateCountryMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Auto-default VAT klasifikace pokud user nezadal (s multi-tenant scope)
        $this->applyVatClassificationDefaults($body, \MyInvoice\Http\SupplierGuard::currentId($request));

        try {
            $this->repo->updateDraft($id, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
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
        //   3. Draft + změna currency NEBO issue_date NEBO tax_date (DUZP) → fetch nový kurz
        //   4. Jinak → kurz beze změny, jen ensureRate pro backfill když chybí
        $wasDraft = $existing['status'] === 'draft';
        $currencyChanged = (int) ($existing['currency_id'] ?? 0) !== (int) ($body['currency_id'] ?? 0);
        $issueDateChanged = (string) ($existing['issue_date'] ?? '') !== (string) ($body['issue_date'] ?? '');
        // Kurz se váže k DUZP (tax_date) — změna DUZP musí vyvolat přefetch stejně jako
        // změna vystavení. Jen když payload tax_date obsahuje (jinak by prázdný payload
        // falešně signalizoval změnu proti uloženému DUZP).
        $taxDateChanged = array_key_exists('tax_date', $body)
            && (string) ($existing['tax_date'] ?? '') !== (string) ($body['tax_date'] ?? '');
        $rateMeta = null;

        $userRate = $body['exchange_rate'] ?? null;
        $userRateProvided = $userRate !== null && $userRate !== '' && is_numeric($userRate) && (float) $userRate > 0;

        if ($userRateProvided) {
            // Manuální override z UI — preserve s issue_date jako rate_date
            $this->repo->setExchangeRate($id, (float) $userRate, (string) $body['issue_date']);
        } elseif ($wasDraft && ($currencyChanged || $issueDateChanged || $taxDateChanged)) {
            $rateMeta = $this->rateApplier->applyToInvoice($id);
        } else {
            $this->rateApplier->ensureRate($id);
        }

        // Force update vystavené faktury → revenue cache musí přijmout nové total/currency
        $this->stats->recomputeForInvoiceId($id);

        // Force-edit vystavené faktury: přepiš snapshoty z opravených live dat, aby se
        // změny v údajích odběratele/dodavatele/banky promítly do nově generovaného PDF.
        // UI to uživateli avizuje („Změny přepíšou snapshoty"). U draftu se snapshoty
        // nepoužívají (renderer bere live data), takže rebuild řešíme jen pro vystavené.
        if ($existing['status'] !== 'draft') {
            $this->pdf->rebuildSnapshots($id);
        }

        // Invalidate cached PDF — data faktury se změnila, starý soubor je nepoužitelný.
        // Cache freshness check v rendereru zohledňuje jen mtime šablon/CSS, ne dat,
        // takže bez explicit invalidate by se starý PDF dál servíroval.
        $this->pdf->invalidate($id, 'invalidate_update');

        $invoice = $this->repo->find($id);

        // Audit detail: která pole se opravila (zobrazí se v historii u faktury).
        $changed = self::diffFields($existing, $invoice);
        $payload = $changed !== [] ? ['changed' => $changed] : null;
        // Přečíslování při změně typu (proforma → faktura apod.): zaznamenej staré/nové
        // číslo a řady — uvolnění z původní řady + přidělení v nové je auditně podstatné.
        if ($renumber !== null) {
            $payload = ($payload ?? []) + ['renumber' => $renumber];
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'invoice.force_updated' : 'invoice.updated';
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));

        if ($rateMeta !== null) {
            $invoice['_meta'] = ['exchange_rate' => $rateMeta];
        }
        return Json::ok($response, $invoice);
    }

    /**
     * Porovná starou a novou verzi faktury a vrátí sémantické klíče změněných polí
     * (frontend je lokalizuje přes invoice.changed_fields.*). Slouží jako audit detail
     * v activity logu — „co konkrétně se opravilo".
     *
     * @return list<string>
     */
    private static function diffFields(array $old, array $new): array
    {
        // Sloupce porovnávané pro audit. Sufix *_id se v UI klíči zkracuje na čitelný
        // název (client_id → client); ostatní pole se mapují sama na sebe. Drž v sync
        // s editovatelnými sloupci v InvoiceRepository::updateDraft().
        $columns = [
            'client_id', 'currency_id', 'project_id', 'revenue_category_id', 'branding_profile_id',
            'issue_date', 'tax_date', 'due_date', 'varsymbol',
            'invoice_type', 'payment_method', 'note_above_items', 'note_below_items',
            'discount_percent', 'advance_paid_amount', 'reverse_charge',
            'prices_include_vat', 'vat_classification_code', 'income_tax_exempt', 'language',
        ];

        $changed = [];
        foreach ($columns as $col) {
            // String cast sjednotí int/float/null/bool porovnání napříč PDO casty.
            if ((string) ($old[$col] ?? '') !== (string) ($new[$col] ?? '')) {
                $changed[] = preg_replace('/_id$/', '', $col); // client_id → client
            }
        }
        if (self::itemsChanged((array) ($old['items'] ?? []), (array) ($new['items'] ?? []))) {
            $changed[] = 'items';
        }
        return $changed;
    }

    /** Porovná položky podle uživatelsky viditelných polí (popis/množství/cena/sazba). */
    private static function itemsChanged(array $old, array $new): bool
    {
        $project = static fn (array $it): array => [
            (string) ($it['description'] ?? ''),
            (string) ($it['quantity'] ?? ''),
            (string) ($it['unit'] ?? ''),
            (string) ($it['unit_price_without_vat'] ?? ''),
            (string) ($it['vat_rate_id'] ?? ''),
        ];

        return array_map($project, array_values($old)) !== array_map($project, array_values($new));
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
                // Měrná jednotka řádku je signál zboží/služba pro RC prodej do EU (ř.20 vs ř.21).
                $units = ((string) ($item['unit'] ?? '') !== '') ? [(string) $item['unit']] : [];
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units);
            }
            unset($item);
        }

        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100), 'unit' => (string) ($it['unit'] ?? '')];
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
