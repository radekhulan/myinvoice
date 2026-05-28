<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\CzkRecap;
use PDO;

/**
 * CRUD pro faktury + položky + listing s grupováním po měsících (DUZP).
 *
 * Konvence řazení/grupování:
 *   "month bucket" = COALESCE(tax_date, issue_date) → "YYYY-MM"
 *   pro proformu (tax_date NULL) tedy padá na issue_date
 */
final class InvoiceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT i.*,
                    c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.ic AS client_ic, c.dic AS client_dic,
                    c.language AS client_language,
                    c.reverse_charge AS client_reverse_charge,
                    p.name AS project_name, p.hourly_rate AS project_hourly_rate,
                    p.payment_due_days AS project_payment_due_days,
                    p.project_number AS project_number, p.contract_number AS contract_number,
                    p.requires_work_report_approval AS project_requires_approval,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    cur.label AS currency_label,
                    cur.account_number AS bank_account_number, cur.bank_code AS bank_code,
                    cur.bank_name AS bank_name, cur.iban AS bank_iban, cur.bic AS bank_bic
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN projects p ON p.id = i.project_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->castInvoice($row);
        $row['items'] = $this->itemsFor($id);

        // Fakturační emaily projektu (jen popisky, používané v UI hlavičce)
        if (!empty($row['project_id'])) {
            $stmt2 = $pdo->prepare(
                'SELECT email, label FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt2->execute([(int) $row['project_id']]);
            $row['project_billing_emails'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row['project_billing_emails'] = [];
        }

        // VAT breakdown
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        // Sleva: discount_percent je header zdroj pravdy, discount_amount je KLADNÁ
        // magnituda slevy (= -součet záporných slevových položek item_kind='discount').
        $discountAmount = 0.0;
        foreach ($row['items'] as $it) {
            if (($it['item_kind'] ?? 'standard') === 'discount') {
                $discountAmount -= (float) $it['total_without_vat'];
            }
        }
        $row['totals'] = [
            'without_vat'        => $row['total_without_vat'],
            'vat'                => $row['total_vat'],
            'with_vat'           => $row['total_with_vat'],
            'rounding'           => $row['rounding'],
            'advance_paid_amount'=> $row['advance_paid_amount'],
            'amount_to_pay'      => $row['amount_to_pay'],
            'discount_percent'   => $row['discount_percent'],
            'discount_amount'    => round($discountAmount, 2),
        ];

        // CZK přepočet — jen pokud měna != CZK a faktura má zafixovaný kurz.
        // rate_date není uložené přímo na faktuře (kurz odpovídá issue_date — nebo
        // nejbližšímu dříve dostupnému dni); pro zobrazení použijeme issue_date faktury.
        if (
            !empty($row['exchange_rate'])
            && (string) ($row['currency'] ?? '') !== 'CZK'
        ) {
            $rateDate = (string) ($row['exchange_rate_date'] ?? $row['issue_date']);
            $fallback = $rateDate !== (string) $row['issue_date'];
            $row['czk_recap'] = CzkRecap::build(
                $row['vat_breakdown'],
                (float) $row['exchange_rate'],
                $rateDate,
                $fallback,
            );
        } else {
            $row['czk_recap'] = null;
        }

        return $row;
    }

    /**
     * Zafixuje exchange_rate + exchange_rate_date na faktuře (CZK / 1 jednotka cizí
     * měny + den, ke kterému kurz patří — viz fallback logiku CnbExchangeRateClient).
     * Volá se z ExchangeRateApplier po fetch z ČNB. NULL = vyresetuje (např. při
     * změně na CZK měnu).
     */
    public function setExchangeRate(int $invoiceId, ?float $rate, ?string $rateDate = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET exchange_rate = ?, exchange_rate_date = ? WHERE id = ?'
        )->execute([$rate, $rateDate, $invoiceId]);
    }

    public function itemsFor(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.id, ii.invoice_id, ii.description, ii.quantity, ii.unit,
                    ii.unit_price_without_vat, ii.vat_rate_id, ii.vat_rate_snapshot,
                    ii.total_without_vat, ii.total_vat, ii.total_with_vat,
                    ii.order_index, ii.item_kind, ii.linked_work_report_id,
                    ii.vat_classification_code,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
              WHERE ii.invoice_id = ?
              ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /**
     * Vrátí faktury seskupené po měsíci podle COALESCE(tax_date, issue_date).
     *
     * Output: ['data' => [{month: '2026-04', total_*, count, items: [...]} ...], 'meta' => ...]
     *
     * Pokud je $perPage > 0, vrací jen daný řez řádků (LIMIT/OFFSET); meta obsahuje
     * total/page/per_page/pages. Pro export CSV / sumy přes celý dataset volat s $perPage = 0.
     */
    /**
     * Rychlé hledání vystavených faktur podle čísla dokladu (varsymbol) pro globální
     * search box. Malý limit (dropdown).
     *
     * @return list<array{id:int, varsymbol:?string, invoice_type:string, status:string,
     *                    issue_date:?string, total_with_vat:float, currency:string, company_name:string}>
     */
    public function searchQuick(string $q, int $supplierId, int $limit = 6): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $esc = addcslashes($q, '%_\\');
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date,
                    i.total_with_vat, COALESCE(cur.code, 'CZK') AS currency,
                    c.company_name
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.varsymbol LIKE ?
              ORDER BY i.issue_date DESC, i.id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$supplierId, '%' . $esc . '%']);
        return array_map(static fn (array $r) => [
            'id'             => (int) $r['id'],
            'varsymbol'      => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'invoice_type'   => (string) $r['invoice_type'],
            'status'         => (string) $r['status'],
            'issue_date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
            'total_with_vat' => (float) $r['total_with_vat'],
            'currency'       => (string) $r['currency'],
            'company_name'   => (string) $r['company_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'i.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $place = implode(',', array_fill(0, count($types), '?'));
            $where[] = "i.invoice_type IN ($place)";
            foreach ($types as $t) $params[] = $t;
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "i.status IN ($place)";
            foreach ($statuses as $s) $params[] = $s;
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['project_id'])) {
            $where[] = 'i.project_id = ?';
            $params[] = (int) $filters['project_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(COALESCE(i.tax_date, i.issue_date)) = ?';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(COALESCE(i.tax_date, i.issue_date)) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'COALESCE(i.tax_date, i.issue_date) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'COALESCE(i.tax_date, i.issue_date) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "i.status IN ('issued','sent','reminded')";
            $where[] = 'i.invoice_type IN ("invoice","credit_note")';
        }
        if (!empty($filters['overdue'])) {
            $where[] = "i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()";
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query DoS / nečekanou shodu
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(i.varsymbol LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = null;
        if ($perPage > 0) {
            $cntStmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM invoices i
                   JOIN clients c ON c.id = i.client_id
              LEFT JOIN projects p ON p.id = i.project_id
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE $whereSql"
            );
            $cntStmt->execute($params);
            $total = (int) $cntStmt->fetchColumn();
        }

        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.parent_invoice_id, i.recurring_template_id,
                       i.client_id, i.project_id, i.supplier_id,
                       i.issue_date, i.tax_date, i.due_date,
                       i.currency_id, cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                       i.total_without_vat, i.total_vat, i.total_with_vat,
                       i.advance_paid_amount, i.amount_to_pay,
                       i.status, i.payment_method,
                       i.sent_at, i.last_reminder_at, i.reminder_count,
                       i.paid_at, i.cancelled_at,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       p.requires_work_report_approval AS project_requires_approval,
                       EXISTS (SELECT 1 FROM work_reports wr WHERE wr.invoice_id = i.id) AS has_work_report,
                       DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS month_bucket
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN projects p ON p.id = i.project_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE $whereSql
                 ORDER BY COALESCE(i.tax_date, i.issue_date) DESC, i.id DESC";

        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= " LIMIT ? OFFSET ?";
        }

        // PDO nepodporuje míchání named (:foo) a positional (?) parametrů — vše positional.
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Grupování po měsíci
        $grouped = [];
        foreach ($rows as $row) {
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

            $cur = $row['currency'];
            if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                $grouped[$month]['totals_per_currency'][$cur] = [
                    'currency'    => $cur,
                    'without_vat' => 0.0,
                    'vat'         => 0.0,
                    'with_vat'    => 0.0,
                ];
            }
            // Do obratu počítáme jen vystavené faktury + dobropisy (credit_note má záporné částky → odečte se).
            // Vyloučeno: draft (koncepty), proforma (zálohovky), cancelled (storno), cancellation (interní storno).
            if (in_array($row['status'], ['issued', 'sent', 'reminded', 'paid'], true)
                && in_array($row['invoice_type'], ['invoice', 'credit_note'], true)) {
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += $row['total_without_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += $row['total_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += $row['total_with_vat'];
            }
        }

        // Round totals
        foreach ($grouped as &$m) {
            foreach ($m['totals_per_currency'] as &$t) {
                $t['without_vat'] = round($t['without_vat'], 2);
                $t['vat']         = round($t['vat'], 2);
                $t['with_vat']    = round($t['with_vat'], 2);
            }
            $m['totals_per_currency'] = array_values($m['totals_per_currency']);
        }
        unset($m, $t);

        $meta = ['total' => $total ?? count($rows)];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return [
            'data' => array_values($grouped),
            'meta' => $meta,
        ];
    }

    public function createDraft(array $data, int $userId): int
    {
        $pdo = $this->db->pdo();

        // Supplier_id se odvodí z client (immutable per client)
        $clientId = (int) $data['client_id'];
        $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $supplierId = (int) $stmt->fetchColumn();
        if ($supplierId === 0) {
            throw new \InvalidArgumentException("Client #$clientId nenalezen.");
        }

        // Volitelný ručně zadaný varsymbol (override automatického číslování při issue).
        // Trim + null-if-empty, max 20 znaků (DB sloupec varsymbol VARCHAR(20)).
        $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
        if ($manualVarsymbol === '') {
            $manualVarsymbol = null;
        } elseif (strlen($manualVarsymbol) > 20) {
            throw new \InvalidArgumentException('varsymbol má max 20 znaků');
        }

        $paymentMethod = (string) ($data['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, ['bank_transfer', 'card', 'cash', 'other'], true)) {
            $paymentMethod = 'bank_transfer';
        }

        $sql = 'INSERT INTO invoices
            (invoice_type, parent_invoice_id, client_id, project_id, supplier_id,
             issue_date, tax_date, due_date, currency_id, reverse_charge, language,
             note_above_items, note_below_items, advance_paid_amount, discount_percent, varsymbol,
             payment_method, status, vat_classification_code, revenue_category, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            (string) ($data['invoice_type'] ?? 'invoice'),
            isset($data['parent_invoice_id']) ? (int) $data['parent_invoice_id'] : null,
            $clientId,
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            $supplierId,
            (string) $data['issue_date'],
            ($data['invoice_type'] ?? 'invoice') === 'proforma' ? null : (string) ($data['tax_date'] ?? $data['issue_date']),
            (string) $data['due_date'],
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            self::clampDiscountPercent($data['discount_percent'] ?? 0),
            $manualVarsymbol,
            $paymentMethod,
            !empty($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            !empty($data['revenue_category']) ? (string) $data['revenue_category'] : null,
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateDraft(int $id, array $data): void
    {
        // Varsymbol — měníme jen pokud je v payloadu klíč 'varsymbol' (allow null = vyčištění,
        // missing = nepsát vůbec). UpdateInvoiceAction klíč u vystavené faktury odstraní.
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

        $hasPaymentMethod = array_key_exists('payment_method', $data);
        $paymentMethod = null;
        if ($hasPaymentMethod) {
            $paymentMethod = (string) $data['payment_method'];
            if (!in_array($paymentMethod, ['bank_transfer', 'card', 'cash', 'other'], true)) {
                $paymentMethod = 'bank_transfer';
            }
        }

        $sql = 'UPDATE invoices SET
                client_id = ?, project_id = ?,
                issue_date = ?, tax_date = ?, due_date = ?,
                currency_id = ?, reverse_charge = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?, discount_percent = ?,
                vat_classification_code = ?, revenue_category = ?'
              . ($hasVarsymbol ? ', varsymbol = ?' : '')
              . ($hasPaymentMethod ? ', payment_method = ?' : '')
              . ' WHERE id = ?';

        $params = [
            (int) $data['client_id'],
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            self::clampDiscountPercent($data['discount_percent'] ?? 0),
            !empty($data['vat_classification_code']) ? (string) $data['vat_classification_code'] : null,
            !empty($data['revenue_category']) ? (string) $data['revenue_category'] : null,
        ];
        if ($hasVarsymbol) $params[] = $manualVarsymbol;
        if ($hasPaymentMethod) $params[] = $paymentMethod;
        $params[] = $id;

        $this->db->pdo()->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE smaže invoice_items i work_reports
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    }

    /**
     * Přepíše položky faktury (smaže staré + insertne nové).
     *
     * Pokud má faktura header `discount_percent` > 0, po vložení uživatelských
     * položek se DOPOČÍTÁ záporná slevová položka (item_kind='discount') na každou
     * kombinaci sazba DPH + klasifikační kód — viz `materializeDiscountLines`.
     * Příchozí položky s item_kind='discount' se ignorují (sleva je vždy generovaná
     * z header pole, nikdy se neukládá z UI jako uživatelský řádek → žádné zdvojení).
     */
    public function replaceItems(int $invoiceId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, item_kind, vat_classification_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)'
        );

        $vatRates = $this->loadVatRates();

        // Reverse charge + země klienta + jazyk + sleva — z hlavičky.
        //   Klasifikační kód: CZ klient → '1'/'2'/'3' (tuzemsko podle sazby)
        //                     EU klient s 0% → '22' (služby), non-EU s 0% → '26' (vývoz)
        $metaStmt = $pdo->prepare(
            'SELECT i.reverse_charge, i.discount_percent, i.language, co.iso2
               FROM invoices i
               JOIN clients c    ON c.id  = i.client_id
               JOIN countries co ON co.id = c.country_id
              WHERE i.id = ?'
        );
        $metaStmt->execute([$invoiceId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: ['reverse_charge' => 0, 'discount_percent' => 0, 'language' => 'cs', 'iso2' => 'CZ'];
        $reverseCharge = (bool) $meta['reverse_charge'];
        $countryIso = (string) ($meta['iso2'] ?? 'CZ');
        $discountPercent = self::clampDiscountPercent($meta['discount_percent'] ?? 0);
        $language = (string) ($meta['language'] ?? 'cs');

        // Slevu agregujeme po (vat_rate_id, vat_rate_snapshot, code) — báze = součet
        // round(qty*price, 2) jednotlivých řádků (stejné zaokrouhlení jako InvoiceMath).
        $discountGroups = [];
        $maxOrder = -1;

        foreach (array_values($items) as $i => $item) {
            // Systémové slevové řádky z UI ignorujeme — generují se z header pole níže.
            if (($item['item_kind'] ?? 'standard') === 'discount') {
                continue;
            }
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            // Auto-klasifikace pro DPH přiznání / KH — bez ní by faktura nedorazila
            // do výkazů (VatClassificationMapper SKIPNE řádky s code=NULL).
            $code = $item['vat_classification_code']
                ?? self::defaultSaleClassificationCode($rate, $reverseCharge, $countryIso);
            $orderIndex = (int) ($item['order_index'] ?? $i);
            $stmt->execute([
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                $orderIndex,
                'standard',
                $code !== null ? (string) $code : null,
            ]);

            $maxOrder = max($maxOrder, $orderIndex);
            if ($discountPercent > 0) {
                $base = round((float) ($item['quantity'] ?? 1) * (float) ($item['unit_price_without_vat'] ?? 0), 2);
                $key = $vatRateId . '|' . ($code ?? '');
                if (!isset($discountGroups[$key])) {
                    $discountGroups[$key] = [
                        'vat_rate_id' => $vatRateId,
                        'snapshot'    => $rate,
                        'code'        => $code,
                        'base'        => 0.0,
                    ];
                }
                $discountGroups[$key]['base'] += $base;
            }
        }

        if ($discountPercent > 0 && $discountGroups !== []) {
            $this->materializeDiscountLines($stmt, $invoiceId, $discountPercent, $discountGroups, $maxOrder + 1, $language);
        }
    }

    /**
     * Vloží záporné slevové položky (item_kind='discount') — jednu na každou skupinu
     * (sazba DPH + klasifikační kód). unit_price = -round(báze * pct/100, 2), množství 1.
     * Díky tomu sleva sníží základ i DPH v dané sazbě a propíše se do všech DPH výkazů
     * (sumují invoice_items). Per-sazbu split = nutný pro správné DPH u smíšených sazeb.
     *
     * @param array<string, array{vat_rate_id:int, snapshot:float, code:?string, base:float}> $groups
     */
    private function materializeDiscountLines(
        \PDOStatement $stmt,
        int $invoiceId,
        float $discountPercent,
        array $groups,
        int $startOrder,
        string $language,
    ): void {
        $label = self::discountLabel($discountPercent, $language);
        $order = $startOrder;
        foreach ($groups as $g) {
            $disc = round($g['base'] * $discountPercent / 100.0, 2);
            if ($disc == 0.0) {
                continue;
            }
            $stmt->execute([
                $invoiceId,
                $label,
                1.0,
                '',
                -$disc,
                $g['vat_rate_id'],
                $g['snapshot'],
                $order++,
                'discount',
                $g['code'] !== null ? (string) $g['code'] : null,
            ]);
        }
    }

    /**
     * Lokalizovaný popis slevové položky, např. "Sleva 10 %" / "Discount 10 %".
     * Procenta bez zbytečných nul (10, 12.5).
     */
    public static function discountLabel(float $discountPercent, string $language = 'cs'): string
    {
        $pct = rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.');
        return ($language === 'en' ? 'Discount ' : 'Sleva ') . $pct . ' %';
    }

    /**
     * Ořeže slevu do rozsahu 0–100 % (2 desetinná místa).
     */
    public static function clampDiscountPercent(mixed $value): float
    {
        $v = is_numeric($value) ? (float) $value : 0.0;
        return round(max(0.0, min(100.0, $v)), 2);
    }

    /**
     * Default vat_classification_code podle sazby + RC + země klienta pro VYSTAVENÉ faktury.
     *
     * Mapování:
     *   CZ klient:
     *     21% → '1' (tuzemsko základní)
     *     12% → '2' (tuzemsko snížená)
     *     0%  → '3' (tuzemsko osvobozeno)
     *   EU klient (DE, SK, AT, …):
     *     0%  → '22' (poskytnutí služby do EU, B2B reverse charge — nejčastější CZ IT use case)
     *     21%/12% → tuzemsko sazby (B2C nebo CZ klient s EU adresou)
     *   Non-EU klient:
     *     0%  → '26' (vývoz do 3. země)
     *     jinak tuzemsko sazby
     *
     * Pro dodávky zboží do EU (kód '20') si user musí kód změnit ručně —
     * default rate=0% pro EU mapujeme na služby ('22'), což je častější.
     */
    public static function defaultSaleClassificationCode(
        float $rate,
        bool $reverseCharge,
        ?string $clientCountryIso2 = null,
    ): ?string {
        $r = (int) round($rate);
        $iso = strtoupper((string) ($clientCountryIso2 ?? 'CZ'));
        // EU member states (ISO-2 kódy, bez CZ které je tuzemsko)
        $euCountries = [
            'AT','BE','BG','HR','CY','DK','EE','FI','FR','DE','GR','HU','IE','IT',
            'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
        ];
        $isEu = in_array($iso, $euCountries, true);
        $isForeign = $iso !== 'CZ' && $iso !== '';

        // Zahraniční klient + nulová sazba → EU služby nebo vývoz
        if ($isForeign && $r === 0) {
            return $isEu ? '22' : '26';
        }
        // Tuzemsko / B2C cizinec s českou DPH sazbou
        if ($r >= 21)            return '1';
        if ($r >= 5 && $r <= 15) return '2';
        return '3';
    }

    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * @return array<int, float>
     */
    public function vatRateMap(): array
    {
        return $this->loadVatRates();
    }

    private function castInvoice(array $row): array
    {
        $row['id']                  = (int) $row['id'];
        $row['client_id']           = (int) $row['client_id'];
        $row['project_id']          = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $row['parent_invoice_id']   = isset($row['parent_invoice_id']) && $row['parent_invoice_id'] !== null ? (int) $row['parent_invoice_id'] : null;
        if (array_key_exists('recurring_template_id', $row)) {
            $row['recurring_template_id'] = $row['recurring_template_id'] !== null ? (int) $row['recurring_template_id'] : null;
        }
        if (isset($row['currency_id']))   $row['currency_id'] = (int) $row['currency_id'];
        if (isset($row['supplier_id']))   $row['supplier_id'] = (int) $row['supplier_id'];
        $row['reverse_charge']      = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        foreach (['total_without_vat', 'total_vat', 'total_with_vat', 'rounding', 'advance_paid_amount', 'amount_to_pay', 'discount_percent'] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) $row[$f] = (float) $row[$f];
        }
        if (array_key_exists('exchange_rate', $row)) {
            $row['exchange_rate'] = $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null;
        }
        if (isset($row['client_reverse_charge'])) $row['client_reverse_charge'] = (bool) $row['client_reverse_charge'];
        if (array_key_exists('reminder_count', $row)) $row['reminder_count'] = (int) $row['reminder_count'];
        if (array_key_exists('approval_reminder_count', $row)) {
            $row['approval_reminder_count'] = (int) $row['approval_reminder_count'];
        }
        if (array_key_exists('project_requires_approval', $row)) {
            $row['project_requires_approval'] = $row['project_requires_approval'] !== null
                ? (bool) $row['project_requires_approval']
                : false;
        }
        if (array_key_exists('has_work_report', $row)) {
            $row['has_work_report'] = (bool) $row['has_work_report'];
        }
        return $row;
    }

    /**
     * Vygeneruje a uloží nový approval_token, nastaví status='requested',
     * vyresetuje předchozí decision/reminder pole. TTL je v dnech (cfg.approval.token_ttl_days).
     * Vrací nový token.
     */
    public function setApprovalRequested(int $invoiceId, int $ttlDays = 30): string
    {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $expiresExpr = 'DATE_ADD(NOW(), INTERVAL ' . max(1, $ttlDays) . ' DAY)';
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET approval_status = 'requested',
                    approval_token = ?,
                    approval_token_expires_at = $expiresExpr,
                    approval_requested_at = NOW(),
                    approval_decided_at = NULL,
                    approval_decided_by_email = NULL,
                    approval_rejection_reason = NULL,
                    approval_reminder_at = NULL,
                    approval_reminder_count = 0
              WHERE id = ?"
        )->execute([$token, $invoiceId]);
        return $token;
    }

    /**
     * Uloží rozhodnutí (approved/rejected). $decidedBy = email klienta (z public formu)
     * nebo email aktuálního uživatele (z admin „Změnit stav"). Token zneplatněn.
     */
    public function setApprovalDecision(int $invoiceId, string $newStatus, ?string $decidedBy, ?string $rejectionReason): void
    {
        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Invalid approval status: $newStatus");
        }
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = ?,
                    approval_token = NULL,
                    approval_decided_at = NOW(),
                    approval_decided_by_email = ?,
                    approval_rejection_reason = ?
              WHERE id = ?'
        )->execute([$newStatus, $decidedBy, $rejectionReason, $invoiceId]);
    }

    /**
     * Reset approval na 'none' (pro admin „Změnit stav" → none). Token zneplatněn.
     */
    public function resetApproval(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = "none",
                    approval_token = NULL,
                    approval_token_expires_at = NULL,
                    approval_requested_at = NULL,
                    approval_decided_at = NULL,
                    approval_decided_by_email = NULL,
                    approval_rejection_reason = NULL,
                    approval_reminder_at = NULL,
                    approval_reminder_count = 0
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    /**
     * Najde fakturu podle approval_token. Pokud token expiroval (token_expires_at < NOW()),
     * vrátí null — pro caller je to stejný case jako neexistující token.
     */
    public function findByApprovalToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices
              WHERE approval_token = ?
                AND (approval_token_expires_at IS NULL OR approval_token_expires_at > NOW())'
        );
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if ($id === false) return null;
        return $this->find((int) $id);
    }

    /**
     * Pro admin „Approval inbox" + reminder cron. Vrací requested faktury filtrované
     * podle dní od poslední upomínky/žádosti.
     *
     * @param int|null $supplierId  null = všichni dodavatelé (cron)
     * @param int|null $minDaysSince  minimum dní od poslední aktivity (NULL = bez filtru)
     * @param int|null $maxReminders  filtr: vyber jen ty s reminder_count < limit
     * @return list<array<string,mixed>>
     */
    public function listForApprovalInbox(
        ?int $supplierId = null,
        ?string $statusFilter = null,
        ?int $minDaysSince = null,
        ?int $maxReminders = null,
        int $page = 1,
        int $perPage = 0,
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($supplierId !== null) {
            $where[] = 'i.supplier_id = ?';
            $params[] = $supplierId;
        }

        // Status counts pro tab badges — bez status filtru, ale se supplier scope
        // (+ minDaysSince/maxReminders pokud explicit, takže badge sedí s aplikovanými filtry).
        if ($perPage > 0) {
            $whereForCounts = $where;
            $paramsForCounts = $params;
            $whereForCounts[] = "i.approval_status != 'none'";
            if ($minDaysSince !== null) {
                $whereForCounts[] = 'COALESCE(i.approval_reminder_at, i.approval_requested_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)';
                $paramsForCounts[] = $minDaysSince;
            }
            if ($maxReminders !== null) {
                $whereForCounts[] = 'i.approval_reminder_count < ?';
                $paramsForCounts[] = $maxReminders;
            }
            $whereCountsSql = implode(' AND ', $whereForCounts);
            $stmtCounts = $this->db->pdo()->prepare(
                "SELECT
                    SUM(CASE WHEN i.approval_status = 'requested' THEN 1 ELSE 0 END) AS requested,
                    SUM(CASE WHEN i.approval_status = 'approved'  THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN i.approval_status = 'rejected'  THEN 1 ELSE 0 END) AS rejected,
                    COUNT(*) AS all_count
                 FROM invoices i
                WHERE $whereCountsSql"
            );
            $stmtCounts->execute($paramsForCounts);
            $statusCounts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['requested' => 0, 'approved' => 0, 'rejected' => 0, 'all_count' => 0];
        }

        if ($statusFilter !== null) {
            $where[] = 'i.approval_status = ?';
            $params[] = $statusFilter;
        } else {
            // default = jen non-none (vše co prošlo schvalovacím flow)
            $where[] = "i.approval_status != 'none'";
        }
        if ($minDaysSince !== null) {
            $where[] = 'COALESCE(i.approval_reminder_at, i.approval_requested_at) <= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $minDaysSince;
        }
        if ($maxReminders !== null) {
            $where[] = 'i.approval_reminder_count < ?';
            $params[] = $maxReminders;
        }

        $whereSql = implode(' AND ', $where);
        $limitSql = $perPage > 0 ? ' LIMIT ? OFFSET ?' : '';
        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.supplier_id,
                       i.client_id, i.project_id, i.currency_id, i.language,
                       i.total_with_vat, i.amount_to_pay,
                       i.approval_status, i.approval_token, i.approval_token_expires_at,
                       i.approval_requested_at, i.approval_decided_at,
                       i.approval_decided_by_email, i.approval_rejection_reason,
                       i.approval_reminder_at, i.approval_reminder_count,
                       c.company_name AS client_company_name, c.main_email AS client_main_email,
                       p.name AS project_name,
                       cur.code AS currency
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN projects p ON p.id = i.project_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE $whereSql
                 ORDER BY i.approval_requested_at DESC{$limitSql}";

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) $stmt->bindValue($idx++, $v);
        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(fn (array $r) => $this->castInvoice($r), $rows);

        // BC: bez paginace (perPage=0) cron volá a očekává plochý seznam.
        if ($perPage <= 0) {
            return $items;
        }

        // Total pro aktuální filter (jen v paginated cestě)
        $stmtTotal = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoices i WHERE $whereSql"
        );
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        return [
            'data' => $items,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / max(1, $perPage)),
                'status_counts' => [
                    'all'       => (int) ($statusCounts['all_count'] ?? 0),
                    'requested' => (int) ($statusCounts['requested'] ?? 0),
                    'approved'  => (int) ($statusCounts['approved'] ?? 0),
                    'rejected'  => (int) ($statusCounts['rejected'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Označ že upomínka byla poslána (cron-send-approval-reminders.php).
     */
    public function markApprovalReminderSent(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_reminder_at = NOW(),
                    approval_reminder_count = approval_reminder_count + 1
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    private function castItem(array $row): array
    {
        $row['id']                     = (int) $row['id'];
        $row['invoice_id']             = (int) $row['invoice_id'];
        $row['vat_rate_id']            = (int) $row['vat_rate_id'];
        $row['order_index']            = (int) $row['order_index'];
        $row['quantity']               = (float) $row['quantity'];
        $row['unit_price_without_vat'] = (float) $row['unit_price_without_vat'];
        $row['vat_rate_snapshot']      = (float) $row['vat_rate_snapshot'];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $f) {
            $row[$f] = (float) $row[$f];
        }
        $row['linked_work_report_id'] = $row['linked_work_report_id'] !== null ? (int) $row['linked_work_report_id'] : null;
        $row['item_kind'] = (string) ($row['item_kind'] ?? 'standard');
        return $row;
    }

    private function buildVatBreakdown(array $items): array
    {
        $bd = [];
        foreach ($items as $item) {
            $rate = (float) $item['vat_rate_snapshot'];
            $key = number_format($rate, 2, '.', '');
            if (!isset($bd[$key])) {
                $bd[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
            }
            $bd[$key]['base'] += (float) $item['total_without_vat'];
            $bd[$key]['vat']  += (float) $item['total_vat'];
        }
        $out = [];
        foreach ($bd as $b) {
            $out[] = [
                'rate' => $b['rate'],
                'base' => round($b['base'], 2),
                'vat'  => round($b['vat'], 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        return $out;
    }
}
