<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * CRUD pro přijaté faktury (purchase invoices) — paralel k InvoiceRepository,
 * ale pro doklady, které dostáváme od dodavatelů.
 *
 * Klíčové rozdíly oproti vystaveným fakturám:
 *   - vendor_id místo client_id (vendor = protistrana, řádek v `clients` s is_vendor=1)
 *   - status lifecycle: draft → received → booked → paid (+ cancelled)
 *   - žádný approval / sent / reminder flow
 *   - varsymbol generovaný z purchase_invoice_counters dle per-supplier šablony
 *     (supplier.purchase_invoice_number_format) nebo defaultu {PP}{YY}{MM}{CCC}
 *     (např. PF2602001); {PP} dle daňového typu (PF/PN plný, KU/KN krácený, NU/NN bez nároku)
 *
 * Bezpečnostní pravidla:
 *   - Vždy filtrovat WHERE supplier_id = ? (tenant scope)
 *   - Mutating operace ověřit ownership přes find() s supplier_id
 *   - Žádné raw SQL s user input — vždy prepared statements
 */
final class PurchaseInvoiceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Najde fakturu jen pokud patří danému tenantovi.
     * Vrací null jak pro neexistující, tak pro cizí (consistent — neprozrazuje cross-tenant existenci).
     */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.*,
                    c.company_name AS vendor_company_name, c.ic AS vendor_ic, c.dic AS vendor_dic,
                    c.main_email AS vendor_main_email, c.language AS vendor_language,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    pcur.code AS payment_currency, pcur.symbol AS payment_currency_symbol,
                    ec.label AS expense_category_label, ec.code AS expense_category_code
               FROM purchase_invoices pi
               JOIN clients c        ON c.id   = pi.vendor_id
               JOIN currencies cur   ON cur.id = pi.currency_id
          LEFT JOIN currencies pcur  ON pcur.id = pi.payment_currency_id
          LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->castInvoice($row);
        $row['items'] = $this->itemsFor($id);
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        $row['totals'] = [
            'without_vat'         => $row['total_without_vat'],
            'vat'                 => $row['total_vat'],
            'with_vat'            => $row['total_with_vat'],
            'rounding'            => $row['rounding'],
            'advance_paid_amount' => $row['advance_paid_amount'],
            'amount_to_pay'       => $row['amount_to_pay'],
        ];

        // Propojení se zálohou (advance):
        //  - linked_advance   = záloha, kterou tato finální faktura vyúčtovává
        //  - settled_by       = finální faktura vyúčtovávající tuto zálohu (reverzně)
        //  - advance_link_suggestion = AI návrh (suggest & confirm), čeká na potvrzení
        $row['linked_advance'] = $row['advance_purchase_invoice_id'] !== null
            ? $this->briefFor((int) $row['advance_purchase_invoice_id'], $supplierId)
            : null;
        $row['advance_link_suggestion'] = $row['advance_link_suggested_id'] !== null
            ? $this->briefFor((int) $row['advance_link_suggested_id'], $supplierId)
            : null;
        $row['settled_by'] = ($row['document_kind'] ?? '') === 'advance'
            ? $this->settledByFor($id, $supplierId)
            : null;

        // Příznaky pro UI tlačítka „spárovat" (zobrazit jen když existuje protějšek):
        //  - has_advance_candidates    = vyúčtovací faktura bez vazby a existuje nespárovaná záloha
        //  - has_settlement_candidates = záloha bez vyúčtování a existuje nepropojená finální faktura
        $row['has_advance_candidates'] = false;
        $row['has_settlement_candidates'] = false;
        $vendorId = (int) ($row['vendor_id'] ?? 0);
        if (($row['document_kind'] ?? '') !== 'advance') {
            if ($row['advance_purchase_invoice_id'] === null) {
                $q = $this->db->pdo()->prepare(
                    "SELECT EXISTS (
                              SELECT 1 FROM purchase_invoices pi
                               WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                                 AND pi.document_kind = 'advance' AND pi.status != 'cancelled'
                                 AND pi.id <> ?
                                 AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                                  WHERE s.advance_purchase_invoice_id = pi.id)
                            )"
                );
                $q->execute([$supplierId, $vendorId, $id]);
                $row['has_advance_candidates'] = (bool) $q->fetchColumn();
            }
        } elseif ($row['settled_by'] === null) {
            $q = $this->db->pdo()->prepare(
                "SELECT EXISTS (
                          SELECT 1 FROM purchase_invoices pi
                           WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                             AND pi.document_kind != 'advance' AND pi.status != 'cancelled'
                             AND pi.advance_purchase_invoice_id IS NULL AND pi.id <> ?
                        )"
            );
            $q->execute([$supplierId, $vendorId, $id]);
            $row['has_settlement_candidates'] = (bool) $q->fetchColumn();
        }
        return $row;
    }

    /**
     * Stručné shrnutí přijaté faktury (pro propojení/odkazy v detailu). NULL pokud
     * neexistuje nebo nepatří tenantovi.
     *
     * @return array{id:int, varsymbol:?string, vendor_invoice_number:?string,
     *               document_kind:?string, status:string, issue_date:?string,
     *               total_with_vat:float, currency:string}|null
     */
    private function briefFor(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) return null;
        return [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ];
    }

    /** Finální faktura, která vyúčtovává tuto zálohu (reverzní pohled). */
    private function settledByFor(int $advanceId, int $supplierId): ?array
    {
        $id = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE advance_purchase_invoice_id = ? AND supplier_id = ? LIMIT 1'
        );
        $id->execute([$advanceId, $supplierId]);
        $finalId = $id->fetchColumn();
        return $finalId !== false ? $this->briefFor((int) $finalId, $supplierId) : null;
    }

    /**
     * Items dané přijaté faktury, seřazené.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsFor(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.quantity, pii.unit,
                    pii.unit_price_without_vat, pii.vat_rate_id, pii.vat_rate_snapshot,
                    pii.total_without_vat, pii.total_vat, pii.total_with_vat,
                    pii.order_index, pii.vat_classification_code, pii.is_fixed_asset,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM purchase_invoice_items pii
               JOIN vat_rates vr ON vr.id = pii.vat_rate_id
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /**
     * Seznam přijatých faktur tenantu, seskupený po měsících podle **issue_date**
     * (datum vystavení faktury dodavatelem).
     *
     * Pozn.: NEpoužíváme DUZP (tax_date) protože dodavatel může vystavit fakturu
     * v jiném měsíci než je DUZP — typicky DUZP konec měsíce, vystavení následující
     * měsíc. Z účetního hlediska user fakturu uplatní v měsíci, kdy ji obdrží/byla
     * vystavena dodavatelem, ne v měsíci DUZP. DPH přiznání má vlastní logic dle
     * tax_date — viz DphPriznaniBuilder.
     *
     * Output: ['data' => [{month, count, totals_per_currency, invoices: [...]}], 'meta' => ...]
     *
     * Filtry:
     *   supplier_id (povinné — tenant scope)
     *   q, status, document_kind, vendor_id, year, month, date_from, date_to, currency, unpaid_only, overdue
     */
    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        if ($supplierId === 0) {
            return ['data' => [], 'meta' => ['total' => 0]];
        }

        $where = ['pi.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "pi.status IN ($place)";
            foreach ($statuses as $s) $params[] = (string) $s;
        }
        if (!empty($filters['document_kind'])) {
            $kinds = is_array($filters['document_kind']) ? $filters['document_kind'] : [$filters['document_kind']];
            $place = implode(',', array_fill(0, count($kinds), '?'));
            $where[] = "pi.document_kind IN ($place)";
            foreach ($kinds as $k) $params[] = (string) $k;
        }
        if (!empty($filters['vendor_id'])) {
            $where[] = 'pi.vendor_id = ?';
            $params[] = (int) $filters['vendor_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(pi.issue_date) = ?';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(pi.issue_date) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'pi.issue_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'pi.issue_date <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "pi.status IN ('received','booked')";
        }
        if (!empty($filters['overdue'])) {
            $where[] = "pi.status IN ('received','booked') AND pi.due_date <= CURDATE()";
        }
        if (!empty($filters['needs_review'])) {
            $where[] = "pi.extraction_warning IS NOT NULL";
        }
        // „Předané k úhradě" — odvozená dimenze (příznak payment_ordered_at), NE status.
        // '1' = předané, '0' = nepředané. Status zůstává received/booked/paid (ortogonální).
        if (isset($filters['payment_ordered']) && $filters['payment_ordered'] !== null && $filters['payment_ordered'] !== '') {
            $where[] = ((string) $filters['payment_ordered'] === '1')
                ? 'pi.payment_ordered_at IS NOT NULL'
                : 'pi.payment_ordered_at IS NULL';
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query / unexpected match
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(pi.varsymbol LIKE ? OR pi.vendor_invoice_number LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        // MariaDB 10.2+ window function — COUNT(*) OVER() vrací total v každém řádku.
        // Místo 2 query (COUNT + SELECT s LIMIT) jeden round-trip, žádný race condition
        // mezi count a paginated select, žádný duplicate WHERE / JOIN parsing.
        $selectTotal = $perPage > 0 ? ', COUNT(*) OVER() AS total_rows' : '';

        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                       pi.vendor_id, pi.supplier_id,
                       pi.issue_date, pi.tax_date, pi.due_date, pi.received_at,
                       pi.currency_id, cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                       pi.exchange_rate, pi.exchange_rate_date,
                       pi.total_without_vat, pi.total_vat, pi.total_with_vat,
                       pi.advance_paid_amount, pi.amount_to_pay,
                       pi.payment_ordered_at,
                       pi.status, pi.booked_at, pi.paid_at, pi.cancelled_at,
                       pi.extraction_warning, pi.vat_deduction, pi.vat_deduction_percent, pi.tax_deductible,
                       c.company_name AS vendor_company_name, c.ic AS vendor_ic,
                       DATE_FORMAT(pi.issue_date, '%Y-%m') AS month_bucket,
                       EXISTS (SELECT 1 FROM purchase_invoices adv_f
                               WHERE adv_f.advance_purchase_invoice_id = pi.id) AS is_settled_advance
                       {$selectTotal}
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE $whereSql
                 ORDER BY pi.issue_date DESC, pi.id DESC";

        $offset = 0;
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= ' LIMIT ? OFFSET ?';
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // total_rows extrahujeme z prvního řádku (window function vrací stejnou hodnotu
        // v každém řádku). Pokud výsledek je prázdný a používáme pagination, total=0.
        $total = null;
        if ($perPage > 0) {
            $total = !empty($rows) ? (int) $rows[0]['total_rows'] : 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            unset($row['total_rows']); // metadata, nepatří do invoice payloadu
            // Spárovaná záloha = advance, na kterou ukazuje finální (vyúčtovací) faktura.
            // Zachytit z DB flagu PŘED castem a vyřadit z payloadu (interní metadata).
            $isSettledAdvance = (string) ($row['document_kind'] ?? '') === 'advance'
                && (int) ($row['is_settled_advance'] ?? 0) === 1;
            unset($row['is_settled_advance']);
            $row = $this->castInvoice($row);
            $month = (string) $row['month_bucket'];
            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'totals_per_currency' => [],
                    'invoices' => [],
                ];
            }
            $grouped[$month]['invoices'][] = $row;
            $grouped[$month]['count']++;

            // Měsíční součet = reálný náklad. Vyřadit: draft/cancelled a spárovanou/zaplacenou
            // zálohu (advance) — náklad nese finální faktura, jinak 2× započteno (shoda s
            // costs_by_month / CRM). Nespárovaná nezaplacená záloha se počítá (očekávaný náklad).
            // Řádek se i tak zobrazí (analogicky proforma u vystavených faktur).
            $excludedAdvance = $row['document_kind'] === 'advance'
                && ($row['status'] === 'paid' || $isSettledAdvance);
            if (!in_array($row['status'], ['draft', 'cancelled'], true) && !$excludedAdvance) {
                $cur = $row['currency'];
                if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                    $grouped[$month]['totals_per_currency'][$cur] = [
                        'currency'    => $cur,
                        'without_vat' => 0.0,
                        'vat'         => 0.0,
                        'with_vat'    => 0.0,
                    ];
                }
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += (float) $row['total_without_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += (float) $row['total_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += (float) $row['total_with_vat'];
            }
        }
        foreach ($grouped as &$g) {
            $g['totals_per_currency'] = array_values($g['totals_per_currency']);
        }
        unset($g);

        $meta = ['total' => $total ?? array_sum(array_column($grouped, 'count'))];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return ['data' => array_values($grouped), 'meta' => $meta];
    }

    /**
     * Vytvoří draft přijaté faktury. Vrací nové id.
     *
     * Pravidla:
     *   - vendor_id MUSÍ patřit do supplier_id (volající kontroluje přes SupplierGuard nad clients)
     *   - varsymbol je volitelný — pokud chybí, vygeneruje se až při přechodu na received
     *   - vendor_snapshot je povinné (uložíme aktuální vendor data jako immutable)
     */
    public function createDraft(array $data, int $userId, int $supplierId): int
    {
        $pdo = $this->db->pdo();

        $vendorId = (int) ($data['vendor_id'] ?? 0);
        if ($vendorId === 0) {
            throw new \InvalidArgumentException('vendor_id chybí');
        }

        // Sanity check: vendor existuje a patří tenantovi
        $stmt = $pdo->prepare('SELECT supplier_id, default_expense_category_id FROM clients WHERE id = ?');
        $stmt->execute([$vendorId]);
        $vendorRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $vendorSupplier = (int) ($vendorRow['supplier_id'] ?? 0);
        if ($vendorSupplier !== $supplierId) {
            throw new \InvalidArgumentException("Vendor #$vendorId nepatří tomuto tenantovi.");
        }

        // Výchozí kategorie nákladu dodavatele — aplikuje se, pokud volající kategorii
        // explicitně neurčil. Platí pro manuální zadání i pro všechny importy
        // (AI, ISDOC/ZIP, iDoklad, Fakturoid, bankovní párování), které jdou tudy.
        // Sjednocuje chování se server-side backfillem v ClientRepository::update().
        $expenseCategoryId = (isset($data['expense_category_id']) && $data['expense_category_id'])
            ? (int) $data['expense_category_id']
            : (($vendorRow['default_expense_category_id'] ?? null) !== null
                ? (int) $vendorRow['default_expense_category_id']
                : null);

        // Vendor invoice number — povinné, validace max 50 znaků
        $vendorInvoiceNumber = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvoiceNumber === '') {
            throw new \InvalidArgumentException('vendor_invoice_number je povinné');
        }
        if (strlen($vendorInvoiceNumber) > 50) {
            throw new \InvalidArgumentException('vendor_invoice_number má max 50 znaků');
        }

        $documentKind = (string) ($data['document_kind'] ?? 'invoice');
        if (!in_array($documentKind, ['invoice', 'receipt', 'credit_note', 'advance'], true)) {
            $documentKind = 'invoice';
        }

        $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
        if ($manualVarsymbol === '') {
            $manualVarsymbol = null;
        } elseif (strlen($manualVarsymbol) > 20) {
            throw new \InvalidArgumentException('varsymbol má max 20 znaků');
        }

        // Snapshot vendoru — buď z payloadu, nebo načteme z DB
        $vendorSnapshot = $data['vendor_snapshot'] ?? null;
        if (!is_array($vendorSnapshot)) {
            $vendorSnapshot = $this->buildVendorSnapshot($vendorId);
        }

        $sql = 'INSERT INTO purchase_invoices
            (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
             issue_date, tax_date, due_date, received_at,
             currency_id, exchange_rate, exchange_rate_date, exchange_rate_source,
             reverse_charge, prices_include_vat, language, note_above_items, note_below_items,
             vendor_snapshot, own_snapshot,
             advance_paid_amount,
             payment_currency_id, payment_exchange_rate,
             paid_amount_payment_ccy, paid_amount_invoice_ccy, exchange_diff_base,
             payment_account_number, payment_bank_code, payment_iban, payment_bic,
             payment_variable_symbol, payment_account_source, payment_account_checked_at,
             status, vat_classification_code, vat_deduction, vat_deduction_percent, tax_deductible, is_fixed_asset, expense_category_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $supplierId,
            $vendorId,
            $manualVarsymbol,
            $vendorInvoiceNumber,
            $documentKind,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'],
            (string) ($data['exchange_rate_source'] ?? 'cnb'),
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            json_encode($vendorSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            isset($data['own_snapshot']) && is_array($data['own_snapshot'])
                ? json_encode($data['own_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            (float) ($data['advance_paid_amount'] ?? 0),
            isset($data['payment_currency_id']) && $data['payment_currency_id'] ? (int) $data['payment_currency_id'] : null,
            isset($data['payment_exchange_rate']) ? (float) $data['payment_exchange_rate'] : null,
            isset($data['paid_amount_payment_ccy']) ? (float) $data['paid_amount_payment_ccy'] : null,
            isset($data['paid_amount_invoice_ccy']) ? (float) $data['paid_amount_invoice_ccy'] : null,
            isset($data['exchange_diff_base']) ? (float) $data['exchange_diff_base'] : null,
            ...$this->paymentColumns($data),
            isset($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            in_array($data['vat_deduction'] ?? 'full', ['full', 'none', 'proportional'], true) ? (string) ($data['vat_deduction'] ?? 'full') : 'full',
            max(0.0, min(100.0, (float) ($data['vat_deduction_percent'] ?? 100))),
            (array_key_exists('tax_deductible', $data) && !$data['tax_deductible']) ? 0 : 1,
            !empty($data['is_fixed_asset']) ? 1 : 0,
            $expenseCategoryId,
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Z payloadu (`$data['payment']`) vytáhne 7 sloupců platebního účtu v pořadí
     * shodném s INSERT/UPDATE: account_number, bank_code, iban, bic, variable_symbol,
     * source, checked_at.
     *
     * `source` + `checked_at` se nastaví jen když je účet skutečně použitelný
     * (CZ účet+kód nebo IBAN), případně když volající vynutí `payment['checked']=true`
     * (lazy AI re-extrakce proběhla bez výsledku → gate proti opakování). Jinak
     * zůstávají NULL, aby lazy doplnění mohlo později proběhnout.
     *
     * @param array<string,mixed> $data
     * @return array{0:?string,1:?string,2:?string,3:?string,4:?string,5:?string,6:?string}
     */
    private function paymentColumns(array $data): array
    {
        $p = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $account = self::nullableString($p['account_number'] ?? null);
        $bank    = self::nullableString($p['bank_code'] ?? null);
        $iban    = self::nullableString($p['iban'] ?? null);
        $bic     = self::nullableString($p['bic'] ?? null);
        $vs      = self::nullableString($p['variable_symbol'] ?? null);

        $hasAccount = ($account !== null && $bank !== null) || $iban !== null;
        $allowed = ['isdoc', 'ai', 'ai_reextract', 'qr_image', 'manual'];
        $source = ($hasAccount && in_array($p['source'] ?? '', $allowed, true))
            ? (string) $p['source']
            : null;
        $checkedAt = ($hasAccount || !empty($p['checked'])) ? date('Y-m-d H:i:s') : null;

        return [$account, $bank, $iban, $bic, $vs, $source, $checkedAt];
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /**
     * Aktualizuje platební účet dodavatele (pro „Zaplatit pomocí QR"). Funguje
     * v jakémkoli stavu (účet chceme editovat i u received/booked). Použito
     * dedikovaným endpointem (ruční editace, source='manual') i lazy doplněním
     * z ISDOC/AI při otevření QR modalu.
     *
     * @param array<string,mixed> $payment account_number/bank_code/iban/bic/variable_symbol/source/checked
     */
    public function updatePaymentAccount(int $id, array $payment, int $supplierId): void
    {
        [$account, $bank, $iban, $bic, $vs, $source, $checkedAt] = $this->paymentColumns(['payment' => $payment]);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET
                 payment_account_number = ?, payment_bank_code = ?, payment_iban = ?, payment_bic = ?,
                 payment_variable_symbol = ?, payment_account_source = ?, payment_account_checked_at = ?
               WHERE id = ? AND supplier_id = ?'
        )->execute([$account, $bank, $iban, $bic, $vs, $source, $checkedAt, $id, $supplierId]);
    }

    /**
     * Nezaplacené přijaté faktury vhodné do platebního příkazu (status received/booked
     * a zbývá k úhradě). Vrací platební údaje příjemce + DIČ dodavatele (pro CRPDPH
     * ověření). Volitelný filtr měny (= měna účtu plátce).
     *
     * @return list<array<string,mixed>>
     */
    public function listPaymentCandidates(int $supplierId, ?string $currency = null): array
    {
        $where = ["pi.supplier_id = ?", "pi.status IN ('received','booked')", "pi.amount_to_pay > 0"];
        $params = [$supplierId];
        if ($currency !== null && $currency !== '') {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper($currency);
        }
        $sql = "SELECT pi.id, pi.vendor_invoice_number, pi.varsymbol, pi.document_kind,
                       pi.vendor_id, pi.issue_date, pi.due_date,
                       pi.total_with_vat, pi.amount_to_pay, pi.rounding,
                       (pi.pdf_path IS NOT NULL AND pi.pdf_path <> '') AS has_pdf,
                       pi.payment_account_number, pi.payment_bank_code, pi.payment_iban, pi.payment_bic,
                       pi.payment_variable_symbol, pi.payment_constant_symbol,
                       pi.payment_account_source, pi.payment_account_checked_at, pi.payment_ordered_at,
                       cur.code AS currency, cur.symbol AS currency_symbol,
                       c.company_name AS vendor_company_name, c.dic AS vendor_dic, c.ic AS vendor_ic
                  FROM purchase_invoices pi
                  JOIN clients c     ON c.id   = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY pi.due_date ASC, pi.id ASC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['vendor_id']      = (int) $r['vendor_id'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            $r['amount_to_pay']  = (float) $r['amount_to_pay'];
            $r['rounding']       = (float) ($r['rounding'] ?? 0);
            $r['has_pdf']        = (bool) $r['has_pdf'];
        }
        return $rows;
    }

    /**
     * Označí faktury jako zařazené do (vyexportovaného) platebního příkazu.
     * Status NEpřeklápí — to je samostatné rozhodnutí (mark_paid přes setStatus).
     *
     * @param list<int> $ids
     */
    public function markPaymentOrdered(array $ids, int $supplierId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET payment_ordered_at = NOW()
              WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
    }

    /**
     * Update draft přijaté faktury. Volající má ověřit, že je `status='draft'`.
     */
    public function updateDraft(int $id, array $data, int $supplierId): void
    {
        $hasVarsymbol = array_key_exists('varsymbol', $data);
        $manualVarsymbol = null;
        if ($hasVarsymbol) {
            $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
            if ($manualVarsymbol === '') {
                $manualVarsymbol = null;
            } elseif (strlen($manualVarsymbol) > 20) {
                throw new \InvalidArgumentException('varsymbol má max 20 znaků');
            }
        }

        $documentKind = (string) ($data['document_kind'] ?? 'invoice');
        if (!in_array($documentKind, ['invoice', 'receipt', 'credit_note', 'advance'], true)) {
            $documentKind = 'invoice';
        }

        $vendorInvoiceNumber = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvoiceNumber === '') {
            throw new \InvalidArgumentException('vendor_invoice_number je povinné');
        }
        if (strlen($vendorInvoiceNumber) > 50) {
            throw new \InvalidArgumentException('vendor_invoice_number má max 50 znaků');
        }

        // Platební účet pro QR platbu měníme jen když ho volající explicitně poslal
        // (editor faktury). Ostatní update cesty `payment` neposílají → účet zůstává.
        $hasPayment = array_key_exists('payment', $data);
        $paymentSet = '';
        $paymentParams = [];
        if ($hasPayment) {
            $paymentParams = $this->paymentColumns($data);
            $paymentSet = ', payment_account_number = ?, payment_bank_code = ?, payment_iban = ?, payment_bic = ?,'
                . ' payment_variable_symbol = ?, payment_account_source = ?, payment_account_checked_at = ?';
        }

        $sql = 'UPDATE purchase_invoices SET
                vendor_id = ?, vendor_invoice_number = ?, document_kind = ?,
                issue_date = ?, tax_date = ?, due_date = ?, received_at = ?,
                currency_id = ?, exchange_rate = ?, exchange_rate_date = ?, exchange_rate_source = ?,
                reverse_charge = ?, prices_include_vat = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?,
                payment_currency_id = ?, payment_exchange_rate = ?,
                paid_amount_payment_ccy = ?, paid_amount_invoice_ccy = ?, exchange_diff_base = ?,
                vat_classification_code = ?, vat_deduction = ?, vat_deduction_percent = ?, tax_deductible = ?, is_fixed_asset = ?, expense_category_id = ?'
              . $paymentSet
              . ($hasVarsymbol ? ', varsymbol = ?' : '')
              . ' WHERE id = ? AND supplier_id = ?';

        $params = [
            (int) $data['vendor_id'],
            $vendorInvoiceNumber,
            $documentKind,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            empty($data['exchange_rate_date']) ? null : (string) $data['exchange_rate_date'],
            (string) ($data['exchange_rate_source'] ?? 'cnb'),
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            isset($data['payment_currency_id']) && $data['payment_currency_id'] ? (int) $data['payment_currency_id'] : null,
            isset($data['payment_exchange_rate']) ? (float) $data['payment_exchange_rate'] : null,
            isset($data['paid_amount_payment_ccy']) ? (float) $data['paid_amount_payment_ccy'] : null,
            isset($data['paid_amount_invoice_ccy']) ? (float) $data['paid_amount_invoice_ccy'] : null,
            isset($data['exchange_diff_base']) ? (float) $data['exchange_diff_base'] : null,
            isset($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            in_array($data['vat_deduction'] ?? 'full', ['full', 'none', 'proportional'], true) ? (string) ($data['vat_deduction'] ?? 'full') : 'full',
            max(0.0, min(100.0, (float) ($data['vat_deduction_percent'] ?? 100))),
            (array_key_exists('tax_deductible', $data) && !$data['tax_deductible']) ? 0 : 1,
            !empty($data['is_fixed_asset']) ? 1 : 0,
            isset($data['expense_category_id']) && $data['expense_category_id'] ? (int) $data['expense_category_id'] : null,
        ];
        if ($hasPayment) {
            array_push($params, ...$paymentParams);
        }
        if ($hasVarsymbol) $params[] = $manualVarsymbol;
        $params[] = $id;
        $params[] = $supplierId;

        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Smaže fakturu — ON DELETE CASCADE smaže i items.
     * Volající kontroluje, že je status=draft.
     */
    public function delete(int $id, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM purchase_invoices WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
    }

    /**
     * Přepíše items (smaže staré + insertne nové).
     * Volá se z SetItems action; následuje recompute z PurchaseInvoiceCalculator.
     */
    public function replaceItems(int $purchaseInvoiceId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')
            ->execute([$purchaseInvoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)'
        );

        $vatRates = $this->vatRateMap();

        // Reverse charge + země dodavatele — určuje klasifikační kód:
        //   CZ vendor → '40'/'41'/'42' (tuzemsko podle sazby)
        //   CZ vendor + RC → '5' (přenesená povinnost)
        //   EU vendor s 0% → '24e' (přijetí služby z EU, ř.5) — typický pro Microsoft Ireland apod.
        //   non-EU vendor s 0% → '24' (přijetí služby ze 3. země, ř.12) — Anthropic, GitHub apod.
        $metaStmt = $pdo->prepare(
            'SELECT pi.reverse_charge, co.iso2,
                    COALESCE(pi.tax_date, pi.issue_date) AS doc_date
               FROM purchase_invoices pi
               JOIN clients c     ON c.id  = pi.vendor_id
               JOIN countries co  ON co.id = c.country_id
              WHERE pi.id = ?'
        );
        $metaStmt->execute([$purchaseInvoiceId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['reverse_charge' => 0, 'iso2' => 'CZ', 'doc_date' => null];
        $reverseCharge = (bool) $meta['reverse_charge'];
        $countryIso = (string) ($meta['iso2'] ?? 'CZ');
        // Základní sazba pro rok dokladu (číselník daňových konstant) — určuje, kdy
        // sazba znamená "tuzemská základní" v auto-klasifikaci.
        $docYear = !empty($meta['doc_date']) ? (int) substr((string) $meta['doc_date'], 0, 4) : (int) date('Y');
        $standardRate = $this->taxConstants->vatRateStandard($docYear);

        foreach (array_values($items) as $i => $item) {
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            // Auto-klasifikace pro DPH přiznání / KH — pokud caller (importer / manual create)
            // neuvedl explicitní kód, default podle sazby + RC + country. Bez tohohle by
            // faktura NEDORAZILA do výkazů (VatClassificationMapper SKIPNE code=NULL).
            $code = $item['vat_classification_code'] ?? null;
            if ($code === null) {
                $code = self::defaultClassificationCode($rate, $reverseCharge, $countryIso, $standardRate);
            }
            $stmt->execute([
                $purchaseInvoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                (int) ($item['order_index'] ?? $i),
                $code !== null ? (string) $code : null,
                !empty($item['is_fixed_asset']) ? 1 : 0,
            ]);
        }
    }

    /**
     * Default vat_classification_code podle sazby + RC + země dodavatele pro PŘIJATÉ faktury.
     *
     * Mapování:
     *   CZ vendor:
     *     RC + 21%      → '5'  (přenesená povinnost tuzemsko)
     *     21% standard  → '40' (přijaté plnění tuzemsko — základní)
     *     12% standard  → '41' (přijaté plnění tuzemsko — snížená)
     *     0%            → null (osvobozeno bez nároku — user si vybere)
     *   EU vendor (DE, SK, AT, IE, …):
     *     0% → '24e' (přijetí služby z EU, ř.5 — typický pro Microsoft Ireland)
     *     21%/12% → tuzemsko sazby (vendor v EU vykazuje českou DPH — vzácné)
     *   Non-EU vendor (US, UK, atd.):
     *     0% → '24' (přijetí služby ze 3. země / od neusazené osoby, ř.12 — Anthropic, GitHub)
     *     jinak tuzemsko sazby
     *
     * Pro pořízení zboží z EU ('23') či dovoz zboží ze 3. země ('25') si user
     * změní ručně — default 0%+zahraničí mapujeme na SLUŽBY, což je častější CZ IT use case.
     * AI import sem u RC dokladů nespadne: nastavuje explicitní kód (23/24/25 dle
     * supply_nature) + tuzemskou sazbu 21 % už v AiPdfExtractoru (issue #116).
     */
    public static function defaultClassificationCode(
        float $rate,
        bool $reverseCharge,
        ?string $vendorCountryIso2 = null,
        // Základní sazba pro rok dokladu (číselník daňových konstant). Default 21
        // drží zpětnou kompatibilitu pro volání bez kontextu (CLI backfill).
        float $standardRate = 21.0,
    ): ?string {
        $r = (int) round($rate);
        $std = (int) round($standardRate);
        $iso = strtoupper((string) ($vendorCountryIso2 ?? 'CZ'));
        $euCountries = [
            'AT','BE','BG','HR','CY','DK','EE','FI','FR','DE','GR','HU','IE','IT',
            'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        ];
        $isEu = in_array($iso, $euCountries, true);
        $isForeign = $iso !== 'CZ' && $iso !== '';

        // Zahraniční dodavatel + nulová sazba → reverse charge SLUŽBA (drtivě
        // nejčastější případ: digitální předplatná Anthropic/GitHub/Apple/Google).
        // EU → ř.5 (kód 24e), 3. země / neusazená osoba → ř.12 (kód 24). Pořízení
        // nebo dovoz ZBOŽÍ (ř.3 / ř.7) se ze samotné sazby nepozná → tam kód vybírá
        // AI dle povahy plnění (supply_nature) nebo uživatel ručně. Dřív se mimo-EU
        // 0 % defaultovalo na 25 (ř.7 dovoz zboží), což u služeb bylo věcně špatně.
        if ($isForeign && $r === 0) {
            return $isEu ? '24e' : '24';
        }
        // EU vendor + RC + 21 % → pořízení zboží z JČS (kód 23, ř. 3 + ř. 43 mirror + KH A.2).
        // Vzácnější použití (vendor obvykle fakturuje bez DPH), ale když má 21 % sazbu
        // (typicky reverse-charge invoice s vyčíslenou daní pro info), tohle je správně.
        if ($isEu && $reverseCharge && $r >= $std) return '23';
        // CZ tuzemsko (nebo zahraniční vendor s CZ DPH, vzácné)
        if ($reverseCharge && $r >= $std) return '5';
        if ($r >= $std)                   return '40';
        // Snížené sazby 5–15 % (12 aktuální, 10/15 historické). Pásmo 16 až <std
        // záměrně nemapujeme (např. německá 19 % není česká DPH → user vybere ručně).
        if ($r >= 5 && $r <= 15)          return '41';
        return null;
    }

    /**
     * Zafixuje exchange_rate + date + source. NULL rate = vyresetovat.
     */
    public function setExchangeRate(int $id, ?float $rate, ?string $rateDate, string $source, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET exchange_rate = ?, exchange_rate_date = ?, exchange_rate_source = ?
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$rate, $rateDate, $source, $id, $supplierId]);
    }

    /**
     * Status transition. Volající ověří povolené přechody (state machine).
     * Side-efekty (timestamp pole) tady — booked_at, paid_at, cancelled_at.
     */
    public function setStatus(int $id, string $newStatus, int $supplierId, ?string $paidDate = null): void
    {
        if (!in_array($newStatus, ['draft', 'received', 'booked', 'paid', 'cancelled'], true)) {
            throw new \InvalidArgumentException("Invalid status: $newStatus");
        }

        $sets = ['status = ?'];
        $params = [$newStatus];

        if ($newStatus === 'booked') {
            $sets[] = 'booked_at = NOW()';
        } elseif ($newStatus === 'paid') {
            $sets[] = 'paid_at = ?';
            $params[] = $paidDate ?? date('Y-m-d');
        } elseif ($newStatus === 'cancelled') {
            $sets[] = 'cancelled_at = NOW()';
        } elseif ($newStatus === 'received') {
            // Reverse transition (paid→received / cancelled→received) — vyčisti timestamp
            // odpovídajícího "exit" stavu, aby data byla konzistentní.
            $sets[] = 'paid_at = NULL';
            $sets[] = 'cancelled_at = NULL';
        }

        $params[] = $id;
        $params[] = $supplierId;

        $sql = 'UPDATE purchase_invoices SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ?';
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Propojí finální fakturu ($finalId) se zálohou ($advanceId). Vazba se ukládá
     * NA FINÁLNÍ fakturu (advance_purchase_invoice_id), 1:1 (UNIQUE index).
     *
     * Validace: oba doklady patří tenantovi, $advanceId je advance, $finalId NENÍ
     * advance, a oba mají stejného dodavatele. Pokud finální nemá vyplněnou zálohu
     * (advance_paid_amount = 0), doplní ji = total_with_vat zálohy, aby amount_to_pay
     * ukázal zbývající úhradu. Návrh AI (advance_link_suggested_id) se zároveň vyčistí.
     *
     * @throws \RuntimeException při porušení validace
     */
    public function linkAdvance(int $finalId, int $advanceId, int $supplierId): void
    {
        if ($finalId === $advanceId) {
            throw new \RuntimeException('Nelze propojit doklad sám se sebou.');
        }
        $final   = $this->find($finalId, $supplierId);
        $advance = $this->find($advanceId, $supplierId);
        if ($final === null || $advance === null) {
            throw new \RuntimeException('Doklad nenalezen.');
        }
        if (($advance['document_kind'] ?? '') !== 'advance') {
            throw new \RuntimeException('Propojit lze jen se zálohovou fakturou (advance).');
        }
        if (($final['document_kind'] ?? '') === 'advance') {
            throw new \RuntimeException('Zálohu nelze vyúčtovávat jinou zálohou.');
        }
        if ((int) $final['vendor_id'] !== (int) $advance['vendor_id']) {
            throw new \RuntimeException('Záloha i finální faktura musí být od stejného dodavatele.');
        }

        $advanceTotal = (float) $advance['total_with_vat'];
        $setAdvancePaid = ((float) ($final['advance_paid_amount'] ?? 0)) == 0.0;

        $sql = 'UPDATE purchase_invoices
                   SET advance_purchase_invoice_id = ?, advance_link_suggested_id = NULL'
             . ($setAdvancePaid ? ', advance_paid_amount = ?' : '')
             . ' WHERE id = ? AND supplier_id = ?';
        $params = $setAdvancePaid
            ? [$advanceId, $advanceTotal, $finalId, $supplierId]
            : [$advanceId, $finalId, $supplierId];
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /** Zruší propojení finální faktury se zálohou (advance_paid_amount ponecháme — ruční korekce). */
    public function unlinkAdvance(int $finalId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_purchase_invoice_id = NULL
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$finalId, $supplierId]);
    }

    /** Uloží AI návrh propojení se zálohou (suggest & confirm) — neaplikuje vazbu. */
    public function suggestAdvanceLink(int $finalId, int $advanceId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_link_suggested_id = ?
                        WHERE id = ? AND supplier_id = ? AND advance_purchase_invoice_id IS NULL')
            ->execute([$advanceId, $finalId, $supplierId]);
    }

    /** Zahodí AI návrh propojení. */
    public function dismissAdvanceSuggestion(int $finalId, int $supplierId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET advance_link_suggested_id = NULL
                        WHERE id = ? AND supplier_id = ?')
            ->execute([$finalId, $supplierId]);
    }

    /**
     * Kandidáti k propojení: nespárované zálohy (document_kind='advance') stejného
     * dodavatele jako finální faktura $finalId, které ještě nejsou navázané na žádnou
     * finální fakturu. Seřazené od nejnovějších.
     *
     * @return list<array<string,mixed>>
     */
    public function advanceCandidates(int $finalId, int $supplierId): array
    {
        $final = $this->find($finalId, $supplierId);
        if ($final === null) return [];
        // Řazení: nejdřív stejná měna, pak nejbližší HRUBÁ částka (total_with_vat) k
        // finální faktuře — záloha bývá ve výši celé/části faktury. Porovnáváme proti
        // total_with_vat (před odečtem zálohy), NE amount_to_pay (to bývá 0, když je
        // faktura už uhrazená zálohou). Nakonec nejnovější.
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.vendor_id = ?
                AND pi.document_kind = 'advance'
                AND pi.status != 'cancelled'
                AND pi.id <> ?
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                 WHERE s.advance_purchase_invoice_id = pi.id)
              ORDER BY (pi.currency_id = ?) DESC,
                       ABS(pi.total_with_vat - ?) ASC,
                       pi.issue_date DESC, pi.id DESC
              LIMIT 50"
        );
        $stmt->execute([
            $supplierId, (int) $final['vendor_id'], $finalId,
            (int) $final['currency_id'], (float) $final['total_with_vat'],
        ]);
        return array_map(fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => (string) $r['document_kind'],
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Opačný směr párování — z detailu zálohy ($advanceId) nabídne nepropojené finální
     * faktury (document_kind != 'advance', bez advance_purchase_invoice_id) stejného
     * dodavatele. Vlastní propojení proběhne přes linkAdvance($finalId, $advanceId).
     * Řazení: stejná měna → nejbližší hrubá částka → nejnovější.
     *
     * @return list<array<string,mixed>>
     */
    public function settlementCandidates(int $advanceId, int $supplierId): array
    {
        $advance = $this->find($advanceId, $supplierId);
        if ($advance === null) return [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat, cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.vendor_id = ?
                AND pi.document_kind != 'advance'
                AND pi.status != 'cancelled'
                AND pi.advance_purchase_invoice_id IS NULL
                AND pi.id <> ?
              ORDER BY (pi.currency_id = ?) DESC,
                       ABS(pi.total_with_vat - ?) ASC,
                       pi.issue_date DESC, pi.id DESC
              LIMIT 50"
        );
        $stmt->execute([
            $supplierId, (int) $advance['vendor_id'], $advanceId,
            (int) $advance['currency_id'], (float) $advance['total_with_vat'],
        ]);
        return array_map(fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => (string) $r['document_kind'],
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Rychlé hledání přijatých faktur podle čísla dokladu (naše varsymbol nebo číslo
     * dodavatele) pro globální search box. Malý limit (dropdown).
     *
     * @return list<array{id:int, varsymbol:?string, vendor_invoice_number:?string,
     *   document_kind:?string, status:string, issue_date:?string, total_with_vat:float,
     *   currency:string, company_name:string}>
     */
    public function searchQuick(string $q, int $supplierId, int $limit = 6): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $esc = addcslashes($q, '%_\\');
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                    pi.status, pi.issue_date, pi.total_with_vat,
                    COALESCE(cur.code, 'CZK') AS currency, c.company_name
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND (pi.varsymbol LIKE ? OR pi.vendor_invoice_number LIKE ?)
              ORDER BY pi.issue_date DESC, pi.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$supplierId, '%' . $esc . '%', '%' . $esc . '%']);
        return array_map(static fn (array $r) => [
            'id'                    => (int) $r['id'],
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'document_kind'         => $r['document_kind'] !== null ? (string) $r['document_kind'] : null,
            'status'                => (string) $r['status'],
            'issue_date'            => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat'        => (float) $r['total_with_vat'],
            'currency'              => (string) $r['currency'],
            'company_name'          => (string) $r['company_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Najde nespárovanou zálohu (advance) téhož dodavatele, jejíž číslo dokladu nebo
     * variabilní symbol odpovídá odkazu z faktury (např. "zaplaceno zálohou č. X").
     * Porovnává bez mezer (variabilní symbol může být na dokladu rozdělený). Vrací
     * id pro AI návrh propojení, nebo null. Konzervativní (přesná shoda) — návrh
     * uživatel stejně potvrzuje.
     */
    public function findAdvanceByReference(int $supplierId, int $vendorId, string $reference): ?int
    {
        $norm = preg_replace('/\s+/', '', trim($reference)) ?? '';
        if ($norm === '') return null;
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.vendor_id = ?
                AND pi.document_kind = 'advance'
                AND pi.status != 'cancelled'
                AND (REPLACE(COALESCE(pi.vendor_invoice_number,''), ' ', '') = ?
                  OR REPLACE(COALESCE(pi.varsymbol,''), ' ', '') = ?)
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices s
                                 WHERE s.advance_purchase_invoice_id = pi.id)
              ORDER BY pi.issue_date DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $vendorId, $norm, $norm]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Maximální počet pokusů přeskočit obsazené interní číslo (poslední pojistka). */
    private const MAX_VARSYMBOL_SKIP = 1000;

    /**
     * Vestavěná výchozí šablona interního čísla přijaté faktury (= dosavadní chování).
     * {PP}=daňový prefix, {YY}{MM}=období, {CCC}=čítač → např. PF2602001.
     */
    public const PURCHASE_DEFAULT_TEMPLATE = '{PP}{YY}{MM}{CCC}';

    /**
     * Vygeneruje další interní číslo přijaté faktury pro tenant + období dle
     * per-supplier šablony (supplier.purchase_invoice_number_format), nebo dle
     * vestavěného defaultu {PP}{YY}{MM}{CCC} (např. PF2602001). Atomicky inkrementuje
     * counter (INSERT … ON DUPLICATE KEY).
     *
     * Placeholdery šablony: {PP} daňový prefix (PF/PN/KU/KN/NU/NN), {YYYY}/{YY}/{MM}
     * datum, {C+} čítač (padding dle počtu C). Scope čítače plyne ze šablony: má-li
     * {MM} → měsíční řada, jinak {YYYY}/{YY} → roční, jinak jediná řada.
     *
     * Samoopravné (paralela k vydaným, #85/#103): když je counter pozadu za již
     * použitými čísly (ruční číslo „dopředu", import, úprava v DB), vygenerované
     * číslo nevezme — skočí za nejvyšší skutečně použité číslo dané řady a najde
     * první volné. Unique index `uq_pi_supplier_varsymbol` je definitivní pojistka.
     *
     * $period je YYYYMM (období DUZP/vystavení); čítačový klíč se z něj odvodí dle scope.
     */
    public function nextVarsymbol(int $supplierId, ?string $period = null, string $prefix = 'PF'): string
    {
        $period   = $period ?? date('Ym');
        $prefix   = preg_match('/^[A-Z]{2}$/', $prefix) ? $prefix : 'PF';
        $template = $this->purchaseTemplate($supplierId);
        $counterPeriod = $this->purchaseCounterPeriod($template, $period);

        $n        = $this->bumpPurchaseCounter($supplierId, $counterPeriod);
        $rendered = $this->renderPurchaseNumber($template, $prefix, $period, $n);

        // Happy path: counter sedí, číslo je volné.
        if (!$this->purchaseVarsymbolExists($supplierId, $rendered)) {
            return $rendered;
        }

        // Counter pozadu → skoč rovnou za nejvyšší použité číslo řady, pak dolaď mezery.
        $highest = $this->highestUsedPurchaseCounter($supplierId, $template, $period);
        if ($highest >= $n) {
            $n        = $this->liftPurchaseCounterTo($supplierId, $counterPeriod, $highest + 1);
            $rendered = $this->renderPurchaseNumber($template, $prefix, $period, $n);
        }

        $attempts = 0;
        while ($this->purchaseVarsymbolExists($supplierId, $rendered)) {
            if (++$attempts > self::MAX_VARSYMBOL_SKIP) {
                throw new \RuntimeException(
                    'Nepodařilo se najít volné interní číslo přijaté faktury ani po '
                    . self::MAX_VARSYMBOL_SKIP . " pokusech (období {$period}). Zadej číslo ručně."
                );
            }
            $n        = $this->bumpPurchaseCounter($supplierId, $counterPeriod);
            $rendered = $this->renderPurchaseNumber($template, $prefix, $period, $n);
        }

        return $rendered;
    }

    /** Per-supplier šablona interního čísla přijaté faktury, nebo vestavěný default. */
    private function purchaseTemplate(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT purchase_invoice_number_format FROM supplier WHERE id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        $t = trim((string) ($stmt->fetchColumn() ?: ''));
        return $t !== '' ? $t : self::PURCHASE_DEFAULT_TEMPLATE;
    }

    /** Vyrenderuje číslo ze šablony: {PP} prefix, {YYYY}/{YY}/{MM} z období, {C+} čítač. */
    private function renderPurchaseNumber(string $template, string $prefix, string $period, int $counter): string
    {
        $out = strtr($template, [
            '{PP}'   => $prefix,
            '{YYYY}' => substr($period, 0, 4),
            '{YY}'   => substr($period, 2, 2),
            '{MM}'   => substr($period, 4, 2),
        ]);
        return preg_replace_callback('/\{(C+)\}/', static function (array $m) use ($counter): string {
            return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $out) ?? $out;
    }

    /** Klíč čítače dle scope šablony: měsíční (YYYYMM) / roční (YYYY) / jediná řada (ALL). */
    private function purchaseCounterPeriod(string $template, string $period): string
    {
        if (str_contains($template, '{MM}')) {
            return $period; // YYYYMM
        }
        if (str_contains($template, '{YYYY}') || str_contains($template, '{YY}')) {
            return substr($period, 0, 4); // YYYY
        }
        return 'ALL';
    }

    /** Atomický increment counteru období; vrací novou hodnotu (≥1). */
    private function bumpPurchaseCounter(int $supplierId, string $period): int
    {
        $pdo = $this->db->pdo();
        // LAST_INSERT_ID(expr) vrátí nově nastavenou hodnotu i při UPDATE větvi (MariaDB).
        $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        )->execute([$supplierId, $period]);
        $n = (int) $pdo->lastInsertId();
        return $n === 0 ? 1 : $n;
    }

    private function purchaseVarsymbolExists(int $supplierId, string $varsymbol): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $varsymbol]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Nejvyšší čítač mezi přijatými fakturami daného období, jejichž interní číslo
     * odpovídá šabloně po dosazení data ({PP} = libovolný 2písmenný prefix → čítač
     * se počítá napříč daňovými typy). 0 = žádná shoda. Jen zrychlený skok —
     * korektnost garantuje exact-match smyčka v nextVarsymbol().
     */
    private function highestUsedPurchaseCounter(int $supplierId, string $template, string $period): int
    {
        [$regex, $likePrefix] = $this->buildPurchaseMatcher($template, $period);
        if ($regex === null) {
            return 0;
        }
        $like = $likePrefix . '%';
        $stmt = $this->db->pdo()->prepare(
            "SELECT varsymbol FROM purchase_invoices
              WHERE supplier_id = ? AND varsymbol IS NOT NULL AND varsymbol <> '' AND varsymbol LIKE ?"
        );
        $stmt->execute([$supplierId, $like]);

        $max = 0;
        while (($vs = $stmt->fetchColumn()) !== false) {
            if (preg_match($regex, (string) $vs, $m)) {
                $val = (int) $m[1];
                if ($val > $max) {
                    $max = $val;
                }
            }
        }
        return $max;
    }

    /**
     * Postaví [PCRE regex, LIKE prefix] pro zpětné vyparsování čítače z interního čísla.
     * Datumové placeholdery se dosadí konkrétně, {PP} → [A-Z]{2}, {C+} → (\d+).
     * LIKE prefix = literály (+ '__' za {PP}) až po první {C+} pro zúžení skenu.
     *
     * @return array{0: ?string, 1: string}  [regex nebo null (šablona bez čítače), likePrefix]
     */
    private function buildPurchaseMatcher(string $template, string $period): array
    {
        if (!preg_match('/\{C+\}/', $template)) {
            return [null, ''];
        }
        $withDate = strtr($template, [
            '{YYYY}' => substr($period, 0, 4),
            '{YY}'   => substr($period, 2, 2),
            '{MM}'   => substr($period, 4, 2),
        ]);
        // Sentinely mimo regex/LIKE escaping.
        $marked = str_replace('{PP}', "\x00P\x00", $withDate);
        $marked = preg_replace('/\{C+\}/', "\x00C\x00", $marked) ?? $marked;
        $parts  = preg_split('/(\x00P\x00|\x00C\x00)/', $marked, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $regex = '';
        $likePrefix = '';
        $beforeCounter = true;
        foreach ($parts as $p) {
            if ($p === "\x00P\x00") {
                $regex .= '[A-Z]{2}';
                if ($beforeCounter) {
                    $likePrefix .= '__';
                }
            } elseif ($p === "\x00C\x00") {
                $regex .= '(\d+)';
                $beforeCounter = false;
            } elseif ($p !== '') {
                $regex .= preg_quote($p, '/');
                if ($beforeCounter) {
                    $likePrefix .= $this->escapeLikePurchase($p);
                }
            }
        }
        return ['/^' . $regex . '$/', $likePrefix];
    }

    /** Escapuje znaky se zvláštním významem v LIKE (% _ \). */
    private function escapeLikePurchase(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Zvedne counter období na minimálně $value (GREATEST, nikdy nesnižuje); vrací výslednou hodnotu. */
    private function liftPurchaseCounterTo(int $supplierId, string $period, int $value): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))'
        )->execute([$supplierId, $period, $value]);
        $sel = $pdo->prepare(
            'SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = ? AND period = ?'
        );
        $sel->execute([$supplierId, $period]);
        return (int) $sel->fetchColumn();
    }

    /**
     * Po změně daňového uplatnění (vat_deduction / tax_deductible) přepíše daňový
     * PREFIX ({PP}) auto-generovaného interního čísla na ten odpovídající novému
     * typu — číselnou řadu i datum ponechá. Např. PF2602001 → NN2602001.
     *
     * No-op pro: draft (bez čísla), šablonu bez {PP} (pevný prefix, např. legacy
     * 'PF-…'), ručně zadaná / cizí čísla (neodpovídají šabloně) a když prefix sedí.
     */
    public function reprefixVarsymbol(int $id, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT varsymbol, vat_deduction, tax_deductible FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return;

        $vs = (string) ($row['varsymbol'] ?? '');
        if ($vs === '') return; // draft / bez čísla

        $template = $this->purchaseTemplate($supplierId);
        // Bez {PP} se daňový prefix v čísle nevyskytuje → není co přepisovat (např. legacy 'PF-…').
        if (!str_contains($template, '{PP}')) return;

        $expected = self::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
        $newVs = $this->swapTemplatePrefix($template, $vs, $expected);
        if ($newVs === null || $newVs === $vs) return; // ruční / cizí číslo, nebo prefix už sedí

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$newVs, $id, $supplierId]);
    }

    /**
     * Nahradí daňový prefix ({PP}) v interním čísle dle šablony za $newPrefix, ostatní
     * segmenty (datum, čítač, literály) zachová. Vrací null, když číslo neodpovídá
     * struktuře šablony (ruční / cizí číslo). Date-agnostické.
     */
    private function swapTemplatePrefix(string $template, string $varsymbol, string $newPrefix): ?string
    {
        $tokens = preg_split('/(\{PP\}|\{YYYY\}|\{YY\}|\{MM\}|\{C+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex  = '';
        foreach ($tokens as $tok) {
            $regex .= match (true) {
                $tok === '{PP}'                       => '(?<pp>[A-Z]{2})',
                $tok === '{YYYY}'                     => '\d{4}',
                $tok === '{YY}', $tok === '{MM}'      => '\d{2}',
                (bool) preg_match('/^\{C+\}$/', $tok) => '\d+',
                $tok === ''                           => '',
                default                               => preg_quote($tok, '/'),
            };
        }
        if (!preg_match('/^' . $regex . '$/', $varsymbol, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        [$pp, $offset] = $m['pp'];
        if ($pp === $newPrefix) {
            return $varsymbol;
        }
        return substr($varsymbol, 0, $offset) . $newPrefix . substr($varsymbol, $offset + strlen($pp));
    }

    /**
     * Prefix interního čísla přijaté faktury podle daňového typu:
     *   plný nárok   → PF (uznatelný) / PN (neuznatelný)
     *   krácený §75  → KU / KN
     *   bez nároku   → NU / NN
     */
    public static function varsymbolPrefix(string $vatDeduction, bool $taxDeductible): string
    {
        return match ($vatDeduction) {
            'none'         => $taxDeductible ? 'NU' : 'NN',
            'proportional' => $taxDeductible ? 'KU' : 'KN',
            default        => $taxDeductible ? 'PF' : 'PN',
        };
    }

    /**
     * Přiřadí varsymbol fakture, pokud ho nemá. Idempotentní — pokud už ho má, nedělá nic.
     */
    public function ensureVarsymbol(int $id, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT varsymbol, issue_date, vat_deduction, tax_deductible FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Purchase invoice #$id not found.");
        }
        if (!empty($row['varsymbol'])) {
            return (string) $row['varsymbol'];
        }

        $period = date('Ym', strtotime((string) $row['issue_date']));
        $prefix = self::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
        $varsymbol = $this->nextVarsymbol($supplierId, $period, $prefix);

        $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$varsymbol, $id, $supplierId]);
        return $varsymbol;
    }

    /**
     * Update totálů z items (volá PurchaseInvoiceCalculator).
     */
    /** Update jen rounding pole (volá AI import po extract). */
    public function setRounding(int $id, int $supplierId, float $rounding): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET rounding = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$rounding, $id, $supplierId]);
    }

    /**
     * Uloží (nebo vyčistí) ruční rekapitulaci DPH dle dokladu (§ 73 ZDPH).
     * Sanitizuje vstup na list `{rate, base, vat}` (čísla zaokrouhlená na 2 des. místa);
     * prázdné/`null` → NULL (žádný override, kalkulátor počítá standardně).
     *
     * @param list<array{rate?: float|int, base?: float|int|null, vat?: float|int|null}>|null $overrides
     */
    public function setVatOverrides(int $id, int $supplierId, ?array $overrides): void
    {
        $clean = [];
        foreach ($overrides ?? [] as $o) {
            if (!is_array($o) || !isset($o['rate']) || !is_numeric($o['rate'])) {
                continue;
            }
            $entry = ['rate' => round((float) $o['rate'], 2)];
            if (array_key_exists('base', $o) && $o['base'] !== null && is_numeric($o['base'])) {
                $entry['base'] = round((float) $o['base'], 2);
            }
            if (array_key_exists('vat', $o) && $o['vat'] !== null && is_numeric($o['vat'])) {
                $entry['vat'] = round((float) $o['vat'], 2);
            }
            // Override bez base i vat nemá smysl (= žádná změna pro tu sazbu).
            if (array_key_exists('base', $entry) || array_key_exists('vat', $entry)) {
                $clean[] = $entry;
            }
        }
        $json = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET vat_overrides = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$json, $id, $supplierId]);
    }

    /**
     * Zapíše (nebo vyčistí) diagnostický popis problému z AI extrakce.
     * UI ho zobrazí jako žluté upozornění, aby si uživatel data ověřil
     * (typicky: AI sečetla subtotaly jako další položky).
     */
    public function setExtractionWarning(int $id, int $supplierId, ?string $warning): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET extraction_warning = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$warning, $id, $supplierId]);
    }

    /**
     * Přidá další varování k existujícímu (oddělené prázdným řádkem) místo přepsání.
     * Prázdná faktura → nastaví jen nové; prázdný vstup → no-op. Pro importéry, které
     * mohou přidat varování (rekapitulace DPH) vedle už existujícího (AI mismatch).
     */
    public function appendExtractionWarning(int $id, int $supplierId, string $warning): void
    {
        $warning = trim($warning);
        if ($warning === '') {
            return;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT extraction_warning FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $current = $stmt->fetchColumn();
        $combined = ($current === false || $current === null || trim((string) $current) === '')
            ? $warning
            : rtrim((string) $current) . "\n\n" . $warning;
        $pdo->prepare(
            'UPDATE purchase_invoices SET extraction_warning = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$combined, $id, $supplierId]);
    }

    public function updateTotals(int $id, float $withoutVat, float $vat, float $withVat, float $rounding): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoices
                          SET total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = ?
                        WHERE id = ?')
            ->execute([$withoutVat, $vat, $withVat, $rounding, $id]);
    }

    /**
     * Vrátí ID faktury s daným pdf_hash u tenanta, nebo null. Pro dedup při PDF uploadu / inbox scanu.
     */
    public function findIdByPdfHash(int $supplierId, string $sha256): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices WHERE supplier_id = ? AND pdf_hash = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $sha256]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Vrátí ID faktury s daným vendor_invoice_number u (tenant, vendor, issue_date) tuple,
     * nebo null pokud neexistuje. Respektuje UNIQUE KEY uq_pi_vendor_invoice — caller
     * tím detekuje "tahle faktura už je v systému" před voláním createDraft (které by
     * jinak hodilo SQLSTATE 23000 duplicate key).
     */
    public function findIdByVendorInvoice(int $supplierId, int $vendorId, string $vendorInvoiceNumber, string $issueDate): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ?
                AND vendor_invoice_number = ? AND issue_date = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $vendorId, $vendorInvoiceNumber, $issueDate]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Set archived PDF metadata po úspěšném uložení souboru na disk.
     */
    public function setPdfMetadata(int $id, int $supplierId, string $path, string $hash, int $size, ?string $originalName): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET pdf_path = ?, pdf_hash = ?, pdf_size_bytes = ?, pdf_original_name = ?, pdf_uploaded_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        )->execute([$path, $hash, $size, $originalName, $id, $supplierId]);
    }

    /**
     * Zápis metadat ZDROJOVÉHO artefaktu (strojový originál — ISDOC/ISDOCX/…).
     * Write-once: `AND source_path IS NULL` zaručí, že re-import / re-extrakce
     * nepřepíše evidenční stopu (kterou už jednou uloženou nesmíme měnit).
     */
    public function setSourceMetadata(int $id, int $supplierId, string $path, string $hash, int $size, ?string $originalName, string $format): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices
                SET source_path = ?, source_hash = ?, source_size_bytes = ?, source_original_name = ?,
                    source_format = ?, source_uploaded_at = NOW()
              WHERE id = ? AND supplier_id = ? AND source_path IS NULL'
        )->execute([$path, $hash, $size, $originalName, $format, $id, $supplierId]);
    }

    /**
     * Update totals na úrovni jedné položky (volá Calculator).
     */
    public function updateItemTotals(int $itemId, float $withoutVat, float $vatAmount, float $withVat): void
    {
        $this->db->pdo()
            ->prepare('UPDATE purchase_invoice_items
                          SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
                        WHERE id = ?')
            ->execute([$withoutVat, $vatAmount, $withVat, $itemId]);
    }

    /**
     * @return array<int, float> map [vat_rate_id => rate_percent]
     */
    public function vatRateMap(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * Postaví vendor_snapshot z aktuálního stavu clients row.
     */
    private function buildVendorSnapshot(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.company_name, c.first_name, c.last_name, c.ic, c.dic, c.tax_number,
                    c.street, c.city, c.zip, c.main_email, c.phone, c.language,
                    co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c
               JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?'
        );
        $stmt->execute([$vendorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? ['id' => $vendorId] : $row;
    }

    /**
     * Group items by vat rate for breakdown table.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function buildVatBreakdown(array $items): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
            $key = number_format($rate, 2, '.', '');
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'vat_rate'    => $rate,
                    'without_vat' => 0.0,
                    'vat'         => 0.0,
                    'with_vat'    => 0.0,
                ];
            }
            $buckets[$key]['without_vat'] += (float) ($item['total_without_vat'] ?? 0);
            $buckets[$key]['vat']         += (float) ($item['total_vat'] ?? 0);
            $buckets[$key]['with_vat']    += (float) ($item['total_with_vat'] ?? 0);
        }
        ksort($buckets);
        return array_values($buckets);
    }

    private function castInvoice(array $row): array
    {
        foreach (['id', 'supplier_id', 'vendor_id', 'currency_id', 'payment_currency_id',
                  'created_by', 'pdf_size_bytes', 'source_size_bytes', 'expense_category_id',
                  'advance_purchase_invoice_id', 'advance_link_suggested_id'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null) $row[$f] = (int) $row[$f];
        }
        $row['reverse_charge'] = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        $row['prices_include_vat'] = isset($row['prices_include_vat']) ? (bool) $row['prices_include_vat'] : false;
        $row['is_fixed_asset'] = isset($row['is_fixed_asset']) ? (bool) $row['is_fixed_asset'] : false;
        $row['tax_deductible'] = !array_key_exists('tax_deductible', $row) || (bool) $row['tax_deductible'];
        $vatDeduction = (string) ($row['vat_deduction'] ?? '');
        $row['vat_deduction'] = in_array($vatDeduction, ['full', 'none', 'proportional'], true) ? $vatDeduction : 'full';
        $row['vat_deduction_percent'] = isset($row['vat_deduction_percent']) ? (float) $row['vat_deduction_percent'] : 100.0;
        foreach ([
            'total_without_vat', 'total_vat', 'total_with_vat', 'rounding',
            'advance_paid_amount', 'amount_to_pay',
            'exchange_rate', 'payment_exchange_rate',
            'paid_amount_payment_ccy', 'paid_amount_invoice_ccy', 'exchange_diff_base',
        ] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) $row[$f] = (float) $row[$f];
        }
        // Decode JSON snapshots (DB column je longtext, ne JSON type)
        foreach (['vendor_snapshot', 'own_snapshot'] as $f) {
            if (isset($row[$f]) && is_string($row[$f]) && $row[$f] !== '') {
                $decoded = json_decode($row[$f], true);
                if (is_array($decoded)) $row[$f] = $decoded;
            }
        }
        // Ruční rekapitulace DPH dle dokladu (§ 73). NULL/prázdné → null (žádný override).
        if (array_key_exists('vat_overrides', $row)) {
            $raw = $row['vat_overrides'];
            $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
            $row['vat_overrides'] = (is_array($decoded) && $decoded !== []) ? $decoded : null;
        }
        return $row;
    }

    private function castItem(array $row): array
    {
        foreach (['id', 'purchase_invoice_id', 'vat_rate_id', 'order_index'] as $f) {
            if (isset($row[$f])) $row[$f] = (int) $row[$f];
        }
        foreach ([
            'quantity', 'unit_price_without_vat', 'vat_rate_snapshot',
            'total_without_vat', 'total_vat', 'total_with_vat',
        ] as $f) {
            if (isset($row[$f])) $row[$f] = (float) $row[$f];
        }
        $row['is_fixed_asset'] = isset($row['is_fixed_asset']) ? (bool) $row['is_fixed_asset'] : false;
        return $row;
    }
}
