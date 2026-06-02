<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;

/**
 * Vygeneruje fakturu ze šablony pravidelné fakturace.
 *
 * Kroky:
 *   1. Vytvoří draft (klon šablony — client, project, currency, language,
 *      payment_method, reverse_charge, notes; položky se zkopírují s volitelnou
 *      synchronizací M/YYYY v popisu k DUZP/issue_date — viz MonthSynchronizer).
 *      tax_date_mode řídí DUZP — same_as_issue (default) nebo previous_month_last_day.
 *   2. Recompute totals (InvoiceCalculator).
 *   3. Aplikuje ČNB kurz, pokud měna != CZK (ExchangeRateApplier).
 *   4. Pokud auto_issue=true:
 *        - auto_send_email=true → AutoIssueAndSendService.run() (issue + render + send)
 *        - jinak → in-place issue (varsymbol + snapshots + status='issued')
 *   5. Posune next_run_date na šabloně (PeriodicityCalculator) a updatuje
 *      last_run_date; pokud nové next > end_date, status='expired'.
 *
 * Vrací sumář pro cron / RunNowAction.
 *
 * @phpstan-type Result array{
 *     invoice_id: int,
 *     varsymbol: ?string,
 *     issued: bool,
 *     sent_to: list<string>,
 *     new_next_run_date: ?string,
 *     template_status: string,
 * }
 */
final class RecurringInvoiceGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly RecurringTemplateRepository $templates,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly AutoIssueAndSendService $issueAndSend,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoicePdfRenderer $pdfRenderer,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{invoice_id:int, varsymbol:?string, issued:bool, sent_to:list<string>, new_next_run_date:?string, template_status:string}
     */
    public function generate(int $templateId, ?string $forcedIssueDate = null, ?int $userId = null, string $ip = '', string $ua = 'cron', bool $forceDraft = false): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }

        $issueDate = $forcedIssueDate ?? (string) $template['next_run_date'];

        // Cron volá s $userId=null — fallback na autora šablony, aby invoices.created_by
        // (NOT NULL) i activity_log měly konzistentní audit.
        $userId ??= (int) $template['created_by'];

        // Validate state — paused/expired by neměl cron volat, ale RunNow může
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        // Pre-flight check částky k úhradě — vyhodnotíme stejnou matematikou jako
        // recompute, ale BEZ DB zápisu. Tím se vyhneme orphan draftu, kdyby
        // generator spadl mezi insertem a delete-on-fail.
        $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => (string) ($template['invoice_type'] ?? 'invoice'),
            'advance_paid_amount' => 0,
            'reverse_charge' => !empty($template['reverse_charge']),
            'discount_percent' => (float) ($template['discount_percent'] ?? 0),
            'items' => $template['items'],
        ], $this->invoices->vatRateMap());
        if ($amountError !== null) {
            throw new \DomainException($amountError);
        }

        $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
        $this->calc->recompute($invoiceId);
        $this->rateApplier->applyToInvoice($invoiceId);

        // forceDraft = ruční „Vygenerovat koncept" — vždy nech draft (i u auto_issue=true),
        // uživatel ho pak vystaví/upraví ručně. Rozvrh posouváme stejně jako u běžné
        // generace, aby cron tutéž periodu nevygeneroval podruhé.
        if ($forceDraft) {
            $issued = false;
            $sentTo = [];
            $varsymbol = null;
        } else {
            ['issued' => $issued, 'sent_to' => $sentTo, 'varsymbol' => $varsymbol] =
                $this->performIssue($invoiceId, $template, $userId, $ip, $ua);
        }

        ['next' => $newNext, 'status' => $newStatus] =
            $this->advanceTemplateSchedule($templateId, $template, $issueDate);

        $this->logger->log('recurring.generated', $userId, 'recurring_template', $templateId, [
            'invoice_id'  => $invoiceId,
            'issue_date'  => $issueDate,
            'auto_issue'  => $template['auto_issue'],
            'auto_send'   => $template['auto_send_email'],
            'sent_to'     => $sentTo,
            'next_run'    => $newNext,
            'new_status'  => $newStatus,
        ], $ip, $ua);

        return [
            'invoice_id'        => $invoiceId,
            'varsymbol'         => $varsymbol,
            'issued'            => $issued,
            'sent_to'           => $sentTo,
            'new_next_run_date' => $newNext,
            'template_status'   => $newStatus,
        ];
    }

    /**
     * Otevře koncept pro AKTUÁLNÍ období (draft_open_mode='period_start') — vytvoří
     * draft s fixními řádky šablony, ale NEvystaví ho a NEposune next_run_date.
     * issue_date i tax_date se nastaví na PLÁNOVANÝ konec období (next_run_date),
     * takže koncept od začátku nese správné datum vystavení i DUZP. Uživatel pak
     * celý měsíc edituje výkaz práce na tomto konceptu; cron ho v issuePeriod()
     * v den next_run_date uzavře.
     *
     * Idempotentní: pokud už pro období existuje faktura (draft i vystavená),
     * vrátí ji bez vytvoření nové.
     *
     * @return array{invoice_id:int, created:bool}
     */
    public function openDraft(int $templateId, ?int $userId = null, string $ip = '', string $ua = 'cron'): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $issueDate = (string) $template['next_run_date'];
        $userId ??= (int) $template['created_by'];

        // Idempotence — koncept (ani vystavená faktura) pro toto období už nesmí existovat.
        $existing = $this->templates->findPeriodInvoice($templateId, $issueDate);
        if ($existing !== null) {
            return ['invoice_id' => (int) $existing['id'], 'created' => false];
        }

        $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => (string) ($template['invoice_type'] ?? 'invoice'),
            'advance_paid_amount' => 0,
            'reverse_charge' => !empty($template['reverse_charge']),
            'discount_percent' => (float) ($template['discount_percent'] ?? 0),
            'items' => $template['items'],
        ], $this->invoices->vatRateMap());
        if ($amountError !== null) {
            throw new \DomainException($amountError);
        }

        $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
        $this->calc->recompute($invoiceId);
        $this->rateApplier->applyToInvoice($invoiceId);

        $this->logger->log('recurring.draft_opened', $userId, 'recurring_template', $templateId, [
            'invoice_id' => $invoiceId,
            'issue_date' => $issueDate,
        ], $ip, $ua);

        return ['invoice_id' => $invoiceId, 'created' => true];
    }

    /**
     * Uzavře a vystaví koncept pro aktuální období (draft_open_mode='period_start').
     * Najde otevřený koncept (nebo ho vytvoří, kdyby openDraft neproběhl — např.
     * cron neběžel 1. dne), přepočítá totály (zachytí položky výkazu doplněné během
     * měsíce), vystaví a volitelně odešle, pak posune next_run_date.
     *
     * Vrací stejný tvar jako generate().
     *
     * @return array{invoice_id:int, varsymbol:?string, issued:bool, sent_to:list<string>, new_next_run_date:?string, template_status:string}
     */
    public function issuePeriod(int $templateId, ?int $userId = null, string $ip = '', string $ua = 'cron'): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $issueDate = (string) $template['next_run_date'];
        $userId ??= (int) $template['created_by'];

        $existing = $this->templates->findPeriodInvoice($templateId, $issueDate);

        $issued = false;
        $sentTo = [];
        $varsymbol = null;

        if ($existing !== null && (string) $existing['status'] !== 'draft') {
            // Už vystaveno (ručně nebo dřívějším během) — nic neděláme, jen posuneme rozvrh.
            $invoiceId = (int) $existing['id'];
            $varsymbol = $existing['varsymbol'] !== null ? (string) $existing['varsymbol'] : null;
            $issued = true;
        } else {
            if ($existing !== null) {
                // Otevřený koncept — přepočítej (zachytí vícepráce z výkazu) a vystav.
                $invoiceId = (int) $existing['id'];
            } else {
                // openDraft neproběhl — vytvoř fakturu teď (fallback / draft_open_mode přepnut pozdě).
                $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay([
                    'invoice_type' => (string) ($template['invoice_type'] ?? 'invoice'),
                    'advance_paid_amount' => 0,
                    'reverse_charge' => !empty($template['reverse_charge']),
                    'discount_percent' => (float) ($template['discount_percent'] ?? 0),
                    'items' => $template['items'],
                ], $this->invoices->vatRateMap());
                if ($amountError !== null) {
                    throw new \DomainException($amountError);
                }
                $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
            }
            $this->calc->recompute($invoiceId);
            $this->rateApplier->applyToInvoice($invoiceId);

            ['issued' => $issued, 'sent_to' => $sentTo, 'varsymbol' => $varsymbol] =
                $this->performIssue($invoiceId, $template, $userId, $ip, $ua);
        }

        ['next' => $newNext, 'status' => $newStatus] =
            $this->advanceTemplateSchedule($templateId, $template, $issueDate);

        $this->logger->log('recurring.generated', $userId, 'recurring_template', $templateId, [
            'invoice_id'  => $invoiceId,
            'issue_date'  => $issueDate,
            'auto_issue'  => $template['auto_issue'],
            'auto_send'   => $template['auto_send_email'],
            'sent_to'     => $sentTo,
            'next_run'    => $newNext,
            'new_status'  => $newStatus,
            'from_opened_draft' => $existing !== null,
        ], $ip, $ua);

        return [
            'invoice_id'        => $invoiceId,
            'varsymbol'         => $varsymbol,
            'issued'            => $issued,
            'sent_to'           => $sentTo,
            'new_next_run_date' => $newNext,
            'template_status'   => $newStatus,
        ];
    }

    /**
     * První den měsíce, ve kterém leží next_run_date — datum, kdy se pro
     * draft_open_mode='period_start' otevírá koncept (začátek fakturovaného období).
     */
    public static function draftOpenDate(string $nextRunDate): string
    {
        return (new \DateTimeImmutable($nextRunDate))
            ->modify('first day of this month')
            ->format('Y-m-d');
    }

    /**
     * Vystaví fakturu dle per-šablona flagů auto_issue / auto_send_email.
     *
     * @return array{issued:bool, sent_to:list<string>, varsymbol:?string}
     */
    private function performIssue(int $invoiceId, array $template, ?int $userId, string $ip, string $ua): array
    {
        if (!$template['auto_issue']) {
            return ['issued' => false, 'sent_to' => [], 'varsymbol' => null];
        }
        if ($template['auto_send_email']) {
            $r = $this->issueAndSend->run($invoiceId, $userId, $ip, $ua);
            return ['issued' => $r['issued'], 'sent_to' => $r['sent_to'], 'varsymbol' => $r['varsymbol']];
        }
        $varsymbol = $this->issueOnlyWithoutSend($invoiceId, $userId, $ip, $ua);
        return ['issued' => true, 'sent_to' => [], 'varsymbol' => $varsymbol];
    }

    /**
     * Posune next_run_date o jeden cyklus + případně expiruje. Vrací nové hodnoty.
     *
     * @return array{next:string, status:string}
     */
    private function advanceTemplateSchedule(int $templateId, array $template, string $issueDate): array
    {
        $newNext = PeriodicityCalculator::nextRunDate(
            $issueDate,
            (string) $template['frequency'],
            (bool) $template['end_of_month'],
            $template['day_of_month'] !== null ? (int) $template['day_of_month'] : null,
        );

        $newStatus = (string) $template['status'];
        if (!empty($template['end_date']) && $newNext > (string) $template['end_date']) {
            $newStatus = 'expired';
        }

        $this->templates->advanceSchedule($templateId, $newNext, $issueDate, $newStatus);

        return ['next' => $newNext, 'status' => $newStatus];
    }

    /**
     * Insert draft + items. Zachovává payment_method, reverse_charge, language,
     * notes a item description s případným month-increment.
     */
    private function createInvoiceFromTemplate(array $template, string $issueDate, ?int $userId): int
    {
        $pdo = $this->db->pdo();

        $type = (string) ($template['invoice_type'] ?? 'invoice');
        $dueDate = date('Y-m-d', strtotime($issueDate . ' +' . (int) $template['payment_due_days'] . ' days'));
        $taxDate = $type === 'proforma'
            ? null
            : self::computeTaxDate($issueDate, (string) ($template['tax_date_mode'] ?? 'same_as_issue'));

        // Neplátce DPH → položky se přepnou na 0% osvobozenou sazbu (stejně jako u ručně
        // vystavené faktury, viz InvoiceEditor.defaultVatRateId). Autoritativní záchrana i
        // pro šablony uložené dřív s nominální sazbou (21 %) — bez toho by cron tiše vystavil
        // fakturu s DPH u neplátce. Děje se PŘED guardem, aby se validovala coercnutá sazba.
        $template['items'] = $this->coerceNonVatPayerRates(
            $pdo,
            (int) $template['supplier_id'],
            $template['items'],
            $taxDate ?? $issueDate,
        );

        // Safeguard: přišpendlené sazby šablony musí být platné k DUZP (u proformy k issue).
        // Brání tichému vystavení se starou sazbou po její změně (nový řádek vat_rates).
        VatRateValidityGuard::assertValidOn(
            $pdo,
            array_map(static fn ($it) => (int) $it['vat_rate_id'], $template['items']),
            $taxDate ?? $issueDate,
        );

        $pdo->beginTransaction();
        try {
            $discountPercent = round(max(0.0, min(100.0, (float) ($template['discount_percent'] ?? 0))), 2);
            // Výchozí kategorie tržby — default zakázky > klienta (sdílený helper,
            // stejná logika jako createDraft a import). Faktura ze šablony tak dostane
            // kategorii stejně jako ručně založená.
            $projectId = !empty($template['project_id']) ? (int) $template['project_id'] : null;
            $revenueCategoryId = InvoiceRepository::resolveDefaultRevenueCategoryId(
                $pdo, (int) $template['client_id'], $projectId
            );
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, client_id, project_id, supplier_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, note_below_items, payment_method, discount_percent,
                    recurring_template_id, revenue_category_id, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $type,
                (int) $template['client_id'],
                $projectId,
                (int) $template['supplier_id'],
                $issueDate,
                $taxDate,
                $dueDate,
                (int) $template['currency_id'],
                $template['reverse_charge'] ? 1 : 0,
                !empty($template['prices_include_vat']) ? 1 : 0,
                (string) ($template['language'] ?? 'cs'),
                $template['note_above_items'] ?? null,
                $template['note_below_items'] ?? null,
                (string) ($template['payment_method'] ?? 'bank_transfer'),
                $discountPercent,
                (int) $template['id'],
                $revenueCategoryId,
                $userId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Description sync — M/YYYY v popisu se synchronizuje k DUZP (tax_date)
            // pokud existuje, jinak k issue_date (proforma). Flag increment_month_in_descriptions
            // řídí, jestli se sync vůbec aplikuje (legacy název zachován pro DB compat).
            $syncTarget = $template['increment_month_in_descriptions']
                ? new \DateTimeImmutable($taxDate ?? $issueDate)
                : null;

            // Položky vkládáme přes kanonickou InvoiceRepository::replaceItems — ta nastaví
            // SPRÁVNÝ vat_rate_snapshot (z aktuální vat_rates), vat_classification_code
            // i zmaterializuje slevovou položku z header discount_percent. Tím se recurring
            // chová stejně jako běžná faktura (dřív se vkládal snapshot=0 → DPH vycházela 0
            // a kódy byly NULL).
            $items = [];
            foreach ($template['items'] as $item) {
                $description = $syncTarget !== null
                    ? MonthSynchronizer::syncTo((string) $item['description'], $syncTarget)
                    : (string) $item['description'];
                $items[] = [
                    'description'            => $description,
                    'quantity'               => (float) $item['quantity'],
                    'unit'                   => (string) $item['unit'],
                    'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                    'vat_rate_id'            => (int) $item['vat_rate_id'],
                    'order_index'            => (int) $item['order_index'],
                ];
            }
            $this->invoices->replaceItems($newId, $items);

            $pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Neplátce DPH fakturuje bez DPH → všechny položky se přepnou na 0% osvobozenou sazbu
     * (rate_percent=0, !is_reverse_charge) platnou k datu plnění. Zrcadlí chování ruční
     * faktury (InvoiceEditor.defaultVatRateId), kde frontend vybere 0% sazbu; tady to dělá
     * autoritativně backend, takže to ochrání i šablony uložené dřív s nominální sazbou.
     * Plátce (nebo nenalezený supplier) zůstává beze změny.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function coerceNonVatPayerRates(\PDO $pdo, int $supplierId, array $items, string $referenceDate): array
    {
        $stmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $isVatPayer = $stmt->fetchColumn();
        // false = supplier nenalezen → nech beze změny; 1 = plátce → nic neřeš.
        if ($isVatPayer === false || (int) $isVatPayer === 1) {
            return $items;
        }

        $rate = $pdo->prepare(
            'SELECT id FROM vat_rates
              WHERE rate_percent = 0 AND is_reverse_charge = 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1'
        );
        $rate->execute([$referenceDate, $referenceDate]);
        $zeroId = $rate->fetchColumn();
        if ($zeroId === false) {
            // Žádná platná 0% sazba k datu — nech sazby být, guard případně chybu nahlásí.
            return $items;
        }

        foreach ($items as &$item) {
            $item['vat_rate_id'] = (int) $zeroId;
        }
        unset($item);

        return $items;
    }

    /**
     * Issue draft bez odeslání — vystaví VS + snapshoty + status='issued',
     * invaliduje cached PDF, recompute stats. Vrací varsymbol.
     */
    private function issueOnlyWithoutSend(int $invoiceId, ?int $userId, string $ip, string $ua): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found after generation");
        }

        $supplierId = (int) $invoice['supplier_id'];
        $issueDate = new \DateTimeImmutable((string) $invoice['issue_date']);
        $varsymbol = $this->varsymbol->next($supplierId, (string) $invoice['invoice_type'], $issueDate, (int) $invoice['client_id']);
        $snaps = $this->snapshots->build(
            (int) $invoice['client_id'],
            (int) $invoice['currency_id'],
            $supplierId,
        );

        $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = "issued"
             WHERE id = ? AND status = "draft"'
        )->execute([
            $varsymbol,
            json_encode($snaps['client'], JSON_UNESCAPED_UNICODE),
            json_encode($snaps['supplier'], JSON_UNESCAPED_UNICODE),
            $snaps['bank'] !== null ? json_encode($snaps['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);

        $this->stats->recomputeForInvoiceId($invoiceId);
        $this->pdfRenderer->invalidate($invoiceId, 'invalidate_recurring_issue');

        $this->logger->log('invoice.issued', $userId, 'invoice', $invoiceId, [
            'varsymbol'   => $varsymbol,
            'auto_reason' => 'recurring_template',
        ], $ip, $ua);

        return $varsymbol;
    }

    /**
     * Spočítá DUZP (tax_date) podle režimu šablony.
     *
     *   same_as_issue           → tax_date = issue_date (původní chování)
     *   previous_month_last_day → tax_date = poslední den měsíce předcházejícího issue_date
     *                             (typický CZ scénář "fakturuji 1.6. za květen, DUZP 31.5.")
     */
    private static function computeTaxDate(string $issueDate, string $mode): string
    {
        $d = new \DateTimeImmutable($issueDate);
        return match ($mode) {
            'previous_month_last_day' => $d->modify('first day of this month')
                                           ->modify('-1 day')
                                           ->format('Y-m-d'),
            default => $issueDate, // 'same_as_issue' a unknown mode (fail-safe)
        };
    }
}
