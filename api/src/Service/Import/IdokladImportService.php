<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use Psr\Log\LoggerInterface;

/**
 * iDoklad import orchestrátor.
 *
 * Volaný background workerem (api/bin/import-worker.php). Stahuje:
 *   1. Contacts          → clients (dedup přes idoklad_id)
 *   2. IssuedInvoices    → invoices (dedup přes idoklad_id) — vč. dobropisů (InvoiceType=3)
 *   3. ReceivedInvoices  → purchase_invoices (dedup přes idoklad_id)
 *
 * Pro každý záznam:
 *   - Check existence (supplier_id, idoklad_id) → skip pokud existuje
 *   - Insert nový + nastavit idoklad_id
 *   - Update progress každých 10 items + appendLog
 *
 * Cancellation: každých 10 items check cancel_requested → graceful exit.
 *
 * Date parsing fallback: ReceivedInvoices.DateOfIssue je často NULL, pak
 * DateOfAccountingEvent (per fork bug fix `Fix ReceivedInvoices date parsing`).
 */
final class IdokladImportService
{
    private const PROGRESS_FLUSH_EVERY = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly IdokladClient $idoklad,
        private readonly ImportJobRepository $jobs,
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly InvoiceCalculator $invCalc,
        private readonly PurchaseInvoiceCalculator $purCalc,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PurchaseInvoiceCnbApplier $cnbApplier,
        private readonly SnapshotBuilder $snapshots,
        private readonly ImageToPdfConverter $imageToPdf,
    ) {}

    /**
     * Spustí job. Volá worker, ne přímo UI (UI vytvoří job a vrátí, worker pak picknul).
     *
     * @param array<string,mixed> $params  z import_jobs.params:
     *   - include_clients: bool (default true)
     *   - include_issued: bool (default true)
     *   - include_received: bool (default true)
     *   - include_receipts: bool (default true) — přijaté účtenky/paragony
     *   - dry_run: bool (default false)
     */
    public function run(int $jobId): void
    {
        // Reload job uvnitř transakce — race-safe markRunning
        $job = $this->loadJob($jobId);
        if (!$this->jobs->markRunning($jobId)) {
            // Někdo jiný už picknul nebo byl cancelled
            return;
        }
        try {
            $params = $job['params'] ?? [];
            $supplierId = (int) $job['supplier_id'];
            $userId = (int) $job['created_by'];
            $dryRun = !empty($params['dry_run']);

            $incremental = !empty($params['incremental']);
            $downloadAttachments = !empty($params['download_attachments']);
            $bookmarkSince = $incremental ? $this->loadBookmark($supplierId) : null;

            $msg = 'Import zahájen' . ($dryRun ? ' (dry-run)' : '');
            if ($incremental && $bookmarkSince !== null) $msg .= ', incremental od ' . $bookmarkSince;
            if ($downloadAttachments) $msg .= ', s přílohami';
            $this->jobs->appendLog($jobId, $msg . '.');

            if (!empty($params['include_clients']) || ($params['include_clients'] ?? null) === null) {
                $this->importClients($jobId, $supplierId, $userId, $dryRun, $bookmarkSince);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_issued']) || ($params['include_issued'] ?? null) === null) {
                $this->importIssued($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
                $this->importIssuedCorrections($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_received']) || ($params['include_received'] ?? null) === null) {
                $this->importReceived($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_receipts']) || ($params['include_receipts'] ?? null) === null) {
                $this->importReceipts($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
            }

            // Mark completed + bookmark
            $this->jobs->appendLog($jobId, 'Import dokončen.');
            $this->jobs->markCompleted($jobId);
            $this->db->pdo()->prepare(
                'UPDATE supplier SET idoklad_last_imported_at = NOW() WHERE id = ?'
            )->execute([$supplierId]);
        } catch (CancelledException $e) {
            $this->jobs->appendLog($jobId, 'Import zrušen uživatelem.');
            $this->jobs->markCancelled($jobId);
        } catch (\Throwable $e) {
            $this->logger->error('iDoklad import failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->jobs->appendLog($jobId, 'FAIL: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    private function loadJob(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Import job #{$jobId} nenalezen.");
        }
        if (!empty($row['params'])) {
            $row['params'] = json_decode((string) $row['params'], true);
        }
        return $row;
    }

    private function checkCancel(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new CancelledException();
        }
    }

    /**
     * Import Contacts → clients. Dedup přes (supplier_id, idoklad_id).
     */
    private function importClients(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing contacts…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji kontakty z iDoklad' . ($bookmarkSince ? " (>{$bookmarkSince})" : '') . '…');

        // Incremental sync: iDoklad v3 filtr ve tvaru `column~operator~value` (#197).
        $query = self::incrementalFilter($bookmarkSince);

        $created = 0; $skipped = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'Contacts', $query) as $contact) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($contact['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            // Create — map iDoklad Contact → clients schema
            try {
                $clientId = $this->createClientFromIdoklad($contact, $supplierId);
                $this->db->pdo()->prepare(
                    'UPDATE clients SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $clientId]);
                $created++;
            } catch (\Throwable $e) {
                $this->jobs->appendLog($jobId, "Kontakt {$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
        $this->jobs->appendLog($jobId, "Kontakty: vytvořeno {$created}, přeskočeno {$skipped} (z {$processed}).");
    }

    /**
     * Map iDoklad Contact → clients row + create.
     */
    private function createClientFromIdoklad(array $c, int $supplierId): int
    {
        $countryIso2 = $this->idokladCountryIso2($c, $supplierId);
        // iDoklad JSON pole je `Firstname` (malé n), ne `FirstName`. Fallback na obojí.
        $personName = trim((string) ($c['Firstname'] ?? $c['FirstName'] ?? '') . ' ' . (string) ($c['Surname'] ?? ''));
        $data = [
            'company_name' => (string) ($c['CompanyName'] ?? '') ?: ($personName ?: 'iDoklad import'),
            'ic'           => (string) ($c['IdentificationNumber'] ?? '') ?: null,
            'dic'          => (string) ($c['VatIdentificationNumber'] ?? '') ?: null,
            'street'       => (string) ($c['Street'] ?? '—'),
            'city'         => (string) ($c['City'] ?? '—'),
            'zip'          => (string) ($c['PostalCode'] ?? '00000'),
            'country_iso2' => $countryIso2,
            'main_email'   => (string) ($c['Email'] ?? '') ?: 'unknown@import.local',
            'phone'        => (string) ($c['Phone'] ?? '') ?: null,
            'language'     => 'cs',
            'is_customer'  => true,
            'is_vendor'    => false,
        ];
        return $this->clients->create($data, $supplierId);
    }

    /**
     * Import IssuedInvoices → invoices. Mapping: header + items; status='draft',
     * ledaže iDoklad doklad eviduje jako zaplacený (PaymentStatus 1/3) → 'paid'
     * s paid_at = DateOfPayment (#121, viz ImportedPaymentStateMapper).
     * Dobropisy (CreditNotes) jsou separátní endpoint a dělají se ve fázi 3.
     *
     * Note: faktury z iDoklad nemají project_id (oni nemají koncept projektů jako my)
     * — project_id = NULL. Uživatel může později ručně přiřadit.
     */
    private function importIssued(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing issued invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji vydané faktury z iDoklad…');

        $query = self::incrementalFilter($bookmarkSince);

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'IssuedInvoices', $query) as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $invoiceId = $this->createIssuedFromIdoklad($idoklad, $supplierId, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $invoiceId]);
                $this->invCalc->recompute($invoiceId);
                if ($downloadAttachments) {
                    $this->archiveIssuedPdf($supplierId, $invoiceId, $idokladId, $idoklad);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Vydaná #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Vydané faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu vydanou fakturu z iDoklad payloadu.
     */
    private function createIssuedFromIdoklad(array $i, int $supplierId, int $userId): int
    {
        // Resolve client by PartnerId → idoklad_id v clients
        $partnerId = (int) ($i['PartnerId'] ?? 0);
        $clientId = $this->resolveClientByIdoklad($partnerId, $supplierId);
        if ($clientId === null) {
            throw new \RuntimeException("Klient s iDoklad ID {$partnerId} nenalezen — nejdřív naimportuj kontakty.");
        }

        $invoiceType = $this->mapIssuedDocumentType((int) ($i['DocumentType'] ?? 0));

        // Sleva (issue #48/#50): iDoklad drží slevu na hlavičce (DiscountPercentage +
        // DiscountType) i na položce (Items[].DiscountPercentage). Slevu na úrovni
        // dokladu (DiscountType=OnDocument=3) mapujeme na naše invoices.discount_percent
        // — InvoiceRepository::replaceItems z ní dopočítá zápornou položku „Sleva X %".
        // Položkovou slevu (Individual/Grouped) zapečeme do jednotkové ceny, ať částka
        // sedí i bez položkové slevy v našem modelu.
        $docDiscountOnDocument = (int) ($i['DiscountType'] ?? 0) === 3;
        $docDiscountPercent = $docDiscountOnDocument ? (float) ($i['DiscountPercentage'] ?? 0) : 0.0;

        $payload = [
            'invoice_type'     => $invoiceType,
            'client_id'        => $clientId,
            'issue_date'       => (string) ($i['DateOfIssue'] ?? date('Y-m-d')),
            'tax_date'         => $invoiceType === 'proforma' ? null : (string) ($i['DateOfTaxing'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
            'due_date'         => (string) ($i['DateOfMaturity'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
            'currency_id'      => $this->resolveIssuedCurrencyId($i, $supplierId),
            'reverse_charge'   => false,
            'language'         => 'cs',
            // varsymbol = číslo dokladu (unikátní per dodavatel), NE platební VariableSymbol (#196).
            'varsymbol'        => $this->sanitizeVarsymbol(self::idokladDocNumber($i)),
            'payment_method'   => 'bank_transfer',
            'discount_percent' => $docDiscountPercent,
        ];

        $invoiceId = $this->invoices->createDraft($payload, $userId);

        // Items
        $vatRates = $this->loadVatRateMap();
        $items = [];
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate);
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => (float) ($line['Amount'] ?? 1),
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => self::idokladNetUnitPrice($line, $rate),
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $idx,
            ];
        }
        if (!empty($items)) {
            $this->invoices->replaceItems($invoiceId, $items);
        }

        // #121: promítni platební stav z iDokladu (PaymentStatus 1=Paid / 3=Overpaid)
        // — zaplacené historické doklady nesmí zůstat viset jako nezaplacené pohledávky.
        $this->applyIssuedPaymentState(
            $invoiceId,
            $clientId,
            (int) $payload['currency_id'],
            $supplierId,
            ImportedPaymentStateMapper::fromIdoklad($i),
            (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
            (string) $payload['issue_date'],
        );
        return $invoiceId;
    }

    /**
     * Aplikuje namapovaný platební stav na čerstvě importovanou vydanou fakturu
     * (issue #121). Jen pro doklady ve stavu 'draft' (guard v WHERE). Doklad opouští
     * 'draft', proto dostává snapshoty (client/supplier/bank) stejně jako file import
     * (InvoiceImportService) a IssueInvoiceAction; sent_at = issue_date 12:00 (stejná
     * aproximace jako file import).
     *
     * @param ?array{status:string, paid_at:?string} $state  null = ponechat draft
     */
    private function applyIssuedPaymentState(int $invoiceId, int $clientId, int $currencyId, int $supplierId, ?array $state, string $fallbackPaidAt, string $issueDate): void
    {
        if ($state === null || $state['status'] !== 'paid') return;

        $snapshots = $this->snapshots->build($clientId, $currencyId, $supplierId);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = ?, sent_at = ?,
                    client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ?
              WHERE id = ? AND status = 'draft'"
        )->execute([
            $state['paid_at'] ?? $fallbackPaidAt,
            $issueDate . ' 12:00:00',
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);
    }

    /**
     * Aplikuje 'paid' na čerstvě importovanou přijatou fakturu (issue #121).
     * Guard na status='draft' — createReceivedFromIdoklad může přes dedup guard
     * vrátit existující (už zpracovaný) doklad, ten nepřepisujeme.
     */
    private function applyPurchasePaymentState(int $purchaseId, int $supplierId, ?array $state, string $fallbackPaidAt): void
    {
        if ($state === null || $state['status'] !== 'paid') return;
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        )->execute([$state['paid_at'] ?? $fallbackPaidAt, $purchaseId, $supplierId]);
    }

    /**
     * Import ReceivedInvoices → purchase_invoices.
     *
     * Per fork bug fix: DateOfIssue často NULL pro přijaté, fallback DateOfAccountingEvent.
     */
    private function importReceived(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing received invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji přijaté faktury z iDoklad…');

        $query = self::incrementalFilter($bookmarkSince);

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'ReceivedInvoices', $query) as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $purchaseId = $this->createReceivedFromIdoklad($idoklad, $supplierId, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE purchase_invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $purchaseId]);
                $this->purCalc->recompute($purchaseId);
                if ($downloadAttachments) {
                    $this->archiveReceivedPdf($supplierId, $purchaseId, $idokladId);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Přijatá #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Přijaté faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu přijatou fakturu z iDoklad payloadu.
     */
    private function createReceivedFromIdoklad(array $i, int $supplierId, int $userId): int
    {
        // Resolve vendor by PartnerId → idoklad_id v clients (with is_vendor flag)
        $partnerId = (int) ($i['PartnerId'] ?? 0);
        $vendorId = $this->resolveClientByIdoklad($partnerId, $supplierId);
        if ($vendorId === null) {
            throw new \RuntimeException("Dodavatel s iDoklad ID {$partnerId} nenalezen — nejdřív naimportuj kontakty.");
        }
        // Promote na vendor (might be already-imported customer)
        $this->clients->markAsVendor($vendorId);

        // Date fallback: DateOfIssue → DateOfAccountingEvent → today (per fork bug fix)
        $issueDate = (string) ($i['DateOfIssue'] ?? $i['DateOfAccountingEvent'] ?? '') ?: date('Y-m-d');
        $taxDate   = (string) ($i['DateOfTaxing'] ?? $issueDate);
        $dueDate   = (string) ($i['DateOfMaturity'] ?? $issueDate);

        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 21.0) ?? $this->matchVatRateId($vatRates, 0.0) ?? 0;

        // Sleva (issue #48): přijaté faktury nemají header discount_percent — slevu
        // z iDokladu (DiscountType=OnDocument) materializujeme rovnou jako zápornou
        // položku „Sleva X %" na každou sazbu DPH (per-rate split = správné DPH).
        // Položkovou slevu (Individual/Grouped) zapečeme do jednotkové ceny.
        $docDiscountOnDocument = (int) ($i['DiscountType'] ?? 0) === 3;
        $docDiscountPercent = $docDiscountOnDocument ? (float) ($i['DiscountPercentage'] ?? 0) : 0.0;

        $items = [];
        $discountBaseByRate = []; // vat_rate_id => ['rate_id' => int, 'base' => float]
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId;
            $qty = (float) ($line['Amount'] ?? 1);
            $unitPrice = self::idokladNetUnitPrice($line, $rate);
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => $qty,
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => $unitPrice,
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $idx,
            ];
            if ($docDiscountPercent > 0) {
                if (!isset($discountBaseByRate[$vatRateId])) {
                    $discountBaseByRate[$vatRateId] = ['rate_id' => $vatRateId, 'base' => 0.0];
                }
                $discountBaseByRate[$vatRateId]['base'] += round($qty * $unitPrice, 2);
            }
        }

        if ($docDiscountPercent > 0 && $discountBaseByRate !== []) {
            $order = count($items);
            foreach ($discountBaseByRate as $g) {
                $disc = round($g['base'] * $docDiscountPercent / 100.0, 2);
                if ($disc == 0.0) {
                    continue;
                }
                $items[] = [
                    'description'            => InvoiceRepository::discountLabel($docDiscountPercent, 'cs'),
                    'quantity'               => 1.0,
                    'unit'                   => '',
                    'unit_price_without_vat' => -$disc,
                    'vat_rate_id'            => $g['rate_id'],
                    'order_index'            => $order++,
                ];
            }
        }

        // Auto-detect reverse charge: vendor non-CZ + všechny items vat_rate=0
        // (typicky přijatá faktura z EU s reverse charge). Stejná heuristika jako AI extractor.
        $reverseCharge = $this->inferReverseChargeFromItems($vendorId, $items);

        $currencyCode = $this->idokladCurrencyCode($i, $supplierId);

        $payload = [
            'vendor_id'             => $vendorId,
            // U přijatých je `DocumentNumber` interní číslo iDokladu; číslo dodavatele je
            // `ReceivedDocumentNumber`. Preferuj to dodavatelovo (fallback na DocumentNumber).
            'vendor_invoice_number' => $this->sanitizeVendorNumber((string) ($i['ReceivedDocumentNumber'] ?? $i['DocumentNumber'] ?? '')),
            'document_kind'         => 'invoice',
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId($currencyCode, $supplierId, isActive: false),
            'exchange_rate'         => self::idokladExchangeRate($i),
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => $reverseCharge,
            'language'              => 'cs',
            'items'                 => $items,
        ];

        // Dedup guard — re-import stejné faktury (typicky při opakovaném pullu z iDokladu)
        // by jinak hodil SQL 23000 duplicate key. Vrátíme existující ID.
        $existingId = $this->purchaseRepo->findIdByVendorInvoice(
            $supplierId, $vendorId,
            (string) $payload['vendor_invoice_number'],
            (string) $payload['issue_date'],
        );
        if ($existingId !== null) {
            return $existingId;
        }

        $id = $this->purchaseRepo->createDraft($payload, $userId, $supplierId);
        if (!empty($items)) {
            $this->purchaseRepo->replaceItems($id, $items);
        }
        // Auto-ČNB kurz pro non-CZK fakturu pokud iDoklad neobsahoval explicitní kurz
        $this->cnbApplier->applyIfMissing(
            $id,
            $supplierId,
            $currencyCode,
            (string) ($payload['tax_date'] ?? $payload['issue_date'] ?? ''),
            $payload['exchange_rate'] ?? null,
        );

        // Seed override rekapitulace DPH dle dokladu (§ 73). iDoklad nevrací globální
        // rekapitulaci, ale per-řádek autoritativní Prices (TotalWithoutVat/TotalVat),
        // takže ji poskládáme agregací po sazbě. Dokladovou slevu (materializovanou
        // jako záporné řádky) přeskakujeme — per-řádkové Prices ji nezahrnují, takže
        // by recap neseděl na náš (po-slevový) dopočet.
        if ($docDiscountPercent <= 0.0) {
            $docByRate = self::idokladVatRecap($i['Items'] ?? []);
            if ($docByRate !== []) {
                $this->purCalc->recompute($id);
                $warning = (new PurchaseVatRecapSeeder($this->purchaseRepo, $this->purCalc))->seed(
                    $id,
                    $supplierId,
                    $docByRate,
                    $currencyCode,
                    false,
                );
                if ($warning !== null) {
                    try {
                        $this->purchaseRepo->appendExtractionWarning($id, $supplierId, $warning);
                    } catch (\Throwable) {
                        // Varování je „nice to have".
                    }
                }
            }
        }

        // #121: iDoklad eviduje doklad jako zaplacený → promítni (jen na čerstvě
        // vytvořený doklad; dedup-vrácený existující doklad výše se nemění).
        $this->applyPurchasePaymentState(
            $id,
            $supplierId,
            ImportedPaymentStateMapper::fromIdoklad($i),
            $taxDate ?: $issueDate,
        );

        return $id;
    }

    /**
     * Import ReceivedReceipts (přijaté účtenky / paragony) → purchase_invoices (document_kind='receipt').
     *
     * iDoklad endpoint GET /v3/ReceivedReceipts používá stejný paginated envelope jako
     * ReceivedInvoices, takže řídicí smyčka je shodná s importReceived(). Liší se mapování:
     * účtenky nemají splatnost ani DUZP a Partner může být null (hotovostní nákup) — viz
     * createReceiptFromIdoklad().
     */
    private function importReceipts(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing received receipts…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji přijaté účtenky z iDoklad…');

        $query = self::incrementalFilter($bookmarkSince);

        $created = 0; $skipped = 0; $failed = 0; $processed = 0;
        foreach ($this->idoklad->getAll($supplierId, 'ReceivedReceipts', $query) as $idoklad) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $idokladId = (int) ($idoklad['Id'] ?? 0);
            if ($idokladId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $purchaseId = $this->createReceiptFromIdoklad($idoklad, $supplierId, $userId);
                if ($purchaseId === null) { $skipped++; continue; } // hotovostní účtenka bez Partnera
                $this->db->pdo()->prepare(
                    'UPDATE purchase_invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $purchaseId]);
                $this->purCalc->recompute($purchaseId);
                if ($downloadAttachments) {
                    $this->archiveReceivedPdf($supplierId, $purchaseId, $idokladId, 'ReceivedReceipt');
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Účtenka #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Přijaté účtenky: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    /**
     * Vytvoří jednu přijatou účtenku z iDoklad payloadu jako purchase_invoice s document_kind='receipt'.
     *
     * Rozdíly oproti createReceivedFromIdoklad():
     *   • Partner je vnořený a MŮŽE být null (hotovostní nákup bez kontaktu) → doklad se naváže
     *     na sběrného systémového dodavatele „Hotovostní nákup (účtenka)" (náklad se neztratí)
     *     a importuje se bez nároku na odpočet DPH (viz níže).
     *   • Účtenka nemá splatnost (DateOfMaturity) ani DUZP (DateOfTaxing) → vše z DateOfIssue.
     *   • Číslo dokladu dodavatele je ExternalDocumentNumber (ne ReceivedDocumentNumber); často chybí.
     *   • iDoklad u účtenek nevrací hlavičkovou slevu (DiscountType/DiscountPercentage).
     */
    private function createReceiptFromIdoklad(array $i, int $supplierId, int $userId): ?int
    {
        $hdr = self::idokladReceiptHeader($i, date('Y-m-d'));

        // Hotovostní účtenka bez kontaktu (Partner == null) — dodavatele neznáme. Místo zahození
        // nákladu ji navážeme na sběrného systémového vendora a odpočet DPH necháme neuplatněný
        // (nemůžeme ověřit dodavatele ani jeho plátcovství — bezpečný default pro plátce; neplátce
        // to neřeší). Uživatel může po doplnění dodavatele odpočet povolit.
        $unknownVendor = ($hdr['partner_id'] === 0);
        if ($unknownVendor) {
            $vendorId = $this->clients->findOrCreateCashReceiptVendor($supplierId);
        } else {
            $vendorId = $this->resolveClientByIdoklad($hdr['partner_id'], $supplierId);
            if ($vendorId === null) {
                throw new \RuntimeException("Dodavatel s iDoklad ID {$hdr['partner_id']} nenalezen — nejdřív naimportuj kontakty.");
            }
            $this->clients->markAsVendor($vendorId);
        }

        // Číslo dokladu — createDraft ho vyžaduje neprázdné; hotovostní účtenka ho nemusí mít.
        // Fallback na stabilní iDoklad Id, aby dedup (vendor+číslo+datum) i re-import fungovaly.
        $vendorNumber = $this->sanitizeVendorNumber($hdr['vendor_invoice_number']);
        if ($vendorNumber === '') {
            $vendorNumber = 'UCT-' . (int) ($i['Id'] ?? 0);
        }

        $issueDate = $hdr['issue_date'];
        $taxDate   = $hdr['tax_date'];
        $dueDate   = $hdr['due_date'];

        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 21.0) ?? $this->matchVatRateId($vatRates, 0.0) ?? 0;

        // Položky — stejný tvar jako ReceivedInvoices (Amount/Name/Unit/VatRate + per-řádek Prices).
        // idokladNetUnitPrice() řeší i PriceType=WithVat (účtenky bývají ceny s DPH).
        $items = [];
        foreach (($i['Items'] ?? []) as $idx => $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId;
            $items[] = [
                'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                'quantity'               => (float) ($line['Amount'] ?? 1),
                'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                'unit_price_without_vat' => self::idokladNetUnitPrice($line, $rate),
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $idx,
            ];
        }

        $reverseCharge = $this->inferReverseChargeFromItems($vendorId, $items);
        $currencyCode  = $this->idokladCurrencyCode($i, $supplierId);

        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $vendorNumber,
            'document_kind'         => 'receipt',
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId($currencyCode, $supplierId, isActive: false),
            'exchange_rate'         => self::idokladExchangeRate($i),
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => $reverseCharge,
            // Neznámý dodavatel (hotovostní účtenka) → bez nároku na odpočet DPH. Import jde přes
            // createDraft(), který NEuplatňuje auto-none z CreatePurchaseInvoiceAction, proto explicitně.
            'vat_deduction'         => $unknownVendor ? 'none' : 'full',
            'language'              => 'cs',
            'items'                 => $items,
        ];

        // Dedup guard — re-import stejné účtenky (typicky při opakovaném pullu) by jinak hodil
        // SQL 23000 duplicate key. Vrátíme existující ID.
        $existingId = $this->purchaseRepo->findIdByVendorInvoice(
            $supplierId, $vendorId,
            (string) $payload['vendor_invoice_number'],
            (string) $payload['issue_date'],
        );
        if ($existingId !== null) {
            return $existingId;
        }

        $id = $this->purchaseRepo->createDraft($payload, $userId, $supplierId);
        if (!empty($items)) {
            $this->purchaseRepo->replaceItems($id, $items);
        }
        $this->cnbApplier->applyIfMissing(
            $id,
            $supplierId,
            $currencyCode,
            (string) ($payload['tax_date'] ?? $payload['issue_date'] ?? ''),
            $payload['exchange_rate'] ?? null,
        );

        // Seed override rekapitulace DPH z per-řádek Prices (stejně jako createReceivedFromIdoklad).
        // Účtenka nemá hlavičkovou slevu, takže recap seedujeme bezpodmínečně, je-li kompletní.
        $docByRate = self::idokladVatRecap($i['Items'] ?? []);
        if ($docByRate !== []) {
            $this->purCalc->recompute($id);
            $warning = (new PurchaseVatRecapSeeder($this->purchaseRepo, $this->purCalc))->seed(
                $id,
                $supplierId,
                $docByRate,
                $currencyCode,
                false,
            );
            if ($warning !== null) {
                try {
                    $this->purchaseRepo->appendExtractionWarning($id, $supplierId, $warning);
                } catch (\Throwable) {
                    // Varování je „nice to have".
                }
            }
        }

        // Hotovostní účtenka bez identifikace dodavatele — upozorni na revizi (odpočet je záměrně
        // neuplatněný; uživatel po doplnění dodavatele může nárok povolit).
        if ($unknownVendor) {
            try {
                $this->purchaseRepo->appendExtractionWarning(
                    $id,
                    $supplierId,
                    'Účtenka bez identifikace dodavatele (hotovostní nákup) — pro uplatnění odpočtu DPH doplňte dodavatele a povolte odpočet.',
                );
            } catch (\Throwable) {
                // Varování je „nice to have".
            }
        }

        // Účtenka/paragon je hrazená na místě → import rovnou jako zaplacená (paid_at = datum
        // vystavení). ReceivedReceipts payload obvykle PaymentStatus nenese (ověřeno živě: null),
        // takže fromIdoklad() vrátí null; přesto když by iDoklad stav úhrady vrátil, respektuj ho.
        $paymentState = ImportedPaymentStateMapper::fromIdoklad($i)
            ?? ['status' => 'paid', 'paid_at' => $issueDate];
        $this->applyPurchasePaymentState($id, $supplierId, $paymentState, $issueDate);

        return $id;
    }

    /**
     * Receipt-specifické mapování hlavičky (čistá funkce, bez DB — proto unit-testovatelná).
     *
     * Účtenky se od přijatých faktur liší: Partner je vnořený a může být null (hotovostní
     * nákup), chybí splatnost (DateOfMaturity) i DUZP (DateOfTaxing) → issue/tax/due odvodíme
     * shodně z DateOfIssue; číslo dokladu dodavatele je ExternalDocumentNumber (fallback
     * DocumentNumber) — účtenka ho nemusí mít vůbec, prázdné je u document_kind='receipt' OK.
     *
     * @param array<string,mixed> $i      iDoklad ReceivedReceipt payload
     * @param string              $today  fallback datum (Y-m-d), když DateOfIssue chybí
     * @return array{partner_id:int, vendor_invoice_number:string, issue_date:string, tax_date:string, due_date:string}
     */
    public static function idokladReceiptHeader(array $i, string $today): array
    {
        $partnerId = (int) ($i['Partner']['Id'] ?? $i['PartnerId'] ?? 0);
        $issueDate = (string) ($i['DateOfIssue'] ?? '') ?: $today;

        return [
            'partner_id'            => $partnerId,
            'vendor_invoice_number' => (string) ($i['ExternalDocumentNumber'] ?? $i['DocumentNumber'] ?? ''),
            'issue_date'            => $issueDate,
            'tax_date'              => $issueDate,
            'due_date'              => $issueDate,
        ];
    }

    /**
     * Poskládá rekapitulaci DPH po sazbách z iDoklad řádkových Prices (autoritativní
     * hodnoty z iDokladu). Vrací rateKey => kladné `{base, vat}`. Když některý řádek
     * Prices nemá, vrátí prázdné pole (neseedujeme z neúplných dat).
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<string,array{base:float,vat:float}>
     */
    private static function idokladVatRecap(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $rate = (float) ($line['VatRate'] ?? 0);
            if ($rate <= 0.0) {
                continue; // 0 % / osvobozeno → neseedujeme
            }
            $prices = $line['Prices'] ?? null;
            if (!is_array($prices) || !isset($prices['TotalWithoutVat'], $prices['TotalVat'])) {
                return []; // chybí autoritativní data → neseedovat
            }
            $key = number_format($rate, 2, '.', '');
            if (!isset($out[$key])) {
                $out[$key] = ['base' => 0.0, 'vat' => 0.0];
            }
            $out[$key]['base'] += abs((float) $prices['TotalWithoutVat']);
            $out[$key]['vat']  += abs((float) $prices['TotalVat']);
        }
        return $out;
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function resolveClientByIdoklad(int $partnerId, int $supplierId): ?int
    {
        if ($partnerId === 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $partnerId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Lookup or auto-create currency. Pro vydané faktury (issued) je třeba is_active=1
     * (musíme mít bankovní účet); pro přijaté stačí is_active=0 (jen pro nákupní cyklus).
     */
    private function resolveCurrencyId(string $code, int $supplierId, bool $isActive): int
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
             VALUES (?, ?, ?, ?, ?, ?, 2, ?, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code, $isActive ? 1 : 0]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Účet vydané faktury podle historických údajů `MyAddress` z iDokladu.
     *
     * Jedna měna může mít v MyInvoice více bankovních účtů. Samotný CurrencyId
     * proto nestačí: nejprve hledáme přesnou dvojici číslo účtu + kód banky
     * uloženou na konkrétní faktuře. Výchozí účet měny je pouze fallback pro
     * doklady bez bankovních údajů nebo bez jednoznačné lokální shody.
     *
     * @param array<string,mixed> $doc
     */
    private function resolveIssuedCurrencyId(array $doc, int $supplierId): int
    {
        $code = $this->idokladCurrencyCode($doc, $supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_number, bank_code, iban FROM currencies
              WHERE supplier_id = ? AND code = ? AND is_active = 1
              ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([$supplierId, strtoupper(trim($code)) ?: 'CZK']);
        $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $matchedId = self::matchIssuedBankAccount($doc, $accounts);
        if ($matchedId !== null) {
            return $matchedId;
        }

        $address = is_array($doc['MyAddress'] ?? null) ? $doc['MyAddress'] : [];
        if (trim((string) ($address['AccountNumber'] ?? '')) !== '') {
            $this->logger->warning('iDoklad issued invoice bank account was not matched uniquely; using currency default', [
                'supplier_id' => $supplierId,
                'idoklad_id'  => (int) ($doc['Id'] ?? 0),
                'bank_code'   => trim((string) ($address['BankCode'] ?? '')) ?: null,
                'currency'    => strtoupper(trim($code)) ?: 'CZK',
            ]);
        }

        return $this->resolveCurrencyId($code, $supplierId, isActive: true);
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<array{id:mixed,account_number:mixed,bank_code:mixed,iban?:mixed}> $accounts
     */
    public static function matchIssuedBankAccount(array $doc, array $accounts): ?int
    {
        $address = is_array($doc['MyAddress'] ?? null) ? $doc['MyAddress'] : [];
        $accountNumber = trim((string) ($address['AccountNumber'] ?? ''));
        $bankCode = preg_replace('/\D/', '', (string) ($address['BankCode'] ?? '')) ?? '';
        if ($accountNumber === '') {
            return null;
        }

        $matches = [];
        foreach ($accounts as $account) {
            $iban = isset($account['iban']) && is_string($account['iban']) ? $account['iban'] : null;
            if (!AccountNumberNormalizer::matchesAny($accountNumber, $account['account_number'] ?? null, $iban)) {
                continue;
            }

            $candidateBank = preg_replace('/\D/', '', (string) ($account['bank_code'] ?? '')) ?? '';
            if ($candidateBank === '' && $iban !== null) {
                $candidateBank = AccountNumberNormalizer::czechIbanBankCode($iban) ?? '';
            }
            if ($bankCode !== '' && $candidateBank !== $bankCode) {
                continue;
            }
            $matches[] = (int) $account['id'];
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * ISO kód měny dokladu. iDoklad list endpointy vrací jen `CurrencyId` (int) — přeložíme
     * přes /Currencies mapu. Fallback na legacy nested `Currency.Code` / `CurrencyCode`, pak CZK.
     */
    private function idokladCurrencyCode(array $doc, int $supplierId): string
    {
        $cid = (int) ($doc['CurrencyId'] ?? 0);
        if ($cid > 0) {
            $map = $this->idoklad->currencyCodeMap($supplierId);
            if (isset($map[$cid])) return $map[$cid];
        }
        return (string) ($doc['Currency']['Code'] ?? $doc['CurrencyCode'] ?? 'CZK') ?: 'CZK';
    }

    /**
     * Kurz dokladu přepočtený na „kolik CZK za 1 jednotku měny".
     *
     * iDoklad drží kurz jako `ExchangeRate` na `ExchangeRateAmount` jednotek (u měn jako
     * HUF/JPY je Amount=100). Náš model očekává kurz na 1 jednotku → dělíme. Bez toho byl
     * kurz u těchto měn 100× špatně (viz #80 audit).
     */
    public static function idokladExchangeRate(array $doc): ?float
    {
        if (!isset($doc['ExchangeRate'])) return null;
        $rate = (float) $doc['ExchangeRate'];
        $amount = (float) ($doc['ExchangeRateAmount'] ?? 1);
        if ($amount > 0.0 && $amount !== 1.0) {
            $rate /= $amount;
        }
        return $rate;
    }

    /**
     * ISO2 země kontaktu. iDoklad list endpoint vrací jen `CountryId` (int) — přeložíme
     * přes /Countries mapu. Fallback na legacy nested `Country.Code`, pak CZ.
     */
    private function idokladCountryIso2(array $contact, int $supplierId): string
    {
        $cid = (int) ($contact['CountryId'] ?? 0);
        if ($cid > 0) {
            $map = $this->idoklad->countryCodeMap($supplierId);
            if (isset($map[$cid])) return $map[$cid];
        }
        return strtoupper((string) ($contact['Country']['Code'] ?? 'CZ')) ?: 'CZ';
    }

    /**
     * @return array<int, float>  id → rate_percent
     */
    private function loadVatRateMap(): array
    {
        // vat_rates nemá is_active — platnost se řídí valid_from/valid_to (k dnešku).
        $rows = $this->db->pdo()->query(
            'SELECT id, rate_percent FROM vat_rates
              WHERE (valid_from IS NULL OR valid_from <= CURDATE())
                AND (valid_to   IS NULL OR valid_to   >= CURDATE())'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (float) $r['rate_percent'];
        }
        return $map;
    }

    private function matchVatRateId(array $vatRates, float $rate): ?int
    {
        foreach ($vatRates as $id => $r) {
            if (abs($r - $rate) < 0.01) return $id;
        }
        return null;
    }

    /**
     * Netto jednotková cena (bez DPH, po případné položkové slevě) z iDoklad v3 položky.
     *
     * iDoklad v3 GET model NEMÁ top-level `UnitPrice` — zadaná cena je vnořená v
     * `Prices.UnitPrice` a je v `PriceType` dokladu (WithVat=0 je DEFAULT!). Autoritativní
     * netto je `Prices.TotalWithoutVat` (už po položkové slevě), takže ho vydělíme
     * množstvím. Bez toho se `$line['UnitPrice']` vždy vyhodnotilo jako 0 → všechny
     * importované faktury (vydané i přijaté) měly 0 Kč (viz #80).
     *
     * Fallback (kdyby `Prices` chyběl): `Prices.UnitPrice` / legacy top-level `UnitPrice`
     * převedený dle PriceType na netto a po položkové slevě.
     */
    public static function idokladNetUnitPrice(array $line, float $vatRate): float
    {
        $prices = is_array($line['Prices'] ?? null) ? $line['Prices'] : [];
        $qty = (float) ($line['Amount'] ?? 1);

        if (isset($prices['TotalWithoutVat']) && $qty != 0.0) {
            return round((float) $prices['TotalWithoutVat'] / $qty, 4);
        }

        $unitPrice = (float) ($prices['UnitPrice'] ?? $line['UnitPrice'] ?? 0);
        // PriceType: 0 = WithVat (iDoklad default), 1 = WithoutVat, 2 = OnlyBase
        if ((int) ($line['PriceType'] ?? 0) === 0 && $vatRate > 0.0) {
            $unitPrice /= (1 + $vatRate / 100.0);
        }
        $itemDiscount = (float) ($line['DiscountPercentage'] ?? 0);
        if ($itemDiscount > 0) {
            $unitPrice *= (1 - $itemDiscount / 100.0);
        }
        return round($unitPrice, 4);
    }

    /**
     * Heuristika reverse charge (přenesená daňová povinnost):
     *   - vendor je v non-CZ zemi (EU/třetí země)
     *   - všechny items mají vat_rate = 0
     *
     * Stejná logika jako AiPdfExtractor::inferReverseCharge — duplikováno bez společného
     * helperu (kvůli rozdílným tvarům items + vat_rate_id mapy).
     */
    private function inferReverseChargeFromItems(int $vendorId, array $items): bool
    {
        if (empty($items)) return false;
        $vatRates = $this->loadVatRateMap();
        foreach ($items as $it) {
            $rateId = (int) ($it['vat_rate_id'] ?? 0);
            $ratePercent = $vatRates[$rateId] ?? null;
            if ($ratePercent !== null && (float) $ratePercent > 0.0) return false;
        }
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
     * iDoklad DocumentType pro vydané faktury:
     *   0 = Regular invoice
     *   1 = Proforma invoice
     *   2 = Tax document for advance payment
     *   3 = Final invoice (po proforma)
     *   5 = Credit note (= IssuedInvoiceCorrection separátní endpoint)
     */
    private function mapIssuedDocumentType(int $docType): string
    {
        return match ($docType) {
            1       => 'proforma',
            5       => 'credit_note',
            default => 'invoice',
        };
    }

    /**
     * Sanitize varsymbol pro DB column (varchar 20, [A-Za-z0-9_-]).
     */
    private function sanitizeVarsymbol(string $vs): string
    {
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '', $vs) ?? '';
        if ($vs === '') return 'IDOKLAD-' . substr((string) random_int(1000, 9999), 0, 4);
        return substr($vs, 0, 20);
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'IDOKLAD-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }

    /**
     * Import CreditNotes (dobropisy k vystaveným fakturám).
     * Mají CreditedInvoiceId odkazující na původní fakturu (idoklad_id),
     * což mapujeme na invoices.parent_invoice_id (po lookup do clients/invoices
     * podle naší db-side idoklad_id).
     */
    private function importIssuedCorrections(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince = null, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing credit notes…']);
        $this->jobs->appendLog($jobId, 'Stahuji dobropisy z iDoklad…');

        $query = self::incrementalFilter($bookmarkSince);
        $created = 0; $skipped = 0; $failed = 0;

        foreach ($this->idoklad->getAll($supplierId, 'CreditNotes', $query) as $i) {
            $idokladId = (int) ($i['Id'] ?? 0);
            if ($idokladId === 0) continue;

            // Dedup — dobropisy jsou v `invoices` tabulce s invoice_type='credit_note'
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $idokladId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                // Resolve parent invoice (iDoklad CreditNotes nese odkaz v CreditedInvoiceId)
                $parentIdokladId = (int) ($i['CreditedInvoiceId'] ?? 0);
                $parentInvoiceId = null;
                if ($parentIdokladId > 0) {
                    $s = $this->db->pdo()->prepare(
                        'SELECT id FROM invoices WHERE supplier_id = ? AND idoklad_id = ? LIMIT 1'
                    );
                    $s->execute([$supplierId, $parentIdokladId]);
                    $pid = $s->fetchColumn();
                    $parentInvoiceId = $pid !== false ? (int) $pid : null;
                }

                // Create as invoice_type='credit_note' + parent reference
                $partnerId = (int) ($i['PartnerId'] ?? 0);
                $clientId = $this->resolveClientByIdoklad($partnerId, $supplierId);
                if ($clientId === null) {
                    throw new \RuntimeException("Klient #{$partnerId} nenalezen — naimportuj nejdřív kontakty.");
                }

                $docDiscountOnDocument = (int) ($i['DiscountType'] ?? 0) === 3;
                $docDiscountPercent = $docDiscountOnDocument ? (float) ($i['DiscountPercentage'] ?? 0) : 0.0;

                $payload = [
                    'invoice_type'      => 'credit_note',
                    'parent_invoice_id' => $parentInvoiceId,
                    'client_id'         => $clientId,
                    'issue_date'        => (string) ($i['DateOfIssue'] ?? date('Y-m-d')),
                    'tax_date'          => (string) ($i['DateOfTaxing'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
                    'due_date'          => (string) ($i['DateOfMaturity'] ?? $i['DateOfIssue'] ?? date('Y-m-d')),
                    'currency_id'       => $this->resolveIssuedCurrencyId($i, $supplierId),
                    'reverse_charge'    => false,
                    'language'          => 'cs',
                    // varsymbol = číslo dokladu (unikátní per dodavatel), NE platební VariableSymbol (#196).
                    'varsymbol'         => $this->sanitizeVarsymbol(self::idokladDocNumber($i)),
                    'payment_method'    => 'bank_transfer',
                    'discount_percent'  => $docDiscountPercent,
                ];
                $invoiceId = $this->invoices->createDraft($payload, $userId);
                $this->db->pdo()->prepare(
                    'UPDATE invoices SET idoklad_id = ? WHERE id = ?'
                )->execute([$idokladId, $invoiceId]);

                // Items
                $vatRates = $this->loadVatRateMap();
                $items = [];
                foreach (($i['Items'] ?? []) as $idx => $line) {
                    $rate = (float) ($line['VatRate'] ?? 0);
                    $items[] = [
                        'description'            => (string) ($line['Name'] ?? $line['Description'] ?? ''),
                        'quantity'               => (float) ($line['Amount'] ?? 1),
                        'unit'                   => (string) ($line['Unit'] ?? 'ks'),
                        'unit_price_without_vat' => self::idokladNetUnitPrice($line, $rate),
                        'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate),
                        'order_index'            => $idx,
                    ];
                }
                if (!empty($items)) {
                    $this->invoices->replaceItems($invoiceId, $items);
                }
                // #121: vyrovnaný/uhrazený dobropis nesmí zůstat draft
                $this->applyIssuedPaymentState(
                    $invoiceId,
                    $clientId,
                    (int) $payload['currency_id'],
                    $supplierId,
                    ImportedPaymentStateMapper::fromIdoklad($i),
                    (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
                    (string) $payload['issue_date'],
                );
                $this->invCalc->recompute($invoiceId);
                if ($downloadAttachments) {
                    $this->archiveIssuedPdf($supplierId, $invoiceId, $idokladId, $i);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Dobropis #{$idokladId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Dobropisy: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed}.");
    }

    /**
     * Stáhne rendered PDF od iDoklad a uloží do storage/invoices/supplier-{id}/.
     * Dedup přes SHA-256: pokud už existuje stejný soubor, jen reuse path.
     * Pro vydanou fakturu používáme separátní column imported_pdf_* aby se nepřepsal
     * náš vlastní rendered PDF (pdf_path).
     */
    private function archiveIssuedPdf(int $supplierId, int $invoiceId, int $idokladId, array $idoklad): void
    {
        $pdf = $this->idoklad->downloadIssuedPdf($supplierId, $idokladId);
        if ($pdf === null) return;

        $archiveRoot = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/invoices-imported'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices-imported');
        }
        $sha = hash('sha256', $pdf);
        $size = strlen($pdf);
        // Hash-shard layout (supplier-{id}/{2}/{16}.pdf) — sdílené s PurchaseInvoicePdfArchiver.
        $diskPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::ensureShardPath($archiveRoot, $supplierId, $sha);
        if (!is_file($diskPath)) {
            @file_put_contents($diskPath, $pdf);
        }
        $relPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $name = ($idoklad['DocumentNumber'] ?? 'invoice') . '.pdf';
        $this->db->pdo()->prepare(
            'UPDATE invoices SET imported_pdf_path = ?, imported_pdf_hash = ?,
                                  imported_pdf_size_bytes = ?, imported_pdf_original_name = ?
              WHERE id = ?'
        )->execute([$relPath, $sha, $size, $name, $invoiceId]);
    }

    /**
     * Stáhne první archivovatelnou přílohu dokladu (typically jedna od dodavatele).
     *
     * $documentType = iDoklad Attachments scope: 'ReceivedInvoice' pro přijaté faktury,
     * 'ReceivedReceipt' pro účtenky (SDK enum 5 / 11) — přílohy žijí v odděleném scope
     * per typ dokladu, dotaz se špatným scope vrací 404/nic.
     *
     * Fotka (JPG/PNG… z telefonu — u účtenek běžný případ) se konvertuje na PDF stejnou
     * cestou jako ruční upload (ImageToPdfConverter, issue #75), vč. přejmenování na .pdf.
     */
    private function archiveReceivedPdf(int $supplierId, int $purchaseInvoiceId, int $idokladInvoiceId, string $documentType = 'ReceivedInvoice'): void
    {
        $attachments = $this->idoklad->listReceivedAttachments($supplierId, $idokladInvoiceId, $documentType);
        $picked = self::pickArchivableAttachment($attachments);
        if ($picked === null) return;
        [$pdf, $name] = $picked;

        if (!str_starts_with($pdf, '%PDF')) {
            $mime = $this->imageToPdf->detectImageMime($pdf);
            if ($mime === null) {
                return; // ani PDF, ani podporovaný obrázek — nearchivujeme
            }
            try {
                $pdf = $this->imageToPdf->convert($pdf, $mime);
            } catch (\Throwable $e) {
                $this->logger->info('iDoklad attachment image→PDF conversion failed', ['purchase_invoice_id' => $purchaseInvoiceId, 'error' => $e->getMessage()]);
                return;
            }
            $name = (string) preg_replace('/\.[^.]+$/', '', $name) . '.pdf';
        }

        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $sha = hash('sha256', $pdf);
        $size = strlen($pdf);
        // Hash-shard layout (supplier-{id}/{2}/{16}.pdf) — sdílené s PurchaseInvoicePdfArchiver.
        $diskPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::ensureShardPath($archiveRoot, $supplierId, $sha);
        if (!is_file($diskPath)) {
            @file_put_contents($diskPath, $pdf);
        }
        $relPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $this->purchaseRepo->setPdfMetadata($purchaseInvoiceId, $supplierId, $relPath, $sha, $size, $name);
    }

    /**
     * Vybere z iDoklad příloh první archivovatelnou: preferuje PDF (podle přípony
     * `.pdf` NEBO magic `%PDF` — název může chybět), jinak první přílohu
     * s dekódovatelnými bajty (typicky fotka; o konverzi/odmítnutí rozhodne caller).
     *
     * Čistá funkce (bez IO) — viz IdokladAttachmentPickTest.
     *
     * @param list<array<string,mixed>> $attachments  [{FileName, FileBytes(base64)}, …]
     * @return array{0:string,1:string}|null  [raw bajty, jméno souboru] nebo null
     */
    public static function pickArchivableAttachment(array $attachments): ?array
    {
        $fallback = null;
        foreach ($attachments as $a) {
            $bytes = $a['FileBytes'] ?? null;
            if ($bytes === null || $bytes === '') continue;
            $raw = base64_decode((string) $bytes, true);
            if ($raw === false || $raw === '') continue;
            $name = (string) ($a['FileName'] ?? '');
            if (str_ends_with(strtolower($name), '.pdf') || str_starts_with($raw, '%PDF')) {
                return [$raw, $name !== '' ? $name : 'invoice.pdf'];
            }
            $fallback ??= [$raw, $name !== '' ? $name : 'attachment'];
        }
        return $fallback;
    }

    /**
     * Sestaví query pro incremental sync „od posledního importu".
     *
     * iDoklad v3 vyžaduje filtr ve tvaru `column~operator~value` (separátor `~`),
     * takže „změněno od data" je `DateLastChange~gte~YYYY-MM-DD`. Dřívější tvar
     * `DateLastChange>=…` API odmítalo s HTTP 400 „Incorrect filter format" a celý
     * incremental import (počínaje kontakty) spadl (#197).
     *
     * @return array<string,string>
     */
    public static function incrementalFilter(?string $since): array
    {
        return $since !== null ? ['filter' => "DateLastChange~gte~{$since}"] : [];
    }

    /**
     * Vybere hodnotu pro `varsymbol` importované vydané faktury / dobropisu.
     *
     * V našem modelu je `varsymbol` číslo dokladu s UNIQUE (supplier_id, varsymbol),
     * takže musí odpovídat unikátnímu číslu dokladu z iDokladu (`DocumentNumber`),
     * NE platebnímu `VariableSymbol` — ten se u paušálů/trvalých plateb opakuje a
     * kolidoval na `uq_inv_supplier_varsymbol` (#196). VariableSymbol drží jen jako
     * fallback pro případ, že by DocumentNumber chybělo.
     *
     * @param array<string,mixed> $i iDoklad doklad (v3 GET model)
     */
    public static function idokladDocNumber(array $i): string
    {
        $doc = trim((string) ($i['DocumentNumber'] ?? ''));
        if ($doc !== '') return $doc;
        return trim((string) ($i['VariableSymbol'] ?? ''));
    }

    /**
     * Bookmark — vrátí ISO date posledního úspěšného importu pro tento tenant.
     * Použito jako filter DateLastChange~gte~… pro incremental sync.
     */
    private function loadBookmark(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT idoklad_last_imported_at FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) return null;
        // ISO 8601 → iDoklad chce YYYY-MM-DD
        return substr((string) $val, 0, 10);
    }
}

/**
 * Marker exception pro graceful cancel — worker break loop a markCancelled.
 */
final class CancelledException extends \RuntimeException {}
