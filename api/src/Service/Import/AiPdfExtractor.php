<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wrapper kolem AnthropicClient — extrakuje data z PDF a vytvoří purchase_invoice draft.
 *
 * Pipeline:
 *   1. AnthropicClient.extractInvoice() → JSON s vendor/customer/items
 *   2. Validate strukturu (povinná pole, sanity checks proti hallucinations)
 *   3. Cross-tenant guard (customer.ic vs tenant.ic)
 *   4. ClientResolver.resolveVendor() pro vendor (ARES enrich pokud IČO)
 *   5. Mapper na purchase_invoice draft
 *
 * Tato třída je pro PHASE 2c MVP. V další iteraci:
 *   - ISDOC priorita (pokud PDF má ISDOC embed, použij IsdocParser; AI jen fallback)
 *   - Confidence scoring (AI vrátí confidence per pole; uložit pro review UI)
 *   - Cost tracking per request (input/output tokens)
 */
final class AiPdfExtractor
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Connection $db,
        private readonly AnthropicClient $anthropic,
        private readonly ClientResolver $clientResolver,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly IsdocParser $isdoc,
        private readonly IsdocToPurchaseInvoiceMapper $isdocMapper,
        private readonly Config $config,
        private readonly \MyInvoice\Service\Currency\CnbExchangeRateClient $cnb,
        private readonly ImageToPdfConverter $imageToPdf,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Extract + create draft purchase_invoice.
     *
     * @return array{ok:bool, purchase_invoice_id?:int, vendor_id?:int, source:string,
     *               error?:string, ai_data?:array<string,mixed>, model?:string,
     *               usage?:array<string,int>}
     */
    public function extractAndCreate(int $supplierId, int $userId, string $pdfBytes, ?string $modelOverride = null, ?string $originalFilename = null): array
    {
        // Obrázek (fotka z telefonu, issue #75) → normalizuj na PDF; downstream
        // (ISDOC, AI, archivace, preview) pak pracuje výhradně s PDF.
        if (!str_starts_with($pdfBytes, '%PDF')) {
            $imgMime = $this->imageToPdf->detectImageMime($pdfBytes);
            if ($imgMime !== null) {
                try {
                    $pdfBytes = $this->imageToPdf->convert($pdfBytes, $imgMime);
                    // Obsah je teď PDF → sjednoť i příponu názvu (jinak by se
                    // „uctenka.jpg" stahla jako .jpg, ač je uvnitř PDF).
                    if ($originalFilename !== null && $originalFilename !== '') {
                        $originalFilename = preg_replace('/\.[^.\\/]+$/', '', $originalFilename) . '.pdf';
                    }
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => $e->getMessage(), 'source' => 'image_convert_failed'];
                }
            }
        }

        // Dedup check — pokud PDF se stejným SHA-256 už existuje u tenanta, vrať existing.
        $sha256 = hash('sha256', $pdfBytes);
        $existingId = $this->repo->findIdByPdfHash($supplierId, $sha256);
        if ($existingId !== null) {
            return [
                'ok'                  => true,
                'purchase_invoice_id' => $existingId,
                'source'              => 'duplicate',
                'duplicate'           => true,
                'message'             => 'PDF je již importován jako faktura #' . $existingId,
            ];
        }

        // ISDOC priorita — pokud PDF/A-3 obsahuje embedded ISDOC, použij parser (přesnější, zdarma).
        $isdocXml = $this->pdfIsdoc->extract($pdfBytes);
        if ($isdocXml !== null) {
            try {
                $parsed = $this->isdoc->parse($isdocXml);
                if (!empty($parsed['invoices'])) {
                    $r = $this->isdocMapper->map($parsed['invoices'][0], $supplierId, $userId);
                    // Attach PDF k vytvořené přijaté faktuře
                    $this->attachPdf((int) $r['purchase_invoice_id'], $supplierId, $pdfBytes, $originalFilename);
                    return [
                        'ok'                  => true,
                        'purchase_invoice_id' => $r['purchase_invoice_id'],
                        'vendor_id'           => $r['vendor_id'],
                        'source'              => 'isdoc_embedded',
                    ];
                }
            } catch (\Throwable $e) {
                // ISDOC fail → spadnout do AI fallback
            }
        }

        // AI extraction fallback
        $extracted = $this->anthropic->extractInvoice($supplierId, $pdfBytes, $modelOverride);
        if (!$extracted['ok']) {
            return ['ok' => false, 'error' => $extracted['error'] ?? 'AI extrakce selhala', 'source' => 'ai_failed'];
        }

        // Auto-upgrade na silnější model když Haiku vrátil slabý výsledek (vendor=tenant
        // nebo katastrofální items mismatch). Sonnet 4.6 čte komplexní PDF (autoservisy,
        // multi-column layouts) výrazně lépe za cenu ~4× vyšší. Pokud uživatel už má
        // Sonnet/Opus jako default, retry nemá smysl (už by ho použil).
        $modelUsed = (string) ($extracted['model'] ?? '');
        $isHaiku = str_contains($modelUsed, 'haiku');
        if ($isHaiku) {
            $tenantIc = $this->fetchTenantIc($supplierId);
            $weakness = $this->detectWeakExtraction($extracted['data'], $tenantIc);
            if ($weakness !== null) {
                $this->logger->info('AI extractor: Haiku vrátil slabý výsledek, retry se Sonnetem 4.6', [
                    'supplier_id' => $supplierId,
                    'reason' => $weakness,
                    'haiku_model' => $modelUsed,
                ]);
                $upgrade = $this->anthropic->extractInvoice($supplierId, $pdfBytes, 'claude-sonnet-4-6');
                if ($upgrade['ok']) {
                    $extracted = $upgrade;
                }
            }
        }
        $data = $extracted['data'];

        $validationError = $this->validateAiData($data);
        if ($validationError !== null) {
            return [
                'ok'      => false,
                'error'   => 'AI extrakce neprošla validací: ' . $validationError,
                'ai_data' => $data,
                'source'  => 'ai_invalid',
                'model'   => $extracted['model'] ?? null,
                'usage'   => $extracted['usage'] ?? null,
            ];
        }

        // Cross-tenant guard — customer.ic musí matchovat tenant.
        // Swap detection: AI občas zamění vendor↔customer (tenanta dá jako vendora).
        // Imports jsou vždy purchase faktury (tenant je vždy customer/odběratel),
        // takže pokud vendor.ic == tenant.ic, je to swap → prohodit zpět.
        $tenantIc = $this->fetchTenantIc($supplierId);
        $customerIc = $this->normalizeIc((string) ($data['customer']['ic'] ?? ''));
        $vendorIc   = $this->normalizeIc((string) ($data['vendor']['ic'] ?? ''));

        if ($tenantIc !== null && $vendorIc === $tenantIc) {
            if ($customerIc !== null && $customerIc !== $tenantIc) {
                // AI swap detected: tenant je v vendor pozici, customer má jiné (validní) IČ
                // → prohodit zpět (původní chování).
                $this->logger->info('AI extractor: detected vendor↔customer swap (tenant in vendor slot), swapping back', [
                    'vendor_ic'   => $vendorIc,
                    'customer_ic' => $customerIc,
                    'tenant_ic'   => $tenantIc,
                    'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                ]);
                $tmp = $data['vendor'] ?? [];
                $data['vendor']   = $data['customer'] ?? [];
                $data['customer'] = $tmp;
                // Re-normalize po prohození pro guard níže
                $customerIc = $this->normalizeIc((string) ($data['customer']['ic'] ?? ''));
            } else {
                // vendor.ic == tenant.ic A customer chybí (nebo má taky tenant IČ) — AI
                // očividně mis-přečetla hlavičku PDF (typicky autoservisy / poskytovatelé
                // s vlastní hlavičkou kde vlastní firma je nahoře a odběratel níže).
                // Bez customer s jiným IČ nemáme jak swap-back udělat → abortujeme,
                // aby se faktura nezačala jako "MyWebdesign fakturuje sám sobě".
                $this->logger->warning('AI extractor: vendor IC matches tenant IC and no usable customer to swap — rejecting', [
                    'vendor_ic'   => $vendorIc,
                    'customer_ic' => $customerIc,
                    'tenant_ic'   => $tenantIc,
                    'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                ]);
                return [
                    'ok'      => false,
                    'error'   => 'AI špatně rozpoznala dodavatele — IČO dodavatele se shoduje s IČO vašeho tenanta. '
                              . 'Pravděpodobně AI zaměnila hlavičku PDF (váš název je na faktuře jako odběratel). '
                              . 'Zkuste fakturu nahrát znovu, nebo zadejte ručně.',
                    'ai_data' => $data,
                    'source'  => 'vendor_is_tenant',
                ];
            }
        }

        if ($tenantIc !== null && $customerIc !== null && $customerIc !== $tenantIc) {
            return [
                'ok'      => false,
                'error'   => "Faktura adresovaná jinému plátci (customer IČO: {$customerIc}, tenant: {$tenantIc}).",
                'ai_data' => $data,
                'source'  => 'wrong_tenant',
            ];
        }

        // Resolve vendor (s ARES enrich + create pokud nový)
        $vendorData = (array) ($data['vendor'] ?? []);
        if (empty($vendorData['ic']) && empty($vendorData['company_name'])) {
            return ['ok' => false, 'error' => 'AI nevrátila vendor data', 'ai_data' => $data, 'source' => 'no_vendor'];
        }
        $resolved = $this->clientResolver->resolveVendor($vendorData, $supplierId);

        // Číslo dokladu chybí (typicky účtenka/paragon bez čísla) → doplň unikátní
        // fallback z PDF hashe. Musí být unikátní per (vendor, datum), jinak by dvě
        // účtenky od stejného vendora ve stejný den kolidovaly na uq_pi_vendor_invoice
        // (nebo by je dedup sloučil). Hash je per-doklad unikátní; re-import téhož
        // souboru chytne dřív pdf_hash dedup výše.
        if (empty($data['vendor_invoice_number'])) {
            $data['vendor_invoice_number'] = 'BEZ-CISLA-' . substr($sha256, 0, 8);
        }

        // Create purchase invoice draft
        try {
            $invoiceId = $this->createDraft($data, $supplierId, $userId, $resolved['id'], $resolved['is_vat_payer'] ?? null);
            // Attach PDF — uložit do archive a updatnout pdf_path/hash/size na faktuře
            $this->attachPdf($invoiceId, $supplierId, $pdfBytes, $originalFilename);
            return [
                'ok'                  => true,
                'purchase_invoice_id' => $invoiceId,
                'vendor_id'           => $resolved['id'],
                'source'              => 'ai',
                'model'               => $extracted['model'] ?? null,
                'usage'               => $extracted['usage'] ?? null,
                'ai_data'             => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'error'   => 'Vytvoření draft selhalo: ' . $e->getMessage(),
                'ai_data' => $data,
                'source'  => 'create_failed',
            ];
        }
    }

    /**
     * Validation — anti-hallucination check.
     */
    private function validateAiData(array $data): ?string
    {
        if (!isset($data['vendor']) || !is_array($data['vendor'])) {
            return 'chybí vendor objekt';
        }
        if (empty($data['vendor']['company_name']) && empty($data['vendor']['ic'])) {
            return 'vendor nemá ani company_name ani IČO';
        }
        // vendor_invoice_number ZÁMĚRNĚ nevyžadujeme — účtenky/paragony nemusí mít
        // číslo dokladu. Chybějící číslo doplníme fallbackem z PDF hashe v createDraft.
        if (empty($data['issue_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['issue_date'])) {
            return 'invalid issue_date (musí být YYYY-MM-DD)';
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'invalid currency (musí být ISO 4217, např. CZK)';
        }
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return 'chybí items (alespoň jedna položka)';
        }
        foreach ($data['items'] as $i => $item) {
            if (empty($item['description'])) return "item[{$i}] chybí description";
            if (!isset($item['quantity'])) return "item[{$i}] chybí quantity";
            if (!isset($item['unit_price_without_vat'])) return "item[{$i}] chybí unit_price_without_vat";
        }
        return null;
    }

    private function createDraft(array $data, int $supplierId, int $userId, int $vendorId, ?bool $vendorIsVatPayer = null): int
    {
        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 0.0);

        $documentKind = $this->normalizeDocumentKind((string) ($data['document_kind'] ?? 'invoice'));

        // Fallback detekce dobropisu — AI občas vrátí document_kind='invoice', ale items mají
        // záporné quantity/unit_price (PDF byl dobropis). Trust the amounts: záporné částky
        // = dobropis, override AI klasifikace.
        if ($documentKind === 'invoice') {
            $negativeCount = 0;
            $positiveCount = 0;
            foreach ($data['items'] ?? [] as $line) {
                $q = (float) ($line['quantity'] ?? 0);
                $p = (float) ($line['unit_price_without_vat'] ?? 0);
                $sample = $q !== 0.0 ? $q : $p;
                if ($sample < 0) $negativeCount++;
                elseif ($sample > 0) $positiveCount++;
            }
            if ($negativeCount > 0 && $negativeCount >= $positiveCount) {
                $this->logger->info('AI extractor: detected credit_note from negative line items, overriding AI document_kind=invoice', [
                    'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                    'negative_items' => $negativeCount,
                    'positive_items' => $positiveCount,
                ]);
                $documentKind = 'credit_note';
            }
        }

        // Dobropis: položky musí mít záporné quantity (stejný pattern jako CancelInvoiceAction).
        // AI vrací kladné absolutní hodnoty (per prompt); sign aplikujeme tady podle document_kind.
        // Běžná faktura ('invoice'): AI sign respektujeme — slevy/rabaty mají záporné částky
        // (např. "Roční sleva 10%" s unit_price=-643.50), bez abs() jinak by se sleva
        // přičetla místo odečetla.
        $isCredit = $documentKind === 'credit_note';

        // Účtenky/paragony (a hlavně fotky účtenek) uvádějí ceny VČETNĚ DPH a cena
        // bez DPH na dokladu vůbec není; totéž doklad od neplátce. AI to signalizuje
        // `unit_prices_include_vat`; u document_kind=receipt to bereme jako default
        // (true), když AI flag nevrátí. Cenu NEpřepočítáváme ručně — uložíme ji TAK
        // JAK JE (s DPH) a fakturu označíme `prices_include_vat=1`. DPH pak spočítá
        // kalkulátor koeficientem shora (§37 ZDPH) → celek sedí na haléř.
        $pricesIncludeVat = self::resolvePricesIncludeVat($data, $documentKind);

        $items = [];
        foreach ($data['items'] as $idx => $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            $qtyAi = (float) ($line['quantity'] ?? 0);
            $priceAi = (float) ($line['unit_price_without_vat'] ?? 0);
            if ($isCredit) {
                // Dobropis: AI vrací kladné absolutní hodnoty, sign aplikujeme.
                $qty = -1.0 * abs($qtyAi);
                $price = abs($priceAi);
            } else {
                // Běžná faktura: trust AI sign (slevy mají záporné quantity nebo price).
                $qty = $qtyAi;
                $price = $priceAi;
            }
            $items[] = [
                'description'            => (string) $line['description'],
                'quantity'               => $qty,
                'unit'                   => (string) ($line['unit'] ?? 'ks'),
                'unit_price_without_vat' => $price,
                'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId,
                'order_index'            => $idx,
                // vat_classification_code nesetujeme — PurchaseInvoiceRepository::replaceItems()
                // auto-derive based on rate + RC + vendor country (lookup z DB).
            ];
        }

        // Dodavatel NEPLÁTCE DPH → na dokladu žádná DPH a NENÍ nárok na odpočet.
        // Autoritativně z ARES (CZ IČO) / VIES (zahr. DIČ); fallback signál z dokladu.
        // Vynulujeme sazby (kdyby AI halucinovala 21 %) a níže vynutíme vat_deduction='none'.
        $vendorNonPayer = self::isVendorNonPayer($vendorIsVatPayer, (array) ($data['vendor'] ?? []));
        if ($vendorNonPayer) {
            $zeroRateId = $this->matchVatRateId($vatRates, 0.0) ?? $defaultVatRateId;
            foreach ($items as &$it) {
                $it['vat_rate_id'] = $zeroRateId;
            }
            unset($it);
        }

        // Doklad s KONZISTENTNÍ jednosazbovou rekapitulací DPH → eviduj VERBATIM
        // (§ 73 odst. 6 / § 30 / § 100 ZDPH). Per-řádková „cena bez DPH" u účtenek
        // (PHM) je často reálně brutto — základ proto NEPŘEPOČÍTÁVÁME z řádků, vezmeme
        // ho z rekapitulace; DPH připne seedVatOverridesFromDocument a celek pak přesně
        // sedí (žádné umělé zaokrouhlení, žádné chybné +DPH navrch). Neplátce vynecháme
        // (na dokladu žádná DPH, řeší se výš), dobropisy taky (znaménka).
        if (!$vendorNonPayer) {
            $authoritative = self::authoritativeRecapBaseLine($items, $data, $isCredit);
            if ($authoritative !== null) {
                $this->logger->info('AI extractor: konzistentní rekapitulace DPH → evidováno verbatim (§73, brutto/netto drift řádků)', [
                    'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                    'lines_before'          => count($items),
                    'recap_base'            => $data['total_without_vat'] ?? null,
                    'recap_total'           => $data['total_with_vat'] ?? null,
                ]);
                $items = $authoritative;
                // Základ je základ; DPH připne override → žádná „shora" math z brutto.
                $pricesIncludeVat = false;
            }
        }

        // Haléřový rounding drift u jednosazbového dokladu (typicky čerpačka: cena/litr
        // gross→net × množství neround-tripuje na základ z REKAPITULACE) → sluč na 1 řádek
        // 1 ks × základ daně z rekapitulace. Tím základ/daň/celkem sedí na doklad bez
        // umělého „zaokrouhlení". V režimu shora (prices_include_vat) netřeba — celek sedí.
        if (!$pricesIncludeVat) {
            $collapsed = self::collapseToSummaryBaseLine($items, $data, $isCredit);
            if ($collapsed !== null) {
                $this->logger->info('AI extractor: sloučeno na 1 řádek dle rekapitulace DPH (haléřový drift)', [
                    'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                    'lines_before'          => count($items),
                    'summary_base'          => $data['total_without_vat'] ?? null,
                ]);
                $items = $collapsed;
            }
        }

        // Reverse charge auto-detect: vendor je v EU/3.zemi A všechny řádky mají vat_rate=0
        // → typicky přenesená daňová povinnost (Čech přijímá službu/zboží ze zahraničí).
        // AI tuto info neextrahuje explicitně, takže detekujeme heuristikou.
        $reverseCharge = $this->inferReverseCharge($vendorId, $items);
        if ($reverseCharge) {
            $this->logger->info('AI extractor: detected reverse_charge (non-CZ vendor + all items vat_rate=0)', [
                'vendor_id' => $vendorId,
                'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
            ]);
        }

        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $this->sanitizeVendorNumber((string) $data['vendor_invoice_number']),
            'document_kind'         => $documentKind,
            'issue_date'            => (string) $data['issue_date'],
            'tax_date'              => isset($data['tax_date']) && $data['tax_date'] ? (string) $data['tax_date'] : null,
            'due_date'              => (string) ($data['due_date'] ?? $data['issue_date']),
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId((string) $data['currency'], $supplierId),
            'exchange_rate'         => null,
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => $reverseCharge,
            'prices_include_vat'    => $pricesIncludeVat,
            // Neplátce → bez nároku na odpočet (VatLedgerService řádky s 'none' vyloučí
            // z DPH přiznání ř.40 i z KH sekce B). Uživatel může v editoru vědomě přepsat.
            'vat_deduction'         => $vendorNonPayer ? 'none' : 'full',
            // Rounding nastavíme až PO recompute z items, ne z AI hodnoty
            // (AI dělá DPH math sama a občas se splete o ±1 haléř — viz user report
            // Vodafone faktury 1025255728, kde AI vrátila total_with_vat=1502,03
            // místo přepočtu 1241,34×1,21=1502,02, takže rounding vyšel -0,03
            // místo -0,02 a "K úhradě" pak ukazovalo 1501,99 místo 1502,00).
            'rounding'              => 0,
            'language'              => 'cs',
            'items'                 => $items,
        ];
        // Dedup guard — jiné PDF stejné faktury (různý hash, stejné číslo+datum+vendor)
        // by hodilo SQL 23000 duplicate key. Skipnout a vrátit existující ID.
        $existingId = $this->repo->findIdByVendorInvoice(
            $supplierId, $vendorId,
            (string) $payload['vendor_invoice_number'],
            (string) $payload['issue_date'],
        );
        if ($existingId !== null) {
            return $existingId;
        }
        $id = $this->repo->createDraft($payload, $userId, $supplierId);
        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);
        // Naseeduj ruční rekapitulaci DPH dle dokladu (§ 73) — uloží základ/DPH dle
        // dokladu dodavatele. Varování (rozdíl > tolerance) zapíšeme až na konci, ať
        // ho pozdější setExtractionWarning() (mismatch / neplátce) nepřepíše.
        $vatRecapWarning = null;
        if (!$pricesIncludeVat) {
            $vatRecapWarning = $this->seedVatOverridesFromDocument($id, $supplierId, $data, $isCredit);
        }
        // Rounding počítáme AŽ TADY (po recompute) — vůči přesnému total z items,
        // ne vůči AI's hodnotě (AI dělá DPH math sama a občas se splete o haléř).
        // Preferujeme PDF rounded (`total_with_vat_rounded`), fallback na AI's
        // `total_with_vat` (mnoho AI extracts vrátí "K úhradě" jako total_with_vat
        // bez explicitního total_with_vat_rounded).
        $this->applyRoundingFromPdfTotal($id, $supplierId, $data, $isCredit);
        // Pro non-CZK currency: auto-apply ČNB kurz k tax_date (nebo issue_date).
        $this->applyCnbRate($id, $supplierId, $data);
        // Pokud AI detekovala "NEPLAŤTE, JIŽ UHRAZENO" / "PAID" → mark as paid.
        if (!empty($data['already_paid'])) {
            $this->markAlreadyPaid($id, $supplierId);
        }
        // Sanity check: rozdíl mezi součtem řádků a AI-vráceným totalem >2 % → varování.
        // Typicky odhalí faktury kde AI sečetla subtotaly jako další items
        // (např. NC Auto BMW Service → 4977 reálně vs 22442 jako duplicitní subtotaly).
        $this->maybeFlagTotalsMismatch($id, $supplierId, $data, $items, $pricesIncludeVat);
        // Dodavatel neplátce → vysvětlující varování (má přednost před mismatch hláškou).
        if ($vendorNonPayer) {
            try {
                $this->repo->setExtractionWarning(
                    $id,
                    $supplierId,
                    'Dodavatel je neplátce DPH — odpočet daně byl automaticky zakázán '
                        . '(z dokladu od neplátce nelze uplatnit nárok na odpočet DPH). '
                        . 'Sazby byly nastaveny na 0 %. V editoru lze vědomě přepsat.',
                );
            } catch (\Throwable) {
                // Varování je „nice to have" — faktura už je vytvořená správně.
            }
        }
        // Finální faktura odkazující na zálohu ("zaplaceno zálohou č. X") → zkus najít
        // shodnou přijatou zálohu a NAVRHNI propojení (uživatel potvrdí v detailu).
        if ($documentKind !== 'advance') {
            $this->maybeSuggestAdvanceLink($id, $supplierId, $vendorId, $data);
        }
        // Varování z rekapitulace DPH (seed) přidáme až teď — po mismatch/neplátce
        // zápisech, které používají setExtractionWarning() (overwrite); append ho
        // tak nepřepíšou a uživatel vidí obě hlášky.
        if ($vatRecapWarning !== null && $vatRecapWarning !== '') {
            try {
                $this->repo->appendExtractionWarning($id, $supplierId, $vatRecapWarning);
            } catch (\Throwable) {
                // Varování je „nice to have" — faktura už je vytvořená správně.
            }
        }
        return $id;
    }

    /**
     * Pokud AI vrátila `advance_reference` (odkaz na zálohu/proformu), zkus najít
     * shodnou nespárovanou zálohu téhož dodavatele a uložit NÁVRH propojení
     * (advance_link_suggested_id). Vazbu NEAPLIKUJE — potvrzuje ji uživatel.
     */
    private function maybeSuggestAdvanceLink(int $invoiceId, int $supplierId, int $vendorId, array $data): void
    {
        $ref = trim((string) ($data['advance_reference'] ?? ''));
        if ($ref === '') return;
        $advanceId = $this->repo->findAdvanceByReference($supplierId, $vendorId, $ref);
        if ($advanceId !== null && $advanceId !== $invoiceId) {
            $this->repo->suggestAdvanceLink($invoiceId, $advanceId, $supplierId);
        }
    }

    /**
     * Spočítá `Σ(qty × unit_price)` z items a porovná s AI `total_without_vat`.
     * Pokud |rozdíl| / total > 2 %, zapíše textový popis do `extraction_warning`,
     * aby UI mohlo uživatele upozornit "AI extrakce mohla započítat mezisoučty
     * jako další položky — zkontroluj data před zaúčtováním."
     */
    /**
     * Rozhodne, zda jsou ceny řádků z AI extrakce VČETNĚ DPH (brutto) → faktura
     * dostane `prices_include_vat=1` a DPH se počítá shora koeficientem.
     *
     * Pravidlo: AI signalizuje `unit_prices_include_vat` (bool). Když flag chybí,
     * default odvodíme z typu dokladu — účtenky/paragony (`receipt`) ceny s DPH
     * uvádějí typicky a cenu bez DPH na dokladu nemají; ostatní doklady default false.
     */
    private static function resolvePricesIncludeVat(array $data, string $documentKind): bool
    {
        if (array_key_exists('unit_prices_include_vat', $data)) {
            return !empty($data['unit_prices_include_vat']);
        }
        return $documentKind === 'receipt';
    }

    /**
     * Dodavatel je neplátce DPH (→ žádný nárok na odpočet, na dokladu žádná DPH)?
     * Autoritativní výsledek z ARES/VIES (`VendorVatPayerResolver`) má přednost; když je
     * nezjištěný (null), použijeme explicitní signál z dokladu (AI `vendor.is_vat_payer`
     * = false, typicky doklad s textem „DIČ: Neplátce DPH").
     *
     * @param array<string,mixed> $vendorData
     */
    public static function isVendorNonPayer(?bool $resolvedIsVatPayer, array $vendorData): bool
    {
        if ($resolvedIsVatPayer === true)  return false;
        if ($resolvedIsVatPayer === false) return true;
        return array_key_exists('is_vat_payer', $vendorData) && $vendorData['is_vat_payer'] === false;
    }

    /**
     * Když má doklad JEDINOU sazbu DPH a součet řádků (qty×cena po zaokrouhlení) se o haléře
     * rozchází se základem daně z REKAPITULACE dokladu (`total_without_vat` z AI), nahradí
     * řádky jediným řádkem `1 ks × stated_base`. Tím základ/daň/celkem přesně odpovídají
     * rekapitulaci dokladu a odpadne haléřové „zaokrouhlení" z per-řádkového driftu —
     * typicky čerpačka, kde AI přepočítá cenu/litr z brutto na netto a qty×netto
     * neround-tripuje (34,29 × 33,71 = 1 155,92 vs rekapitulace 1 155,94).
     *
     * Vrací nové `$items` (1 řádek) nebo `null` (neslučovat — víc sazeb, dobropis,
     * chybějící/0 základ, přesná shoda, nebo příliš velký rozdíl = jiný problém).
     *
     * @param list<array{description?:string, quantity:float|int, unit?:string, unit_price_without_vat:float|int, vat_rate_id:int, order_index?:int}> $items
     * @param array<string,mixed> $data
     * @return list<array{description:string, quantity:float, unit:string, unit_price_without_vat:float, vat_rate_id:int, order_index:int}>|null
     */
    public static function collapseToSummaryBaseLine(array $items, array $data, bool $isCredit): ?array
    {
        if ($isCredit || count($items) === 0) {
            return null; // dobropisy neslučujeme (znaménka), prázdné nic
        }
        // Jen jedna sazba DPH (jinak by jeden řádek nešel namapovat na rekapitulaci).
        $rateIds = array_values(array_unique(array_map(static fn ($i) => (int) $i['vat_rate_id'], $items)));
        if (count($rateIds) !== 1) {
            return null;
        }
        $aiBase = isset($data['total_without_vat']) ? (float) $data['total_without_vat'] : null;
        if ($aiBase === null || $aiBase <= 0.0) {
            return null; // bez rekapitulačního základu nemáme co dosadit
        }
        $sumBase = 0.0;
        foreach ($items as $it) {
            $sumBase += round((float) $it['quantity'] * (float) $it['unit_price_without_vat'], 2);
        }
        $diff = abs(round($sumBase, 2) - round($aiBase, 2));
        // Slučujeme jen haléřový drift: > 0 a do ~1 Kč. Větší rozdíl = jiný problém
        // (chybné řádky) → řeší maybeFlagTotalsMismatch, řádky ponecháme.
        if ($diff <= 0.0 || $diff > 1.0) {
            return null;
        }
        return [[
            'description'            => (string) ($items[0]['description'] ?? ''),
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => round($aiBase, 2),
            'vat_rate_id'            => (int) $items[0]['vat_rate_id'],
            'order_index'            => 0,
        ]];
    }

    /**
     * Doklad s JEDNOSAZBOVOU rekapitulací DPH, která je VNITŘNĚ KONZISTENTNÍ se
     * základem i celkem na dokladu, je podle § 73 odst. 6 / § 30 / § 100 ZDPH
     * AUTORITATIVNÍ — eviduje se tak, jak je, nepřepočítává se z jednotkových cen.
     *
     * Vrací `['rate'=>, 'base'=>, 'vat'=>]` (kladné hodnoty z dokladu) nebo null,
     * když rekapitulace chybí, je vícesazbová, nulová, nebo NESEDÍ na uvedený
     * základ/celkem (pak jí nedůvěřujeme → standardní tok + kontrolní varování).
     *
     * @param array<string,mixed> $data
     * @return array{rate:float,base:float,vat:float}|null
     */
    public static function singleRateConsistentRecap(array $data): ?array
    {
        if (!isset($data['vat_recap']) || !is_array($data['vat_recap'])) {
            return null;
        }
        $rates = [];
        foreach ($data['vat_recap'] as $r) {
            if (!is_array($r) || !isset($r['rate'], $r['base'], $r['vat'])) {
                continue;
            }
            $rate = abs((float) $r['rate']);
            if ($rate <= 0.0) {
                continue; // 0 % / osvobozeno tímto pinem neřešíme
            }
            $base = abs((float) $r['base']);
            $vat  = abs((float) $r['vat']);
            if ($base <= 0.0 && $vat <= 0.0) {
                continue; // prázdný řádek rekapitulace (AI často vrátí šablonu 12 %/21 % i s nulami)
            }
            $rates[] = ['rate' => $rate, 'base' => $base, 'vat' => $vat];
        }
        if (count($rates) !== 1) {
            return null; // jen jednosazbové; vícesazbové řeší PurchaseVatRecapSeeder
        }
        $recap = $rates[0];
        if ($recap['base'] <= 0.0) {
            return null;
        }
        $tol = PurchaseVatRecapSeeder::toleranceFor((string) ($data['currency'] ?? 'CZK'));
        // Konzistence se součty na dokladu (pokud je doklad uvádí).
        $statedBase    = isset($data['total_without_vat']) ? abs((float) $data['total_without_vat']) : null;
        $statedWithVat = isset($data['total_with_vat']) ? abs((float) $data['total_with_vat']) : null;
        if ($statedBase !== null && abs($recap['base'] - $statedBase) > $tol) {
            return null; // rekapitulační základ nesedí na uvedený základ → nedůvěřuj
        }
        if ($statedWithVat !== null && abs(($recap['base'] + $recap['vat']) - $statedWithVat) > $tol) {
            return null; // základ + DPH nesedí na celkem → rekapitulace není konzistentní
        }
        return $recap;
    }

    /**
     * Když má doklad konzistentní jednosazbovou rekapitulaci DPH a součet řádků se
     * od jejího základu VÝRAZNĚ liší (typicky účtenka za PHM, kde „cena/litr" je
     * reálně brutto, i když AI tvrdí `unit_price_without_vat`: 74,81 l × 35,90 =
     * 2 685,68 ≈ CELKEM s DPH, ne základ 2 219,59), nahradí řádky jediným řádkem
     * `1 ks × základ z rekapitulace`. Per-řádkový dopočet by jinak přidal DPH navrch
     * (→ 3 249,67) nebo umělé zaokrouhlení. DPH pak připne {@see seedVatOverridesFromDocument}.
     *
     * Na rozdíl od {@see collapseToSummaryBaseLine} (haléřový drift do 1 Kč) řeší
     * VELKÝ rozdíl — ale jen pod ochranou konzistentní rekapitulace (jinak null,
     * ať se „úplně mimo" řádky vyřeší standardním mismatch varováním).
     *
     * Vrací nové `$items` (1 řádek) nebo null (neslučovat).
     *
     * @param list<array{description?:string, quantity:float|int, unit?:string, unit_price_without_vat:float|int, vat_rate_id:int, order_index?:int}> $items
     * @param array<string,mixed> $data
     * @return list<array{description:string, quantity:float, unit:string, unit_price_without_vat:float, vat_rate_id:int, order_index:int}>|null
     */
    public static function authoritativeRecapBaseLine(array $items, array $data, bool $isCredit): ?array
    {
        if ($isCredit || count($items) === 0) {
            return null; // dobropisy neslučujeme (znaménka), prázdné nic
        }
        $recap = self::singleRateConsistentRecap($data);
        if ($recap === null) {
            return null;
        }
        // Jen když se řádky od rekapitulačního základu opravdu liší (jinak ponech
        // detailní řádky — haléřový drift dořeší collapseToSummaryBaseLine / seeder).
        $sumLineBase = 0.0;
        foreach ($items as $it) {
            $sumLineBase += round((float) $it['quantity'] * (float) $it['unit_price_without_vat'], 2);
        }
        $tol = PurchaseVatRecapSeeder::toleranceFor((string) ($data['currency'] ?? 'CZK'));
        if (abs(round($sumLineBase, 2) - round($recap['base'], 2)) <= $tol) {
            return null;
        }
        return [[
            'description'            => (string) ($items[0]['description'] ?? ''),
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => round($recap['base'], 2),
            'vat_rate_id'            => (int) $items[0]['vat_rate_id'],
            'order_index'            => 0,
        ]];
    }

    private function maybeFlagTotalsMismatch(int $invoiceId, int $supplierId, array $data, array $items, bool $pricesIncludeVat = false): void
    {
        // AI JSON může pole vynechat / nastavit null. Po `??` máme float|null.
        // Sanity check porovnává součet řádků (qty × cena) s odpovídajícím AI totalem:
        //  - režim ZDOLA (default): ceny řádků jsou bez DPH → reference = total_without_vat.
        //  - režim SHORA (účtenky): ceny řádků jsou S DPH → reference = total_with_vat.
        // Žádný přepočet `total_with_vat / 1.21` (u multi-rate by dělal false positive).
        // Pokud AI příslušný total nevrátí, kontrolu přeskočíme.
        $rawTotal = $pricesIncludeVat
            ? ($data['total_with_vat'] ?? null)
            : ($data['total_without_vat'] ?? null);
        if ($rawTotal === null) return;
        $aiTotal = abs((float) $rawTotal);
        // Pro logging/diagnostiku si zapamatujeme i protější total (pokud existuje).
        $aiTotalWithVat = isset($data['total_with_vat']) ? abs((float) $data['total_with_vat']) : null;

        // Signed sum — respektuje znaménka u slev (qty nebo unit_price může být záporný)
        // i u dobropisů (kde extractor aplikoval `qty *= -1`). Pak abs() pro porovnání
        // s AI totalem, který je vždy kladný (per prompt).
        $signedSum = 0.0;
        foreach ($items as $it) {
            $signedSum += (float) ($it['quantity'] ?? 0) * (float) ($it['unit_price_without_vat'] ?? 0);
        }
        $itemsSum = round(abs($signedSum), 2);

        $reference = $aiTotal;
        if ($reference <= 0.0) return;

        $diff = abs($itemsSum - $reference);
        $relativeDiff = $diff / $reference;
        if ($relativeDiff <= 0.02) return; // pod 2 % = OK (zaokrouhlení, DPH rounding)

        // Heuristika: pokud items_sum > reference, AI nejspíš započítala subtotaly
        // jako další položky (typický pattern NC Auto), nebo má řádek se slevou
        // špatné znaménko. Pokud items_sum < reference, AI naopak nějaké položky
        // vynechala (vzácnější — chybějící strana 2 atd).
        $direction = $itemsSum > $reference ? 'vyšší než' : 'nižší než';

        $warning = sprintf(
            'Možná chyba AI extrakce: součet řádků bez DPH (%s) je %s AI-vrácený celkový základ daně bez DPH (%s) — rozdíl %.1f %%. '
                . 'Typická příčina: AI započítala mezisoučtové řádky ("Celkem", "Subtotal") jako další položky, '
                . 'nebo některý řádek (např. sleva) má špatné znaménko. '
                . 'Zkontroluj prosím řádky proti PDF před zaúčtováním.',
            number_format($itemsSum, 2, ',', ' '),
            $direction,
            number_format($reference, 2, ',', ' '),
            $relativeDiff * 100.0,
        );
        try {
            $this->repo->setExtractionWarning($invoiceId, $supplierId, $warning);
            $this->logger->warning('AI extractor: totals mismatch flagged', [
                'invoice_id' => $invoiceId,
                'items_sum' => $itemsSum,
                'ai_total_without_vat' => $aiTotal,
                'ai_total_with_vat' => $aiTotalWithVat,
                'relative_diff' => $relativeDiff,
            ]);
        } catch (\Throwable) {
            // Silent — extrakce už proběhla úspěšně, varování je jen "nice to have".
        }

        // Placeholder fallback pouze pro KATASTROFÁLNÍ mismatch (>50 %).
        // Práh úmyslně vysoký — drobné chyby (sleva se špatným znaménkem ~22 %)
        // nechceme zaměnit, uživateli stačí otočit znaménko v jednom řádku.
        //
        // Strategie: zachováme popisy řádků z AI extraktu (jsou typicky správně,
        // jen qty/ceny jsou špatně), jen vynulujeme jejich qty a unit_price. Přidáme
        // jako první řádek "KOREKCE" s AI totalem z "K úhradě" (které AI typicky čte
        // správně). Uživatel pak vidí seznam položek z PDF jako referenci, doplní
        // qty/ceny postupně a až součet sedí, smaže korekční řádek.
        if ($relativeDiff > 0.5 && $reference > 0.0 && !empty($items)) {
            try {
                $firstVatRateId = (int) ($items[0]['vat_rate_id'] ?? 0);
                $defaultVatRateId = $firstVatRateId > 0 ? $firstVatRateId : null;

                $placeholderItems = [];
                // Korekční řádek na začátku — drží správný total z "K úhradě"
                $placeholderItems[] = [
                    'description'            => 'KOREKCE: AI špatně přečetla položky. Doplňte qty/cenu k řádkům níže a tento řádek pak smažte.',
                    'quantity'               => 1.0,
                    'unit'                   => 'ks',
                    'unit_price_without_vat' => $reference,
                    'vat_rate_id'            => $defaultVatRateId,
                    'order_index'            => 0,
                ];
                // Zachováme AI popisy s vynulovanou qty/cenou — uživatel je vyplní
                foreach ($items as $idx => $aiItem) {
                    $desc = trim((string) ($aiItem['description'] ?? ''));
                    if ($desc === '') continue;
                    $placeholderItems[] = [
                        'description'            => $desc,
                        'quantity'               => 0.0,
                        'unit'                   => (string) ($aiItem['unit'] ?? 'ks'),
                        'unit_price_without_vat' => 0.0,
                        'vat_rate_id'            => (int) ($aiItem['vat_rate_id'] ?? 0) > 0
                            ? (int) $aiItem['vat_rate_id']
                            : $defaultVatRateId,
                        'order_index'            => $idx + 1,
                    ];
                }
                $this->repo->replaceItems($invoiceId, $placeholderItems);
                $this->calc->recompute($invoiceId);
                $this->logger->info('AI extractor: items nahrazeny korekcí + vynulovanými AI popisy kvůli katastrofálnímu mismatch', [
                    'invoice_id' => $invoiceId,
                    'relative_diff' => $relativeDiff,
                    'preserved_descriptions' => count($placeholderItems) - 1,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('AI extractor: placeholder fallback selhal', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Transition draft → paid pokud AI detekovala 'already paid' indikátor v PDF.
     *
     * Skok rovnou z draftu do `paid` (přeskakuje 'received'/'booked') — faktura už je
     * historicky uzavřená, intermediate stavy nemají smysl. Selhání logujeme (ne silently),
     * aby debugování bylo viditelné.
     */
    private function markAlreadyPaid(int $id, int $supplierId): void
    {
        try {
            // Při přechodu z draft musí faktura získat varsymbol (interní číslo dokladu) —
            // ručně se to děje v TransitionPurchaseInvoiceStatusAction přes ensureVarsymbol().
            // Tady přímým UPDATE varsymbol nevygenerujeme, takže zavoláme repo metodu napřed.
            $this->repo->ensureVarsymbol($id, $supplierId);
            // Draft → paid přímý update (skip 'received' intermediate — faktura už existuje
            // v hotové stavu). UPDATE jen pokud aktuálně draft.
            $stmt = $this->db->pdo()->prepare(
                "UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, CURDATE())
                  WHERE id = ? AND supplier_id = ? AND status = 'draft'"
            );
            $stmt->execute([$id, $supplierId]);
            if ($stmt->rowCount() === 0) {
                $this->logger->warning('AI extractor: already_paid marking — UPDATE neaktualizoval žádný řádek (status už není draft?)', [
                    'invoice_id' => $id,
                    'supplier_id' => $supplierId,
                ]);
            } else {
                $this->logger->info('AI extractor: faktura označena jako paid podle PDF indikátoru', [
                    'invoice_id' => $id,
                ]);
            }
        } catch (\Throwable $e) {
            // Logujeme (ne silently) — pokud markAlreadyPaid selže, faktura zůstane jako
            // draft a uživatel ručně označí jako uhrazenou. To je správné fallback,
            // ale chceme vědět proč to selhalo (varsymbol konflikt, DB constraint atd).
            $this->logger->error('AI extractor: markAlreadyPaid selhal — faktura zůstane jako draft', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-apply ČNB kurz pro non-CZK přijatou fakturu.
     *
     * Použije tax_date (DUZP) jako primary; fallback issue_date. CnbExchangeRateClient
     * má built-in fallback na předchozí pracovní den (víkend/svátek), takže vždy
     * najde platný kurz.
     */
    private function applyCnbRate(int $id, int $supplierId, array $data): void
    {
        $currency = strtoupper((string) ($data['currency'] ?? 'CZK'));
        if ($currency === 'CZK' || $currency === '') return;
        $dateStr = (string) ($data['tax_date'] ?? $data['issue_date'] ?? '');
        if ($dateStr === '') return;
        try {
            $issueDate = new \DateTimeImmutable($dateStr);
        } catch (\Throwable) {
            return;
        }
        try {
            $result = $this->cnb->getRate($currency, $issueDate);
        } catch (\Throwable) {
            return; // ČNB timeout / network — silent
        }
        if ($result === null || !isset($result['rate'])) return;
        try {
            $this->repo->setExchangeRate(
                $id,
                (float) $result['rate'],
                (string) ($result['rate_date'] ?? $dateStr),
                'cnb',
                $supplierId,
            );
        } catch (\Throwable) {
            // Pokud setExchangeRate selže (race condition / schema mismatch), silent.
        }
    }

    /**
     * Detekce "slabého" výsledku AI extrakce, který by mohl benefitovat z retry
     * na silnější model. Vrací důvod (string) pokud je výsledek slabý, jinak null.
     *
     * Kritéria:
     *   1. `vendor.ic == tenant.ic` a `customer` chybí/nepoužitelný → AI zamíchala
     *      vendor↔customer a nemáme jak udělat swap-back.
     *   2. Σ items vs AI total_without_vat se liší o >50 % → AI buď halucinovala
     *      items, započítala subtotaly, nebo nečte sloupce správně. Sonnet
     *      typicky čte multi-column PDF mnohem přesněji.
     */
    private function detectWeakExtraction(array $data, ?string $tenantIc): ?string
    {
        // Check 1: vendor=tenant bez použitelného customer
        $vendorIc = $this->normalizeIc((string) ($data['vendor']['ic'] ?? ''));
        $customerIc = $this->normalizeIc((string) ($data['customer']['ic'] ?? ''));
        if ($tenantIc !== null && $vendorIc === $tenantIc) {
            if ($customerIc === null || $customerIc === $tenantIc) {
                return 'vendor_is_tenant_no_swap_target';
            }
        }

        // Check 2: items sum vs AI total — katastrofální mismatch.
        // Porovnáváme jen bez DPH proti bez DPH. Pokud AI nevrátila total_without_vat,
        // weak-detekci přeskočíme (radši falsy negative než false-positive auto-upgrade
        // u multi-rate faktur, kde by `total_with_vat / 1.21` byla nesmyslná).
        $aiTotal = isset($data['total_without_vat']) ? abs((float) $data['total_without_vat']) : 0.0;
        if ($aiTotal > 0.0 && !empty($data['items']) && is_array($data['items'])) {
            $signedSum = 0.0;
            foreach ($data['items'] as $it) {
                $signedSum += (float) ($it['quantity'] ?? 0) * (float) ($it['unit_price_without_vat'] ?? 0);
            }
            $itemsSum = abs(round($signedSum, 2));
            if ($itemsSum > 0.0) {
                $relativeDiff = abs($itemsSum - $aiTotal) / $aiTotal;
                if ($relativeDiff > 0.5) {
                    return 'catastrophic_items_mismatch';
                }
            }
        }

        return null;
    }

    private function fetchTenantIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === '' || $ic === null) return null;
        return $this->normalizeIc((string) $ic);
    }

    private function normalizeIc(string $ic): ?string
    {
        $clean = preg_replace('/\D/', '', $ic) ?? '';
        return $clean !== '' ? $clean : null;
    }

    private function resolveCurrencyId(string $code, int $supplierId): int
    {
        $code = strtoupper(trim($code)) ?: 'CZK';
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code]);
        return (int) $pdo->lastInsertId();
    }

    private function loadVatRateMap(): array
    {
        // vat_rates používá valid_from/valid_to (NULL = stále platné), ne is_active.
        // Pro AI mapování stačí aktuálně platné sazby (k dnešnímu datu).
        $today = date('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, rate_percent FROM vat_rates
              WHERE (valid_from IS NULL OR valid_from <= ?)
                AND (valid_to   IS NULL OR valid_to   >= ?)'
        );
        $stmt->execute([$today, $today]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[(int) $r['id']] = (float) $r['rate_percent'];
        return $map;
    }

    private function matchVatRateId(array $vatRates, float $rate): ?int
    {
        foreach ($vatRates as $id => $r) if (abs($r - $rate) < 0.01) return $id;
        return null;
    }

    /**
     * Naseeduje ruční rekapitulaci DPH (§ 73 ZDPH) z AI dat přes sdílený
     * {@see PurchaseVatRecapSeeder} (stejná logika jako ISDOC/Pohoda/iDoklad import).
     *
     * Zdroj cílové rekapitulace: explicitní AI `vat_recap` (po sazbách); fallback na
     * celkové součty řeší seeder u jednosazbového dokladu. Vrací varovný text (rozdíl
     * dokladu vs dopočtu nad tolerancí), který volající zapíše až po ostatních
     * varováních (aby se nepřepsal). Override + recompute provede seeder sám.
     */
    private function seedVatOverridesFromDocument(int $id, int $supplierId, array $data, bool $isCredit): ?string
    {
        $docByRate = [];
        if (isset($data['vat_recap']) && is_array($data['vat_recap'])) {
            foreach ($data['vat_recap'] as $r) {
                if (!is_array($r) || !isset($r['rate'], $r['base'], $r['vat'])) {
                    continue;
                }
                $rate = abs((float) $r['rate']);
                if ($rate <= 0.0) {
                    continue;
                }
                $docByRate[number_format($rate, 2, '.', '')] = [
                    'base' => abs((float) $r['base']),
                    'vat'  => abs((float) $r['vat']),
                ];
            }
        }

        return (new PurchaseVatRecapSeeder($this->repo, $this->calc, $this->logger))->seed(
            $id,
            $supplierId,
            $docByRate,
            (string) ($data['currency'] ?? 'CZK'),
            $isCredit,
            isset($data['total_without_vat']) ? abs((float) $data['total_without_vat']) : null,
            isset($data['total_with_vat']) ? abs((float) $data['total_with_vat']) : null,
        );
    }

    /**
     * Rounding kalkulace POST recompute. Porovná hodnotu z PDF "K úhradě"
     * s přesným součtem z items (= total_with_vat po `InvoiceMath::compute`).
     * Rozdíl < 1 Kč uloží jako rounding offset.
     *
     * Priorita zdroje "K úhradě":
     *   1) `data.total_with_vat_rounded` — explicitní pole, AI ho vyplní pokud
     *      PDF má dvě hodnoty (sum items vs. K úhradě jiná čísla)
     *   2) `data.total_with_vat` — fallback, mnoho AI extracts vrátí "K úhradě"
     *      jako total_with_vat bez explicitního rounded pole
     *
     * Důležité: NEpoužíváme AI hodnotu jako referenci pro recompute, jen jako
     * PDF zobrazenou částku. Reference je VŽDY přepočtený items total z DB
     * (po `recompute`) — AI dělá DPH math sama a občas se splete o haléř,
     * referenční musí být deterministický kalkulátor (`InvoiceMath`).
     */
    private function applyRoundingFromPdfTotal(int $id, int $supplierId, array $data, bool $isCredit): void
    {
        $pdfTotal = null;
        if (isset($data['total_with_vat_rounded']) && $data['total_with_vat_rounded'] !== null) {
            $pdfTotal = (float) $data['total_with_vat_rounded'];
        } elseif (isset($data['total_with_vat']) && $data['total_with_vat'] !== null) {
            $pdfTotal = (float) $data['total_with_vat'];
        }
        if ($pdfTotal === null || $pdfTotal === 0.0) return;
        $pdfTotal = abs($pdfTotal);

        $current = $this->repo->find($id, $supplierId);
        if ($current === null) return;
        $exactTotal = (float) abs((float) ($current['total_with_vat'] ?? 0));
        if ($exactTotal === 0.0) return;

        $diff = round($pdfTotal - $exactTotal, 2);
        if (abs($diff) > 0.0 && abs($diff) < 1.0) {
            try {
                $this->repo->setRounding($id, $supplierId, $isCredit ? -1.0 * $diff : $diff);
            } catch (\Throwable $e) {
                $this->logger->warning('AI extractor: applyRoundingFromPdfTotal — setRounding selhalo', [
                    'invoice_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Heuristika reverse charge: vendor je v jiné zemi než CZ a všechny řádky
     * mají vat_rate=0 → přenesená daňová povinnost.
     *
     * Vrátí false pokud:
     *   - vendor je CZ
     *   - jakýkoli item má vat_rate > 0 (tuzemská faktura s DPH)
     *   - country lookup selže (bezpečný default)
     */
    private function inferReverseCharge(int $vendorId, array $items): bool
    {
        if (empty($items)) return false;
        // Pokud kterýkoli item má vat_rate > 0 → není to RC.
        // loadVatRateMap vrací [id => rate_percent] (float).
        $vatRates = $this->loadVatRateMap();
        foreach ($items as $it) {
            $rateId = (int) ($it['vat_rate_id'] ?? 0);
            $ratePercent = $vatRates[$rateId] ?? null;
            if ($ratePercent !== null && (float) $ratePercent > 0.0) return false;
        }
        // Vendor country lookup
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT co.iso2 FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?'
            );
            $stmt->execute([$vendorId]);
            $iso2 = (string) $stmt->fetchColumn();
            return $iso2 !== '' && $iso2 !== 'CZ';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Normalizuje document_kind z AI odpovědi na povolený enum
     * (whitelist matchující ENUM v `purchase_invoices.document_kind`).
     */
    private function normalizeDocumentKind(string $kind): string
    {
        $k = strtolower(trim($kind));
        return in_array($k, ['invoice', 'credit_note', 'advance', 'receipt'], true)
            ? $k
            : 'invoice';
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'AI-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }

    /**
     * Attach originální PDF bytes k vytvořené přijaté faktuře (uloží do archive,
     * setne pdf_path/hash/size na faktuře). Silent fail — pokud archive není
     * dostupný, faktura zůstane bez PDF (lze nahrát ručně později).
     */
    private function attachPdf(int $invoiceId, int $supplierId, string $pdfBytes, ?string $originalFilename): void
    {
        try {
            $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
            if ($archiveRoot === '') {
                $archiveRoot = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
            }
            $tenantDir = $archiveRoot . '/supplier-' . $supplierId;
            if (!is_dir($tenantDir)) {
                @mkdir($tenantDir, 0755, true);
            }
            $sha256 = hash('sha256', $pdfBytes);
            $diskName = substr($sha256, 0, 16) . '.pdf';
            $finalPath = $tenantDir . '/' . $diskName;
            if (!is_file($finalPath)) {
                @file_put_contents($finalPath, $pdfBytes);
            }
            $relativePath = 'supplier-' . $supplierId . '/' . $diskName;
            $size = (int) @filesize($finalPath);
            $name = $originalFilename ?: 'ai-imported.pdf';
            $this->repo->setPdfMetadata($invoiceId, $supplierId, $relativePath, $sha256, $size, $name);
        } catch (\Throwable) {
            // Silent — extract success je důležitější než PDF attach.
        }
    }
}
