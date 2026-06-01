<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use ZipArchive;

/**
 * Orchestrace importu vystavených faktur z Pohoda XML / ISDOC (single nebo ZIP balík).
 *
 * Pravidla:
 *   - Supplier IČO z XML musí odpovídat aktuálnímu scope. Jinak fail per file.
 *   - Klient: lookup po IČO; pokud chybí, ARES → vytvoř.
 *   - Project:
 *       a) faktura má project_number → najít nebo vytvořit (per-klient unikátní project_number).
 *       b) napříč balíkem má klient >1 odlišných emailů → per-(client, email) projekt s názvem
 *          "{company_name} – {email}", projekt se přiřadí podle emailu faktury.
 *       c) jinak project_id = NULL.
 *   - Duplicity: pokud (supplier_id, varsymbol) existuje → skip s reportem.
 *   - Status: pokud je due_date starší než 30 dní od dnešního data → 'paid'
 *     (paid_at = tax_date|issue_date), jinak 'issued' (paid_at = NULL).
 *   - Snapshoty: vyrobí čerstvé z aktuálního supplier/client/bank.
 */
final class InvoiceImportService
{
    /** Bezpečnostní limity proti zip-bomb / DoS. */
    private const MAX_ZIP_ENTRIES = 500;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 50 * 1024 * 1024; // 50 MiB
    private const MAX_SINGLE_ENTRY_BYTES = 10 * 1024 * 1024;       // 10 MiB

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly ProjectRepository $projects,
        private readonly ClientResolver $clientResolver,
        private readonly PohodaXmlParser $pohoda,
        private readonly IsdocParser $isdoc,
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoiceCalculator $calculator,
        private readonly IsdocToPurchaseInvoiceMapper $purchaseMapper,
        private readonly VarsymbolGenerator $varsymbol,
    ) {}

    /**
     * Import balíku souborů — vystavené i přijaté faktury.
     *
     * `$kind` parametr:
     *   - `'issued'`   — všechny soubory zpracovat jako vydané faktury (legacy behavior).
     *                    Soubory s buyer-tenant IČO (= my zákazník) skipnout jako odmítnuté.
     *   - `'purchase'` — všechny zpracovat jako přijaté faktury (vendor je supplier z ISDOC).
     *   - `'auto'`     — per-soubor detekce dle IČO:
     *       supplier IČO == tenant → my dodavatel → issued cesta
     *       customer IČO == tenant → my zákazník → purchase cesta
     *       ani jedno → reject (cizí ISDOC)
     *
     * @param list<array{name:string, content:string}> $files Vstupní soubory (rozbalené ze ZIP / single).
     * @return array{summary:array<string,int>, results:list<array<string,mixed>>}
     */
    public function importBundle(array $files, int $supplierId, int $userId, string $kind = 'auto'): array
    {
        if (!in_array($kind, ['auto', 'issued', 'purchase'], true)) {
            throw new \InvalidArgumentException("Neznámý kind '{$kind}', použij auto|issued|purchase.");
        }

        $supplierIc = $this->loadSupplierIc($supplierId);
        if ($supplierIc === null) {
            throw new \RuntimeException("Supplier #$supplierId nemá vyplněné IČO — import nemůže ověřit shodu.");
        }
        $tenantIc = preg_replace('/\D/', '', $supplierIc);

        // 1. Rozbalení ZIPů na ploché soubory.
        $flat = [];
        foreach ($files as $f) {
            if ($this->isZip($f['name'], $f['content'])) {
                foreach ($this->unzip($f['content']) as $sub) {
                    $flat[] = ['name' => $f['name'] . '/' . $sub['name'], 'content' => $sub['content']];
                }
            } else {
                $flat[] = $f;
            }
        }

        // 2. Parsování všech souborů — žádná supplier_ic validation tady, ta se dělá při dispatch
        //    (rozdíl mezi issued vs purchase route).
        $parsed = [];
        foreach ($flat as $f) {
            $r = $this->parseRaw($f['name'], $f['content']);
            $parsed[] = ['file' => $f['name']] + $r;
        }

        // 3. Cross-batch analýza emailů (jen pro issued cesta).
        $emailMap = $this->buildEmailMap($parsed);

        // 4. Dispatch + processing.
        $results = [];
        $created = 0;
        $skipped = 0;
        $failed = 0;
        // Scopes vydaných faktur, jejichž číselné řady je po importu třeba dorovnat
        // (counter pozadu za historickými čísly). Klíč = typ|client|datum (idempotentní).
        $counterScopes = [];

        foreach ($parsed as $entry) {
            if (isset($entry['error'])) {
                $results[] = ['file' => $entry['file'], 'status' => 'failed', 'reason' => $entry['error']];
                $failed++;
                continue;
            }
            foreach ($entry['invoices'] as $inv) {
                $label = $entry['file'] . ' / ' . ($inv['varsymbol'] ?? '?');
                if (isset($inv['__error'])) {
                    $results[] = ['file' => $label, 'status' => 'failed', 'reason' => $inv['__error']];
                    $failed++;
                    continue;
                }

                // Auto-detekce per soubor: dle IČO buyer vs supplier
                $route = $this->detectRoute($inv, $tenantIc, $kind);

                try {
                    if ($route === 'issued') {
                        $r = $this->processOne($inv, $supplierId, $userId, $emailMap);
                    } elseif ($route === 'purchase') {
                        $r = $this->processPurchase($inv, $supplierId, $userId);
                    } else {
                        // 'reject' — ISDOC patří jinému plátci (neshoda IČO s tenantem)
                        $r = ['status' => 'failed', 'reason' => $route];
                    }
                    // Přidej kind do response pro UI
                    $r['kind'] = $route === 'issued' || $route === 'purchase' ? $route : null;
                    $results[] = ['file' => $label, 'status' => $r['status']] + $r;
                    if ($r['status'] === 'created') {
                        $created++;
                        if ($route === 'issued') {
                            $type = (string) ($inv['invoice_type'] ?? 'invoice');
                            $cli  = (int) ($r['client_id'] ?? 0);
                            $date = (string) ($inv['issue_date'] ?? '');
                            $counterScopes[$type . '|' . $cli . '|' . $date] = [$type, $cli, $date];
                        }
                    }
                    elseif ($r['status'] === 'skipped') $skipped++;
                    else $failed++;
                } catch (\Throwable $e) {
                    $results[] = ['file' => $label, 'status' => 'failed', 'reason' => $e->getMessage()];
                    $failed++;
                }
            }
        }

        // Dorovnání číselných řad po importu vydaných faktur: counter se posune za
        // nejvyšší importované číslo odpovídající aktuálnímu template (jinak no-op).
        // Selhání syncu nesmí shodit import — jen se přeskočí (generátor je i tak
        // duplicate-aware při dalším vystavení).
        foreach ($counterScopes as [$type, $cli, $date]) {
            if (!in_array($type, ['invoice', 'proforma', 'credit_note'], true)) {
                continue;
            }
            try {
                $for = $date !== '' ? new \DateTimeImmutable($date) : null;
                $this->varsymbol->syncCounter($supplierId, $type, $for, $cli);
            } catch (\Throwable) {
                // ignore — best-effort dorovnání
            }
        }

        return [
            'summary' => ['created' => $created, 'skipped' => $skipped, 'failed' => $failed],
            'results' => $results,
        ];
    }

    /**
     * Per-soubor detekce: kam (issued / purchase / reject) faktura patří.
     * `$kind='auto'` — porovná tenant IČO s supplier/customer.
     * `$kind='issued'|'purchase'` — vynutí směr, jen ověří že tenant je ve správné roli.
     *
     * @param array<string,mixed> $inv
     * @return string 'issued'|'purchase' nebo error message (reject reason)
     */
    private function detectRoute(array $inv, string $tenantIc, string $kind): string
    {
        $supplierIc = preg_replace('/\D/', '', (string) ($inv['supplier']['ic'] ?? '')) ?: '';
        $customerIc = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? '')) ?: '';

        // Pokud parser nevyplnil supplier (starší Pohoda XML), fallback na top-level
        // supplier_ic je už ošetřený přes parser → ale jistota:
        if ($supplierIc === '' && isset($inv['__supplier_ic'])) {
            $supplierIc = preg_replace('/\D/', '', (string) $inv['__supplier_ic']) ?: '';
        }

        $weAreSupplier = $supplierIc !== '' && $supplierIc === $tenantIc;
        $weAreCustomer = $customerIc !== '' && $customerIc === $tenantIc;

        if ($kind === 'issued') {
            if (!$weAreSupplier) return "ISDOC patří jinému dodavateli (supplier IČO: {$supplierIc}, tenant: {$tenantIc}).";
            return 'issued';
        }
        if ($kind === 'purchase') {
            if (!$weAreCustomer) return "ISDOC patří jinému plátci (buyer IČO: {$customerIc}, tenant: {$tenantIc}).";
            return 'purchase';
        }
        // auto
        if ($weAreSupplier)  return 'issued';
        if ($weAreCustomer)  return 'purchase';
        return "Auto-detekce: ani jeden IČO nematchuje tenant (supplier: {$supplierIc}, buyer: {$customerIc}, tenant: {$tenantIc}).";
    }

    /**
     * Zpracuje fakturu jako purchase invoice (přijatá).
     * Reuse IsdocToPurchaseInvoiceMapper.
     *
     * @param array<string,mixed> $inv
     * @return array<string,mixed>
     */
    private function processPurchase(array $inv, int $supplierId, int $userId): array
    {
        try {
            $r = $this->purchaseMapper->map($inv, $supplierId, $userId);
            return [
                'status' => 'created',
                'reason' => $r['vendor_created'] ? 'vytvořen vendor + draft přijaté faktury' : 'draft přijaté faktury (vendor reuse)',
                'purchase_invoice_id' => $r['purchase_invoice_id'],
                'vendor_id'           => $r['vendor_id'],
            ];
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Parse jediný soubor bez validation IČO (ta proběhne při dispatch dle kind).
     * Pro Pohoda XML (nemá AccountingSupplierParty struct v parser výstupu) doplníme
     * top-level supplier_ic do každé invoice jako `__supplier_ic` hint pro detectRoute.
     *
     * @return array{invoices:list<array<string,mixed>>}|array{error:string}
     */
    private function parseRaw(string $name, string $content): array
    {
        try {
            if ($this->isPdf($name, $content)) {
                $extracted = $this->pdfIsdoc->extract($content);
                if ($extracted === null) {
                    return ['error' => 'PDF neobsahuje ISDOC přílohu (PDF/A-3). Nahraj prosím .isdoc / .xml soubor, nebo PDF, který má ISDOC embed.'];
                }
                $content = $extracted;
            }
            $content = self::stripBom($content);
            $parsed = self::looksLikeIsdoc($name, $content)
                ? $this->isdoc->parse($content)
                : $this->pohoda->parse($content);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $topSupplierIc = (string) ($parsed['supplier_ic'] ?? '');
        $invoices = $parsed['invoices'] ?? [];
        // Inject top-level supplier_ic do každé invoice (Pohoda parser nemá supplier party na invoice úrovni)
        foreach ($invoices as &$inv) {
            if (is_array($inv) && !isset($inv['__supplier_ic']) && $topSupplierIc !== '') {
                $inv['__supplier_ic'] = $topSupplierIc;
            }
        }
        unset($inv);

        return ['invoices' => $invoices];
    }

    /**
     * @param list<array<string,mixed>> $parsedFiles
     * @return array<string, array<string,bool>>  IČO → set emailů
     */
    private function buildEmailMap(array $parsedFiles): array
    {
        $map = [];
        foreach ($parsedFiles as $entry) {
            foreach ($entry['invoices'] ?? [] as $inv) {
                $ic = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? ''));
                $email = trim((string) ($inv['client']['email'] ?? ''));
                if ($ic === '' || $email === '') continue;
                $map[$ic][$email] = true;
            }
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string, array<string,bool>> $emailMap
     * @return array<string,mixed>
     */
    private function processOne(array $inv, int $supplierId, int $userId, array $emailMap): array
    {
        $varsymbol = (string) $inv['varsymbol'];

        // Charset whitelist — varsymbol importovaný z ISDOC/Pohoda XML protéká do
        // emailových šablon, PDF cache filenamů, ZIP entry names a CSV cell. Bez
        // omezení by ISDOC `<inv:symVar><a href=//evil></inv:symVar>` zaškodil
        // (HTML injection v emailu, CSV formula injection apod. — viz security
        // report @andrejtomci #3). Povolené znaky: A-Z, a-z, 0-9, _ a `-`,
        // max 20 znaků (= DB column limit).
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $varsymbol)) {
            return [
                'status' => 'failed',
                'reason' => "Neplatný varsymbol '{$varsymbol}' (povoleno: A-Z, a-z, 0-9, _, -; max 20 znaků).",
            ];
        }

        // Duplicate check
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $varsymbol]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return [
                'status' => 'skipped',
                'reason' => "Faktura s varsymbolem $varsymbol již existuje (#{$existing}).",
                'invoice_id' => (int) $existing,
            ];
        }

        // Client
        $clientResult = $this->clientResolver->resolve($inv['client'] ?? [], $supplierId);
        $clientId = $clientResult['id'];

        // Project
        $projectId = $this->resolveProject($inv, $clientId, $emailMap);

        // Currency
        $currencyId = $this->currencyId($supplierId, (string) ($inv['currency'] ?? 'CZK'));

        // Status: due_date starší než 30 dní → paid, jinak sent.
        // Logika: importované doklady už klient prokazatelně dostal (jinak by je
        // nezaznamenali v původním systému), takže status='sent' je správnější
        // než 'issued'. Staré splatné → 'paid' (předpoklad zaplaceno).
        // sent_at = issue_date — nemáme přesnější údaj z původního systému,
        // den vystavení je nejlepší aproximace okamžiku odeslání.
        $taxDate = $inv['tax_date'] ?? null;
        $dueDate = (string) $inv['due_date'];
        $threshold = (new \DateTimeImmutable('today'))->modify('-30 days');
        $isPaid = $dueDate !== '' && new \DateTimeImmutable($dueDate) < $threshold;
        $status = $isPaid ? 'paid' : 'sent';
        $paidAt = $isPaid ? ($taxDate ?: $inv['issue_date']) : null;
        $sentAt = (string) $inv['issue_date'] . ' 12:00:00';

        // Výchozí kategorie tržby — default zakázky > klienta (sdílený helper, stejná
        // logika jako createDraft / recurring), aby i importovaná faktura dostala kategorii.
        $revenueCategoryId = InvoiceRepository::resolveDefaultRevenueCategoryId($this->db->pdo(), $clientId, $projectId);

        // Insert invoice
        $pdo = $this->db->pdo();
        $sql = 'INSERT INTO invoices
            (supplier_id, varsymbol, invoice_type, client_id, project_id,
             issue_date, tax_date, due_date, currency_id, exchange_rate, exchange_rate_date,
             reverse_charge, language,
             total_without_vat, total_vat, total_with_vat,
             status, sent_at, paid_at, revenue_category_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?)';

        $pdo->prepare($sql)->execute([
            $supplierId,
            $varsymbol,
            (string) $inv['invoice_type'],
            $clientId,
            $projectId,
            (string) $inv['issue_date'],
            $taxDate,
            $dueDate,
            $currencyId,
            $inv['exchange_rate'] !== null ? (float) $inv['exchange_rate'] : null,
            $inv['exchange_rate'] !== null ? (string) $inv['issue_date'] : null,
            !empty($inv['reverse_charge']) ? 1 : 0,
            'cs',
            $status,
            $sentAt,
            $paidAt,
            $revenueCategoryId,
            $userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        // Items
        $this->insertItems($invoiceId, $inv['items'] ?? []);

        // Recompute totals (z položek)
        $this->calculator->recompute($invoiceId);

        // Snapshoty z aktuálního supplier/client/bank
        $snapshots = $this->snapshots->build($clientId, $currencyId, $supplierId);
        $pdo->prepare(
            'UPDATE invoices SET client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ? WHERE id = ?'
        )->execute([
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);

        return [
            'status' => 'created',
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
            'client_created' => $clientResult['created'],
            'project_id' => $projectId,
            'varsymbol' => $varsymbol,
            'imported_status' => $status,
        ];
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string, array<string,bool>> $emailMap
     */
    private function resolveProject(array $inv, int $clientId, array $emailMap): ?int
    {
        $projectNumber = trim((string) ($inv['project_number'] ?? ''));
        if ($projectNumber !== '') {
            return $this->findOrCreateProjectByNumber($clientId, $projectNumber);
        }

        // Multi-email rule
        $ic = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? ''));
        $email = trim((string) ($inv['client']['email'] ?? ''));
        if ($ic !== '' && $email !== '' && count($emailMap[$ic] ?? []) > 1) {
            $companyName = (string) ($inv['client']['company_name'] ?? '');
            return $this->findOrCreateProjectByEmail($clientId, $companyName, $email);
        }

        return null;
    }

    private function findOrCreateProjectByNumber(int $clientId, string $projectNumber): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM projects WHERE client_id = ? AND project_number = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $projectNumber]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        return $this->projects->create([
            'client_id'        => $clientId,
            'name'             => $projectNumber,
            'project_number'   => $projectNumber,
            'status'           => 'active',
            'payment_due_days' => 14,
            'hourly_rate'      => 0,
        ]);
    }

    private function findOrCreateProjectByEmail(int $clientId, string $companyName, string $email): int
    {
        $name = trim($companyName . ' – ' . $email);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM projects WHERE client_id = ? AND name = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        return $this->projects->create([
            'client_id'        => $clientId,
            'name'             => $name,
            'status'           => 'active',
            'payment_due_days' => 14,
            'hourly_rate'      => 0,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function insertItems(int $invoiceId, array $items): void
    {
        if (empty($items)) return;
        $vatRates = $this->loadVatRates();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)'
        );

        // Reverse charge na parent faktuře pro správný default classification code
        $rcStmt = $this->db->pdo()->prepare('SELECT reverse_charge FROM invoices WHERE id = ?');
        $rcStmt->execute([$invoiceId]);
        $rc = (bool) $rcStmt->fetchColumn();

        foreach (array_values($items) as $i => $item) {
            $rate = (float) ($item['vat_rate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate);
            // Auto-klasifikace — bez ní by Pohoda import nedorazil do DPH/KH
            $code = $item['vat_classification_code']
                ?? \MyInvoice\Repository\InvoiceRepository::defaultSaleClassificationCode($rate, $rc);
            $stmt->execute([
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                $i,
                $code !== null ? (string) $code : null,
            ]);
        }
    }

    /**
     * @return array<int,float> id → rate_percent
     */
    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * @param array<int,float> $rates
     */
    private function matchVatRateId(array $rates, float $rate): int
    {
        $bestId = 0;
        $bestDiff = INF;
        foreach ($rates as $id => $r) {
            $diff = abs($r - $rate);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestId = $id;
            }
        }
        return $bestId;
    }

    private function currencyId(int $supplierId, string $code): int
    {
        $code = strtoupper($code);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Měna $code není nakonfigurovaná pro tohoto dodavatele.");
        }
        return (int) $id;
    }

    private function loadSupplierIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === null || $ic === '') return null;
        return (string) $ic;
    }

    private function isZip(string $name, string $content): bool
    {
        if (str_ends_with(strtolower($name), '.zip')) return true;
        // Magic bytes — PK\x03\x04 nebo PK\x05\x06 (empty zip).
        // PDF má sice taky neuzipped magic, ale začíná `%PDF-`, takže nedojde
        // k falešné shodě. Defenzivně přidáme explicit PDF guard.
        if (str_starts_with($content, '%PDF-')) return false;
        return strncmp($content, "PK\x03\x04", 4) === 0 || strncmp($content, "PK\x05\x06", 4) === 0;
    }

    private function isPdf(string $name, string $content): bool
    {
        return str_ends_with(strtolower($name), '.pdf') || str_starts_with($content, '%PDF-');
    }

    /**
     * Odstraní vedoucí UTF-8 BOM (EF BB BF). Někteří producenti (iDoklad) jím
     * prefixují ISDOC XML; ltrim ho neodstraní a rozbíjel detekci formátu níže.
     */
    private static function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * Rozliší ISDOC vs Pohoda XML. ISDOC poznáme podle přípony .isdoc nebo podle
     * přítomnosti ISDOC namespace (Pohoda XML ho neobsahuje). Dřív se testoval i
     * prefix '<?xml', což rozbíjel UTF-8 BOM → iDoklad PDF (BOM + namespace) padalo
     * na Pohoda parser s chybou "root není dataPack" (issue #39).
     */
    private static function looksLikeIsdoc(string $name, string $content): bool
    {
        return str_contains(strtolower($name), '.isdoc')
            || str_contains($content, 'isdoc.cz/namespace');
    }

    /**
     * @return list<array{name:string, content:string}>
     */
    private function unzip(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp-zip-');
        file_put_contents($tmp, $content);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Nelze otevřít ZIP.');
        }
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            @unlink($tmp);
            throw new \RuntimeException('ZIP obsahuje příliš mnoho souborů (max ' . self::MAX_ZIP_ENTRIES . ').');
        }

        $out = [];
        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $name = $stat['name'];
            // Defense in depth — odmítni absolutní cesty / traversal v entry name
            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name)) {
                continue;
            }
            // Skip složky a non-XML/ISDOC
            if (str_ends_with($name, '/')) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xml', 'isdoc'], true)) continue;

            $entrySize = (int) ($stat['size'] ?? 0);
            if ($entrySize > self::MAX_SINGLE_ENTRY_BYTES) {
                $zip->close();
                @unlink($tmp);
                throw new \RuntimeException("Položka {$name} v ZIP je příliš velká (max " . self::MAX_SINGLE_ENTRY_BYTES . " B).");
            }
            $totalBytes += $entrySize;
            if ($totalBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                $zip->close();
                @unlink($tmp);
                throw new \RuntimeException('Celková velikost ZIP po rozbalení překračuje povolený limit (zip-bomb ochrana).');
            }

            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                $out[] = ['name' => basename($name), 'content' => $data];
            }
        }
        $zip->close();
        @unlink($tmp);
        return $out;
    }
}
