<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use Psr\Log\LoggerInterface;

/**
 * Fakturoid import orchestrátor — paralel s IdokladImportService.
 *
 * Stahuje:
 *   - Subjects (klienti/dodavatelé) → clients
 *   - Invoices                       → invoices
 *   - Expenses                       → purchase_invoices
 *
 * Dedup přes (supplier_id, fakturoid_id). Lifecycle stejný jako iDoklad
 * (markRunning → progress → markCompleted/Failed/Cancelled).
 *
 * Fakturoid pole rozdílná od iDoklad:
 *   - Subject má `registration_no` (IČO) + `vat_no` (DIČ)
 *   - Invoice má `subject_id` (foreign key) + `lines` (items array)
 *   - Lines: { name, quantity, unit_name, unit_price, vat_rate }
 *   - Subject type: "customer" | "supplier" | "both" → role mapping
 *
 * Platební stav (#121): doklady se zakládají jako draft, ale `status` z Fakturoidu
 * 'paid'/'cancelled' se promítne hned při importu (paid_at = `paid_on`) — viz
 * ImportedPaymentStateMapper. Ostatní vydané doklady se vystaví (issued) bez
 * automatických upomínek (#250).
 */
final class FakturoidImportService
{
    private const PROGRESS_FLUSH_EVERY = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly FakturoidClient $fakturoid,
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
        private readonly ExchangeRateApplier $exchangeRateApplier,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->loadJob($jobId);
        if (!$this->jobs->markRunning($jobId)) return;

        try {
            $params = $job['params'] ?? [];
            $supplierId = (int) $job['supplier_id'];
            $userId = (int) $job['created_by'];
            $dryRun = !empty($params['dry_run']);
            $incremental = !empty($params['incremental']);
            $downloadAttachments = !empty($params['download_attachments']);
            $bookmarkSince = $incremental ? $this->loadBookmark($supplierId) : null;

            $msg = 'Fakturoid import zahájen' . ($dryRun ? ' (dry-run)' : '');
            if ($incremental && $bookmarkSince !== null) $msg .= ', incremental od ' . $bookmarkSince;
            if ($downloadAttachments) $msg .= ', s přílohami';
            $this->jobs->appendLog($jobId, $msg . '.');

            if (!empty($params['include_clients']) || ($params['include_clients'] ?? null) === null) {
                $this->importSubjects($jobId, $supplierId, $userId, $dryRun, $bookmarkSince);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_issued']) || ($params['include_issued'] ?? null) === null) {
                $this->importInvoices($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
                $this->checkCancel($jobId);
            }
            if (!empty($params['include_received']) || ($params['include_received'] ?? null) === null) {
                $this->importExpenses($jobId, $supplierId, $userId, $dryRun, $bookmarkSince, $downloadAttachments);
            }

            $this->jobs->appendLog($jobId, 'Fakturoid import dokončen.');
            $this->jobs->markCompleted($jobId);
            $this->db->pdo()->prepare(
                'UPDATE supplier SET fakturoid_last_imported_at = NOW() WHERE id = ?'
            )->execute([$supplierId]);
        } catch (CancelledException $e) {
            $this->jobs->appendLog($jobId, 'Fakturoid import zrušen uživatelem.');
            $this->jobs->markCancelled($jobId);
        } catch (\Throwable $e) {
            $this->logger->error('Fakturoid import failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->jobs->appendLog($jobId, 'FAIL: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    private function loadJob(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException("Import job #{$jobId} nenalezen.");
        if (!empty($row['params'])) $row['params'] = json_decode((string) $row['params'], true);
        return $row;
    }

    private function checkCancel(int $jobId): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            throw new CancelledException();
        }
    }

    private function importSubjects(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing subjects (clients/vendors)…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji subjekty z Fakturoid…');

        $query = $bookmarkSince !== null ? ['updated_since' => $bookmarkSince] : [];
        $created = 0; $skipped = 0; $processed = 0;

        foreach ($this->fakturoid->getAll($supplierId, 'subjects.json', $query) as $subj) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
                $this->checkCancel($jobId);
            }

            $fakturoidId = (int) ($subj['id'] ?? 0);
            if ($fakturoidId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $fakturoidId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                // Type: "customer" | "supplier" | "both"
                $type = (string) ($subj['type'] ?? 'customer');
                $isCustomer = $type === 'customer' || $type === 'both';
                $isVendor   = $type === 'supplier' || $type === 'both';
                if (!$isCustomer && !$isVendor) $isCustomer = true; // fallback

                $data = [
                    'company_name' => (string) ($subj['name'] ?? 'Fakturoid import'),
                    'ic'           => (string) ($subj['registration_no'] ?? '') ?: null,
                    'dic'          => (string) ($subj['vat_no'] ?? '') ?: null,
                    'street'       => (string) ($subj['street'] ?? '—'),
                    'city'         => (string) ($subj['city'] ?? '—'),
                    'zip'          => (string) ($subj['zip'] ?? '00000'),
                    'country_iso2' => strtoupper((string) ($subj['country'] ?? 'CZ')),
                    'main_email'   => (string) ($subj['email'] ?? '') ?: null,
                    'phone'        => (string) ($subj['phone'] ?? '') ?: null,
                    'language'     => 'cs',
                    'is_customer'  => $isCustomer,
                    'is_vendor'    => $isVendor,
                ];
                $clientId = $this->clients->create($data, $supplierId);
                $this->db->pdo()->prepare(
                    'UPDATE clients SET fakturoid_id = ? WHERE id = ?'
                )->execute([$fakturoidId, $clientId]);
                $created++;
            } catch (\Throwable $e) {
                $this->jobs->appendLog($jobId, "Subject {$fakturoidId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped]);
        $this->jobs->appendLog($jobId, "Subjekty: vytvořeno {$created}, přeskočeno {$skipped} (z {$processed}).");
    }

    private function importInvoices(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing issued invoices…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji vydané faktury z Fakturoid…');

        $query = $bookmarkSince !== null ? ['updated_since' => $bookmarkSince] : [];
        $created = 0; $skipped = 0; $failed = 0; $processed = 0;

        foreach ($this->fakturoid->getAll($supplierId, 'invoices.json', $query) as $inv) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $fakturoidId = (int) ($inv['id'] ?? 0);
            if ($fakturoidId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $fakturoidId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $invoiceId = $this->createIssued($inv, $supplierId, $userId);
                $this->db->pdo()->prepare('UPDATE invoices SET fakturoid_id = ? WHERE id = ?')->execute([$fakturoidId, $invoiceId]);
                $computed = $this->invCalc->recompute($invoiceId);
                // #258: zaokrouhlení celkové částky z Fakturoidu, jinak by klientova
                // zaokrouhlená úhrada vyšla jako přeplatek / částečná úhrada.
                $rounding = ImportedInvoiceRounding::fromFakturoid($inv, (float) $computed['totals']['with_vat']);
                if ($rounding !== 0.0) {
                    $this->invoices->setRounding($invoiceId, $rounding);
                }
                if ($downloadAttachments) {
                    $this->archiveIssuedPdf($supplierId, $invoiceId, $fakturoidId, $inv);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Faktura {$fakturoidId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Vydané faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    private function createIssued(array $i, int $supplierId, int $userId): int
    {
        $subjId = (int) ($i['subject_id'] ?? 0);
        $clientId = $this->resolveClient($subjId, $supplierId);
        if ($clientId === null) {
            throw new \RuntimeException("Klient (subject_id {$subjId}) nenalezen — naimportuj subjekty.");
        }

        // Fakturoid kind: "invoice" | "proforma" | "correction" | …
        $kind = (string) ($i['document_type'] ?? $i['kind'] ?? 'invoice');
        $invoiceType = match ($kind) {
            'proforma'   => 'proforma',
            'correction' => 'credit_note',
            default      => 'invoice',
        };

        $payload = [
            'invoice_type'   => $invoiceType,
            'client_id'      => $clientId,
            'issue_date'     => (string) ($i['issued_on'] ?? date('Y-m-d')),
            'tax_date'       => $invoiceType === 'proforma' ? null : (string) ($i['taxable_fulfillment_due'] ?? $i['issued_on'] ?? date('Y-m-d')),
            'due_date'       => (string) ($i['due_on'] ?? $i['issued_on'] ?? date('Y-m-d')),
            'currency_id'    => $this->resolveCurrencyId((string) ($i['currency'] ?? 'CZK'), $supplierId, isActive: true),
            'reverse_charge' => !empty($i['transferred_tax_liability']),
            'language'       => 'cs',
            'varsymbol'      => $this->uniqueVarsymbol((string) ($i['variable_symbol'] ?? $i['number'] ?? ''), $supplierId),
            'payment_method' => 'bank_transfer',
        ];
        $invoiceId = $this->invoices->createDraft($payload, $userId);

        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 21.0) ?? $this->matchVatRateId($vatRates, 0.0) ?? 0;
        $items = [];
        foreach (($i['lines'] ?? []) as $idx => $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            $items[] = [
                'description'            => (string) ($line['name'] ?? ''),
                'quantity'               => (float) ($line['quantity'] ?? 1),
                'unit'                   => (string) ($line['unit_name'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['unit_price'] ?? 0),
                'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId,
                'order_index'            => $idx,
            ];
        }
        if (!empty($items)) $this->invoices->replaceItems($invoiceId, $items);

        // #238: přenes měnový kurz. Fakturoid vrací pole `exchange_rate` (kurz, jímž
        // byla faktura vystavena) — má přednost před ČNB. Když chybí, dopočti z ČNB
        // k DUZP (ensureRate plní jen non-CZK doklad s NULL kurzem, nikdy nepřepíše).
        // Bez tohoto by VatLedgerService použil náhradní kurz 1.0 → EUR základ by se
        // do DPH/SH vykázal jako CZK.
        $srcRate = isset($i['exchange_rate']) ? (float) $i['exchange_rate'] : 0.0;
        if ($srcRate > 0 && strtoupper((string) ($i['currency'] ?? 'CZK')) !== 'CZK') {
            $this->invoices->setExchangeRate(
                $invoiceId,
                $srcRate,
                (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
            );
        } else {
            $this->exchangeRateApplier->ensureRate($invoiceId);
        }

        // #121: promítni platební stav z Fakturoidu — zaplacené/stornované doklady
        // nesmí zůstat viset jako nezaplacené pohledávky (a chytat upomínky).
        $this->applyIssuedPaymentState(
            $invoiceId,
            $clientId,
            (int) $payload['currency_id'],
            $supplierId,
            ImportedPaymentStateMapper::fromFakturoid($i),
            (string) ($payload['tax_date'] ?? '') ?: (string) $payload['issue_date'],
            (string) $payload['issue_date'],
        );
        return $invoiceId;
    }

    /**
     * Aplikuje namapovaný platební stav na čerstvě importovanou vydanou fakturu
     * (issue #121, #250). Jen pro doklady ve stavu 'draft' (guard v WHERE) — existující
     * doklady, které už uživatel zpracoval, se nemění.
     *
     * Otevřený doklad (state null: open/sent/overdue/uncollectible, vč. částečných
     * úhrad) se importuje jako 'issued' (#250) — ve Fakturoidu už je vystavený.
     * sent_at zůstává NULL a auto_send_reminders = 0, aby historické pohledávky
     * nespustily hromadné upomínky (#121). Doklad bez čísla zůstává draft.
     *
     * Doklad opouští 'draft', proto dostává snapshoty (client/supplier/bank)
     * stejně jako file import (InvoiceImportService) a IssueInvoiceAction —
     * vystavené doklady musí mít zafixované údaje. sent_at = issue_date 12:00
     * (stejná aproximace jako file import). Storno bez mirror `cancellation`
     * záznamu — originál byl stornován už ve zdrojovém systému, interní storno
     * doklad by tu byl jen šum.
     *
     * @param ?array{status:string, paid_at:?string} $state  null = otevřený doklad
     */
    private function applyIssuedPaymentState(int $invoiceId, int $clientId, int $currencyId, int $supplierId, ?array $state, string $fallbackPaidAt, string $issueDate): void
    {
        $snapshots = $this->snapshots->build($clientId, $currencyId, $supplierId);

        $snapshotSql = 'client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ?';
        $snapshotParams = [
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
        ];

        if ($state === null) {
            $this->db->pdo()->prepare(
                "UPDATE invoices SET status = 'issued', auto_send_reminders = 0, {$snapshotSql}
                  WHERE id = ? AND status = 'draft' AND varsymbol IS NOT NULL AND varsymbol <> ''"
            )->execute(array_merge($snapshotParams, [$invoiceId]));
        } elseif ($state['status'] === 'paid') {
            $this->db->pdo()->prepare(
                "UPDATE invoices SET status = 'paid', paid_at = ?, sent_at = ?, {$snapshotSql}
                  WHERE id = ? AND status = 'draft'"
            )->execute(array_merge(
                [$state['paid_at'] ?? $fallbackPaidAt, $issueDate . ' 12:00:00'],
                $snapshotParams,
                [$invoiceId],
            ));
        } elseif ($state['status'] === 'cancelled') {
            $this->db->pdo()->prepare(
                "UPDATE invoices SET status = 'cancelled', cancelled_at = NOW(), {$snapshotSql}
                  WHERE id = ? AND status = 'draft'"
            )->execute(array_merge($snapshotParams, [$invoiceId]));
        }
    }

    /**
     * Aplikuje 'paid' na čerstvě importovanou přijatou fakturu (issue #121).
     * Guard na status='draft' — createExpense může přes dedup guard vrátit
     * existující (už zpracovaný) doklad, ten nepřepisujeme.
     */
    private function applyPurchasePaymentState(int $purchaseId, int $supplierId, ?array $state, string $fallbackPaidAt): void
    {
        if ($state === null || $state['status'] !== 'paid') return;
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        )->execute([$state['paid_at'] ?? $fallbackPaidAt, $purchaseId, $supplierId]);
    }

    private function importExpenses(int $jobId, int $supplierId, int $userId, bool $dryRun, ?string $bookmarkSince, bool $downloadAttachments = false): void
    {
        $this->jobs->updateProgress($jobId, ['current_step' => 'Importing expenses (received invoices)…', 'processed' => 0]);
        $this->jobs->appendLog($jobId, 'Stahuji přijaté (expenses) z Fakturoid…');

        $query = $bookmarkSince !== null ? ['updated_since' => $bookmarkSince] : [];
        $created = 0; $skipped = 0; $failed = 0; $processed = 0;

        foreach ($this->fakturoid->getAll($supplierId, 'expenses.json', $query) as $exp) {
            $processed++;
            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
                $this->checkCancel($jobId);
            }

            $fakturoidId = (int) ($exp['id'] ?? 0);
            if ($fakturoidId === 0) continue;

            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $fakturoidId]);
            if ($stmt->fetchColumn() !== false) { $skipped++; continue; }

            if ($dryRun) { $created++; continue; }

            try {
                $purchaseId = $this->createExpense($exp, $supplierId, $userId);
                $this->db->pdo()->prepare('UPDATE purchase_invoices SET fakturoid_id = ? WHERE id = ?')->execute([$fakturoidId, $purchaseId]);
                $this->purCalc->recompute($purchaseId);
                if ($downloadAttachments) {
                    $this->archiveExpensePdf($supplierId, $purchaseId, $exp);
                }
                $created++;
            } catch (\Throwable $e) {
                $failed++;
                $this->jobs->appendLog($jobId, "Expense {$fakturoidId}: " . $e->getMessage());
            }
        }
        $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created, 'skipped_count' => $skipped, 'failed_count' => $failed]);
        $this->jobs->appendLog($jobId, "Přijaté faktury: vytvořeno {$created}, přeskočeno {$skipped}, chyby {$failed} (z {$processed}).");
    }

    private function createExpense(array $e, int $supplierId, int $userId): int
    {
        $subjId = (int) ($e['subject_id'] ?? 0);
        $vendorId = $this->resolveClient($subjId, $supplierId);
        if ($vendorId === null) {
            throw new \RuntimeException("Dodavatel (subject_id {$subjId}) nenalezen — naimportuj subjekty.");
        }
        $this->clients->markAsVendor($vendorId);

        $issueDate = (string) ($e['issued_on'] ?? date('Y-m-d'));
        $taxDate   = (string) ($e['taxable_fulfillment_due'] ?? $issueDate);
        $dueDate   = (string) ($e['due_on'] ?? $issueDate);

        $vatRates = $this->loadVatRateMap();

        $items = [];
        foreach (($e['lines'] ?? []) as $idx => $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            // Nenamapovanou sazbu doklad ODMÍTNE, nefallbackuje na tuzemských 21 %.
            // Fallback tady dřív z německých 19 % udělal českou základní sazbu, takže se
            // cizí daň dostala na ř. 40 + KH B.2 jako nárok na odpočet (audit VAT
            // klasifikací, C-3a). Import doklad zaznamená jako chybný s touhle hláškou
            // v logu úlohy a pokračuje dalšími — ostatní doklady tím netrpí.
            $vatRateId = $this->matchVatRateId($vatRates, $rate);
            if ($vatRateId === null) {
                throw new \RuntimeException(sprintf(
                    'Položka č. %d: sazba DPH %s %% není v číselníku — cizí sazbu nelze '
                    . 'nahradit tuzemskou, doplňte ji do číselníku sazeb a import zopakujte.',
                    $idx + 1,
                    rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ','),
                ));
            }
            $items[] = [
                'description'            => (string) ($line['name'] ?? ''),
                'quantity'               => (float) ($line['quantity'] ?? 1),
                'unit'                   => (string) ($line['unit_name'] ?? 'ks'),
                'unit_price_without_vat' => (float) ($line['unit_price'] ?? 0),
                'vat_rate_id'            => $vatRateId,
                'order_index'            => $idx,
            ];
        }

        $payload = [
            'vendor_id'             => $vendorId,
            // #113: original_number = číslo dokladu dodavatele; number je jen interní číslo
            // přidělené Fakturoidem — to použij jen jako fallback, když original_number chybí.
            'vendor_invoice_number' => $this->sanitizeVendorNumber(
                trim((string) ($e['original_number'] ?? '')) !== ''
                    ? (string) $e['original_number']
                    : (string) ($e['number'] ?? '')
            ),
            'document_kind'         => 'invoice',
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId((string) ($e['currency'] ?? 'CZK'), $supplierId, isActive: false),
            'exchange_rate'         => isset($e['exchange_rate']) ? (float) $e['exchange_rate'] : null,
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => !empty($e['transferred_tax_liability']),
            'language'              => 'cs',
            'items'                 => $items,
        ];
        // Dedup guard — re-import stejné faktury z Fakturoidu (typicky opakovaný pull)
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
        if (!empty($items)) $this->purchaseRepo->replaceItems($id, $items);
        // Auto-ČNB kurz pro non-CZK fakturu pokud Fakturoid neobsahoval explicitní kurz
        $this->cnbApplier->applyIfMissing(
            $id,
            $supplierId,
            (string) ($e['currency'] ?? 'CZK'),
            (string) ($payload['tax_date'] ?? $payload['issue_date'] ?? ''),
            $payload['exchange_rate'] ?? null,
        );
        // #121: Fakturoid eviduje výdaj jako zaplacený → promítni (jen na čerstvě
        // vytvořený doklad; dedup-vrácený existující doklad výše se nemění).
        $this->applyPurchasePaymentState(
            $id,
            $supplierId,
            ImportedPaymentStateMapper::fromFakturoid($e),
            $taxDate ?: $issueDate,
        );
        return $id;
    }

    // ── Helpers (shared s IdokladImportService logikou, jiná key names) ──

    private function resolveClient(int $fakturoidSubjectId, int $supplierId): ?int
    {
        if ($fakturoidSubjectId === 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND fakturoid_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $fakturoidSubjectId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

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

    private function loadVatRateMap(): array
    {
        // Bereme VŠECHNY sazby bez ohledu na valid_from/valid_to — importujeme
        // historické doklady (2019+), kde platí dobové sazby (CZ-15 %, CZ-10 %,
        // valid_to 2023-12-31, viz migrace 0049). Filtr k dnešku by je vyřadil →
        // matchVatRateId by vrátil null → vat_rate_id=0 → fk_ii_vat violation.
        // Konkrétní % se snapshotuje do invoice_items.vat_rate_snapshot, takže
        // výpočty/výkazy zůstávají korektní.
        $rows = $this->db->pdo()->query(
            'SELECT id, rate_percent FROM vat_rates'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[(int) $r['id']] = (float) $r['rate_percent'];
        return $map;
    }

    private function matchVatRateId(array $vatRates, float $rate): ?int
    {
        foreach ($vatRates as $id => $r) if (abs($r - $rate) < 0.01) return $id;
        return null;
    }

    private function sanitizeVarsymbol(string $vs): string
    {
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '', $vs) ?? '';
        if ($vs === '') return 'FAKT-' . substr((string) random_int(1000, 9999), 0, 4);
        return substr($vs, 0, 20);
    }

    /**
     * Zajistí unikátnost varsymbolu vůči invoices(supplier_id, varsymbol).
     * Fakturoid běžně sdílí variabilní symbol mezi proformou a ostrou fakturou
     * (resp. dobropisem) → naše UNIQUE (uq_inv_supplier_varsymbol) by hodil
     * 1062 duplicate. Při kolizi disambiguujeme suffixem -N (ořez na 20 znaků
     * dle DB sloupce). Jako poslední záchrana null (UNIQUE povoluje více NULL).
     */
    private function uniqueVarsymbol(string $raw, int $supplierId): ?string
    {
        $base = $this->sanitizeVarsymbol($raw);
        if (!$this->varsymbolTaken($base, $supplierId)) return $base;
        for ($n = 2; $n <= 99; $n++) {
            $suffix = '-' . $n;
            $candidate = substr($base, 0, 20 - strlen($suffix)) . $suffix;
            if (!$this->varsymbolTaken($candidate, $supplierId)) return $candidate;
        }
        return null;
    }

    private function varsymbolTaken(string $vs, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $vs]);
        return $stmt->fetchColumn() !== false;
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'FAKT-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }

    /**
     * Stáhne Fakturoidem rendered PDF vydané faktury a uloží do imported_pdf_*
     * (paralelně s naším renderem pdf_path). Dedup přes SHA-256. Symetrické k iDokladu.
     */
    private function archiveIssuedPdf(int $supplierId, int $invoiceId, int $fakturoidId, array $inv): void
    {
        $pdf = $this->fakturoid->downloadInvoicePdf($supplierId, $fakturoidId);
        if ($pdf === null) return; // 204 = PDF se ještě generuje

        $archiveRoot = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/invoices-imported'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices-imported');
        }
        $sha = hash('sha256', $pdf);
        // Hash-shard layout (supplier-{id}/{2}/{16}.pdf) — sdílené s PurchaseInvoicePdfArchiver.
        $diskPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::ensureShardPath($archiveRoot, $supplierId, $sha);
        if (!is_file($diskPath)) @file_put_contents($diskPath, $pdf);

        $relPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $name = ((string) ($inv['number'] ?? 'invoice')) . '.pdf';
        $this->db->pdo()->prepare(
            'UPDATE invoices SET imported_pdf_path = ?, imported_pdf_hash = ?,
                                  imported_pdf_size_bytes = ?, imported_pdf_original_name = ?
              WHERE id = ?'
        )->execute([$relPath, $sha, strlen($pdf), $name, $invoiceId]);
    }

    /**
     * Stáhne přílohu výdaje (originální doklad od dodavatele) z Fakturoid a uloží
     * jako PDF přijaté faktury. Symetrické k iDokladu. Viz issue #261 —
     * Fakturoid vrací přílohy v poli `attachments`, ne ve skalárním `attachment`.
     */
    private function archiveExpensePdf(int $supplierId, int $purchaseInvoiceId, array $exp): void
    {
        $attachment = FakturoidExpenseAttachment::resolve($exp);
        if ($attachment === null) return; // výdaj bez přílohy

        $pdf = $this->fakturoid->downloadAttachment($supplierId, $attachment['download_url']);
        if ($pdf === null) return;

        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $uploads = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $uploads !== '' ? dirname($uploads) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $sha = hash('sha256', $pdf);
        // Hash-shard layout (supplier-{id}/{2}/{16}.pdf) — sdílené s PurchaseInvoicePdfArchiver.
        $diskPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::ensureShardPath($archiveRoot, $supplierId, $sha);
        if (!is_file($diskPath)) @file_put_contents($diskPath, $pdf);

        $relPath = \MyInvoice\Service\Import\PurchaseInvoicePdfArchiver::shardedRelPath($supplierId, $sha);
        $this->purchaseRepo->setPdfMetadata($purchaseInvoiceId, $supplierId, $relPath, $sha, strlen($pdf), $attachment['filename']);
    }

    private function loadBookmark(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT fakturoid_last_imported_at FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) return null;
        // Fakturoid `updated_since` chce ISO 8601 (s timezone)
        return date('c', strtotime((string) $val));
    }
}
