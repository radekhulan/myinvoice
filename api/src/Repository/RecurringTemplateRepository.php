<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\PeriodicityCalculator;
use PDO;

/**
 * CRUD pro recurring_invoice_templates + items.
 *
 * Šablona je strukturálně blízká faktuře (client, project, currency, items, ...),
 * ale má navíc periodicitu a chování cronu (auto_issue, auto_send_email).
 *
 * Listing pro cron je v findDue() — vrátí jen aktivní šablony, kde
 * next_run_date <= today A supplier.auto_generate_recurring=1.
 */
final class RecurringTemplateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*,
                    c.company_name AS client_company_name,
                    c.main_email AS client_main_email,
                    c.language AS client_language,
                    p.name AS project_name,
                    cur.code AS currency, cur.symbol AS currency_symbol,
                    rc.label AS revenue_category_label, rc.code AS revenue_category_code
               FROM recurring_invoice_templates t
               JOIN clients c ON c.id = t.client_id
          LEFT JOIN projects p ON p.id = t.project_id
               JOIN currencies cur ON cur.id = t.currency_id
          LEFT JOIN revenue_categories rc ON rc.id = t.revenue_category_id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->cast($row);
        $row['items'] = $this->itemsFor($id);
        return $row;
    }

    public function itemsFor(int $templateId): array
    {
        return $this->itemsForTemplates([$templateId])[$templateId] ?? [];
    }

    /** @param list<int> $templateIds @return array<int,list<array<string,mixed>>> */
    public function itemsForTemplates(array $templateIds): array
    {
        if ($templateIds === []) return [];
        $ids = array_values(array_unique(array_map('intval', $templateIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.template_id, i.price_list_item_id, i.catalog_policy,
                    i.description_source, i.catalog_price_source,
                    i.catalog_source_currency_code, i.catalog_source_unit_price,
                    i.catalog_exchange_rate, i.catalog_exchange_rate_date,
                    i.description, i.quantity, i.unit,
                    i.unit_price_without_vat, i.vat_rate_id, i.order_index,
                    vr.code AS vat_code, vr.rate_percent AS vat_rate_percent,
                    pli.code AS price_list_item_code, pli.name AS price_list_item_name,
                    pli.archived AS price_list_item_archived
               FROM recurring_invoice_template_items i
               JOIN vat_rates vr ON vr.id = i.vat_rate_id
          LEFT JOIN price_list_items pli ON pli.id = i.price_list_item_id
              WHERE i.template_id IN (' . $placeholders . ')
              ORDER BY i.order_index, i.id'
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = $this->castItem($row);
            $out[$item['template_id']][] = $item;
        }
        return $out;
    }

    /**
     * @param array{
     *   supplier_id?: int|null,
     *   client_id?: int|null,
     *   status?: string|null,
     * } $filters
     */
    /**
     * @param int $page  1-based, ignored when $perPage<=0
     * @param int $perPage  0 = bez paginace (returns vše, BC)
     */
    /**
     * @param array<string,mixed> $filters
     * @param string $sort  '' (default: aktivní první + nejbližší vystavení) | 'client' (zákazník A–Z)
     *                       | 'next_run' (datum příštího vystavení) | 'amount_czk' (částka přepočtená na CZK, sestupně)
     * @param array<string,float> $czkRates  kód měny → dnešní kurz na CZK (jen pro sort='amount_czk')
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 0, string $sort = '', array $czkRates = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['supplier_id'])) {
            $where[] = 't.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 't.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }

        // Status counts — bez status filtru, ale se zbylými filtry (supplier + client).
        // Counts pro tab badges; mají reflektovat všechny statusy pro daného supplier/client scope.
        $whereForCounts = implode(' AND ', $where);
        $stmtCounts = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN t.status = 'active'  THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN t.status = 'paused'  THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN t.status = 'expired' THEN 1 ELSE 0 END) AS expired,
                COUNT(*) AS all_templates
             FROM recurring_invoice_templates t
            WHERE $whereForCounts"
        );
        $stmtCounts->execute($params);
        $statusCounts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['active' => 0, 'paused' => 0, 'expired' => 0, 'all_templates' => 0];

        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = (string) $filters['status'];
        }
        $whereSql = implode(' AND ', $where);

        // Total pro aktuální filter
        $stmtTotal = $this->db->pdo()->prepare("SELECT COUNT(*) FROM recurring_invoice_templates t WHERE $whereSql");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $limitSql = $perPage > 0 ? ' LIMIT ? OFFSET ?' : '';

        // Součet faktury šablony (base + DPH, per-line rounding jako InvoiceCalculator),
        // respektuje reverse_charge. Korelovaný subselect přes položky šablony.
        $totalExpr = self::TEMPLATE_TOTAL_SQL;

        $sql = "SELECT t.id, t.supplier_id, t.client_id, t.project_id, t.name,
                       t.frequency, t.day_of_month, t.end_of_month,
                       t.anchor_date, t.end_date, t.next_run_date, t.last_run_date,
                       t.invoice_type, t.currency_id, t.language, t.payment_method,
                       t.reverse_charge, t.prices_include_vat, t.discount_percent, t.revenue_category_id,
                       t.payment_due_days, t.draft_open_mode, t.reminder_days_before,
                       t.auto_issue, t.auto_send_email, t.status,
                       t.last_run_date, t.last_error, t.last_error_at,
                       t.created_at, t.updated_at,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       cur.code AS currency,
                       (SELECT COUNT(*) FROM invoices iv WHERE iv.recurring_template_id = t.id) AS invoices_generated_count,
                       $totalExpr AS total_with_vat
                  FROM recurring_invoice_templates t
                  JOIN clients c ON c.id = t.client_id
             LEFT JOIN projects p ON p.id = t.project_id
                  JOIN currencies cur ON cur.id = t.currency_id
                 WHERE $whereSql
                 ORDER BY {$this->orderBy($sort, $totalExpr, $czkRates)}{$limitSql}";

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

        // Součet částek per měna přes CELOU filtrovanou množinu (ne jen aktuální stránku) —
        // pro souhrn v hlavičce. CZK přepočet řeší Action (potřebuje kurzový service).
        $totStmt = $this->db->pdo()->prepare(
            "SELECT cur.code AS currency, COALESCE(SUM($totalExpr), 0) AS total
               FROM recurring_invoice_templates t
               JOIN currencies cur ON cur.id = t.currency_id
              WHERE $whereSql
              GROUP BY cur.code
              ORDER BY total DESC"
        );
        $totStmt->execute($params);
        $totalsByCurrency = array_map(
            fn ($r) => ['currency' => (string) $r['currency'], 'total' => (float) $r['total']],
            $totStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        return [
            'data' => array_map([$this, 'cast'], $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $perPage > 0 ? $page : 1,
                'per_page' => $perPage > 0 ? $perPage : $total,
                'pages'    => $perPage > 0 ? (int) ceil($total / max(1, $perPage)) : 1,
                'status_counts' => [
                    'all'     => (int) $statusCounts['all_templates'],
                    'active'  => (int) $statusCounts['active'],
                    'paused'  => (int) $statusCounts['paused'],
                    'expired' => (int) $statusCounts['expired'],
                ],
                'totals_by_currency' => $totalsByCurrency,
            ],
        ];
    }

    /**
     * Sestaví ORDER BY pro list(). amount_czk přepočítá částku šablony na CZK přes
     * předané kurzy (ať se nemixuje CZK a cizí měna), ostatní jsou prostá SQL pole.
     *
     * @param array<string,float> $czkRates
     */
    private function orderBy(string $sort, string $totalExpr, array $czkRates): string
    {
        return match ($sort) {
            'client'     => 'c.company_name ASC, t.next_run_date ASC',
            'next_run'   => 't.next_run_date ASC, t.id ASC',
            'amount_czk' => '(' . $totalExpr . ' * ' . $this->czkRateCase($czkRates) . ') DESC, t.next_run_date ASC',
            default      => "t.status = 'active' DESC, t.next_run_date ASC",
        };
    }

    /**
     * `CASE cur.code WHEN 'EUR' THEN 25.30 ... ELSE 1 END` — bezpečně sestaveno
     * (kódy whitelist [A-Z]{3}, kurzy jako float literály). CZK i neznámé měny = 1.
     *
     * @param array<string,float> $czkRates
     */
    private function czkRateCase(array $czkRates): string
    {
        $czkRates['CZK'] = 1.0;
        $parts = [];
        foreach ($czkRates as $code => $rate) {
            $code = strtoupper((string) $code);
            if (!preg_match('/^[A-Z]{3}$/', $code)) continue;
            $parts[] = "WHEN '{$code}' THEN " . sprintf('%.6f', (float) $rate);
        }
        return $parts === [] ? '1' : 'CASE cur.code ' . implode(' ', $parts) . ' ELSE 1 END';
    }

    /**
     * Distinct kódy měn šablon pro daný filtr — Action z nich poskládá CZK kurzy
     * pro sort='amount_czk'.
     *
     * @param array<string,mixed> $filters
     * @return list<string>
     */
    public function distinctCurrencyCodes(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['supplier_id'])) { $where[] = 't.supplier_id = ?'; $params[] = (int) $filters['supplier_id']; }
        if (!empty($filters['client_id']))   { $where[] = 't.client_id = ?';   $params[] = (int) $filters['client_id']; }
        if (!empty($filters['status']))      { $where[] = 't.status = ?';      $params[] = (string) $filters['status']; }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT cur.code
               FROM recurring_invoice_templates t
               JOIN currencies cur ON cur.id = t.currency_id
              WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * SQL výraz: součet faktury šablony `t` (celkem s DPH, per-line rounding,
     * respektuje t.reverse_charge i t.prices_include_vat). Použit v list() pro
     * per-řádek i agregaci.
     *
     * Režim „ceny s DPH" (t.prices_include_vat=1): unit_price_without_vat nese BRUTTO,
     * takže řádkový součet s DPH = round(qty*unit_price,2) a NEpřičítáme DPH navrch
     * (ta je už uvnitř). Jinak (zdola) přičteme DPH z báze (mimo reverse charge).
     */
    private const TEMPLATE_TOTAL_SQL =
        "((SELECT COALESCE(SUM(
                    ROUND(ri.quantity * ri.unit_price_without_vat, 2)
                  + CASE WHEN t.prices_include_vat = 1 OR t.reverse_charge = 1 THEN 0
                         ELSE ROUND(ROUND(ri.quantity * ri.unit_price_without_vat, 2) * vr.rate_percent / 100, 2) END
                 ), 0)
            FROM recurring_invoice_template_items ri
            JOIN vat_rates vr ON vr.id = ri.vat_rate_id
           WHERE ri.template_id = t.id) * (100 - t.discount_percent) / 100)";

    /**
     * Načte šablony, u kterých má dnes cron něco udělat — jsou aktivní a jejich
     * supplier má zapnutý kill-switch auto_generate_recurring, a navíc buď:
     *   - next_run_date <= today (čas vystavit / legacy generovat), NEBO
     *   - draft_open_mode='period_start' A už začalo fakturované období
     *     (1. den měsíce next_run_date <= today) → čas otevřít koncept.
     *
     * Cron pak dle draft_open_mode a datumů rozhodne open vs. issue.
     */
    public function findDue(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT t.id
               FROM recurring_invoice_templates t
               JOIN supplier s ON s.id = t.supplier_id
              WHERE t.status = 'active'
                AND (t.end_date IS NULL OR t.next_run_date <= t.end_date)
                AND s.auto_generate_recurring = 1
                AND (
                      t.next_run_date <= CURDATE()
                   OR (t.draft_open_mode = 'period_start'
                       AND DATE_FORMAT(t.next_run_date, '%Y-%m-01') <= CURDATE())
                )
              ORDER BY t.next_run_date ASC, t.id ASC"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $out = [];
        foreach ($ids as $id) {
            $tpl = $this->find($id);
            if ($tpl !== null) $out[] = $tpl;
        }
        return $out;
    }

    /**
     * Faktura vygenerovaná z této šablony pro dané období (klíč = issue_date,
     * který se rovná plánovanému next_run_date období). Slouží k idempotenci
     * openDraft() a k nalezení otevřeného konceptu v issuePeriod().
     *
     * @return array{id:int, status:string, varsymbol:?string}|null
     */
    public function findPeriodInvoice(int $templateId, string $issueDate): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, varsymbol
               FROM invoices
              WHERE recurring_template_id = ? AND issue_date = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$templateId, $issueDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'id'        => (int) $row['id'],
            'status'    => (string) $row['status'],
            'varsymbol' => $row['varsymbol'] !== null ? (string) $row['varsymbol'] : null,
        ];
    }

    /**
     * Šablony s otevřeným konceptem, kterým se blíží vystavení (period_start),
     * a kterým ještě nebyl pro toto období odeslán reminder.
     *
     * Okno: 1 ≤ (next_run_date − dnes) ≤ reminder_days_before, reminder_days_before > 0.
     */
    public function findReminderDue(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT t.id
               FROM recurring_invoice_templates t
               JOIN supplier s ON s.id = t.supplier_id
              WHERE t.status = 'active'
                AND t.draft_open_mode = 'period_start'
                AND t.reminder_days_before > 0
                AND s.auto_generate_recurring = 1
                AND DATEDIFF(t.next_run_date, CURDATE()) BETWEEN 1 AND t.reminder_days_before
                AND (t.last_reminder_date IS NULL OR t.last_reminder_date <> t.next_run_date)
              ORDER BY t.next_run_date ASC, t.id ASC"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $out = [];
        foreach ($ids as $id) {
            $tpl = $this->find($id);
            if ($tpl !== null) $out[] = $tpl;
        }
        return $out;
    }

    /** Označí, že reminder pro období (= next_run_date) byl odeslán. */
    public function markReminderSent(int $id, string $periodDate): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates SET last_reminder_date = ? WHERE id = ?'
        )->execute([$periodDate, $id]);
    }

    public function create(array $data, int $userId): int
    {
        $pdo = $this->db->pdo();

        $sql = 'INSERT INTO recurring_invoice_templates
            (supplier_id, branding_profile_id, client_id, project_id, name,
             frequency, day_of_month, end_of_month, anchor_date, end_date, next_run_date,
             invoice_type, currency_id, language, payment_method, reverse_charge, prices_include_vat, discount_percent,
             revenue_category_id,
             payment_due_days, tax_date_mode, draft_open_mode, reminder_days_before,
             note_above_items, note_below_items,
             increment_month_in_descriptions, auto_issue, auto_send_email, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            (int) $data['supplier_id'],
            $this->resolveBrandingProfileId($data['branding_profile_id'] ?? null, (int) $data['supplier_id']),
            (int) $data['client_id'],
            !empty($data['project_id']) ? (int) $data['project_id'] : null,
            (string) $data['name'],
            (string) $data['frequency'],
            !empty($data['end_of_month']) ? null : (isset($data['day_of_month']) && $data['day_of_month'] !== null ? (int) $data['day_of_month'] : null),
            !empty($data['end_of_month']) ? 1 : 0,
            (string) $data['anchor_date'],
            !empty($data['end_date']) ? (string) $data['end_date'] : null,
            (string) ($data['next_run_date'] ?? $data['anchor_date']),
            (string) ($data['invoice_type'] ?? 'invoice'),
            (int) $data['currency_id'],
            (string) ($data['language'] ?? 'cs'),
            (string) ($data['payment_method'] ?? 'bank_transfer'),
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            self::clampDiscountPercent($data['discount_percent'] ?? 0),
            !empty($data['revenue_category_id']) ? (int) $data['revenue_category_id'] : null,
            (int) ($data['payment_due_days'] ?? 14),
            self::normalizeTaxDateMode($data['tax_date_mode'] ?? null),
            self::normalizeDraftOpenMode($data['draft_open_mode'] ?? null),
            self::normalizeReminderDays($data['reminder_days_before'] ?? null),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            !empty($data['increment_month_in_descriptions']) ? 1 : 0,
            !empty($data['auto_issue']) ? 1 : 0,
            !empty($data['auto_send_email']) ? 1 : 0,
            (string) ($data['status'] ?? 'active'),
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function createWithItems(array $data, int $userId, array $items): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->create($data, $userId);
            $this->replaceItems($id, $items);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $endOfMonth = !empty($data['end_of_month']);
        $dayOfMonth = $endOfMonth ? null : (isset($data['day_of_month']) && $data['day_of_month'] !== null ? (int) $data['day_of_month'] : null);

        // Přepočet next_run_date:
        //  - šablona ještě neběžela (last_run_date IS NULL) → next = anchor_date
        //    (uživatel mění harmonogram před prvním generováním).
        //  - už běží → přemapuj DEN nejbližšího naplánovaného next_run_date dle
        //    nového pravidla (end_of_month / day_of_month) v rámci JEHO měsíce —
        //    bez posunu cyklu. Tím se např. změna „20. v měsíci" → „konec měsíce"
        //    projeví hned na nejbližším vystavení (20.6. → 30.6.), ne až o cyklus dál.
        $cur = $this->db->pdo()->prepare(
            'SELECT last_run_date, next_run_date, supplier_id FROM recurring_invoice_templates WHERE id = ?'
        );
        $cur->execute([$id]);
        $existing = $cur->fetch(PDO::FETCH_ASSOC) ?: [];

        if (empty($existing['last_run_date'])) {
            $nextRunDate = (string) $data['anchor_date'];
        } else {
            $nextRunDate = PeriodicityCalculator::snapToDayRule(
                (string) $existing['next_run_date'],
                $endOfMonth,
                $dayOfMonth,
            );
        }

        $sql = 'UPDATE recurring_invoice_templates SET
                client_id = ?, project_id = ?, branding_profile_id = ?, name = ?,
                frequency = ?, day_of_month = ?, end_of_month = ?,
                anchor_date = ?, end_date = ?,
                next_run_date = ?,
                invoice_type = ?, currency_id = ?, language = ?, payment_method = ?,
                reverse_charge = ?, prices_include_vat = ?, discount_percent = ?, revenue_category_id = ?,
                payment_due_days = ?, tax_date_mode = ?,
                draft_open_mode = ?, reminder_days_before = ?,
                note_above_items = ?, note_below_items = ?,
                increment_month_in_descriptions = ?, auto_issue = ?, auto_send_email = ?
              WHERE id = ?';
        $this->db->pdo()->prepare($sql)->execute([
            (int) $data['client_id'],
            !empty($data['project_id']) ? (int) $data['project_id'] : null,
            $this->resolveBrandingProfileId($data['branding_profile_id'] ?? null, (int) ($data['supplier_id'] ?? $existing['supplier_id'] ?? 0)),
            (string) $data['name'],
            (string) $data['frequency'],
            $dayOfMonth,
            $endOfMonth ? 1 : 0,
            (string) $data['anchor_date'],
            !empty($data['end_date']) ? (string) $data['end_date'] : null,
            $nextRunDate,
            (string) ($data['invoice_type'] ?? 'invoice'),
            (int) $data['currency_id'],
            (string) ($data['language'] ?? 'cs'),
            (string) ($data['payment_method'] ?? 'bank_transfer'),
            !empty($data['reverse_charge']) ? 1 : 0,
            !empty($data['prices_include_vat']) ? 1 : 0,
            self::clampDiscountPercent($data['discount_percent'] ?? 0),
            !empty($data['revenue_category_id']) ? (int) $data['revenue_category_id'] : null,
            (int) ($data['payment_due_days'] ?? 14),
            self::normalizeTaxDateMode($data['tax_date_mode'] ?? null),
            self::normalizeDraftOpenMode($data['draft_open_mode'] ?? null),
            self::normalizeReminderDays($data['reminder_days_before'] ?? null),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            !empty($data['increment_month_in_descriptions']) ? 1 : 0,
            !empty($data['auto_issue']) ? 1 : 0,
            !empty($data['auto_send_email']) ? 1 : 0,
            $id,
        ]);
    }

    public function updateWithItems(int $id, array $data, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->update($id, $data);
            $this->replaceItems($id, $items);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function clampDiscountPercent(mixed $value): float
    {
        $v = is_numeric($value) ? (float) $value : 0.0;
        return round(max(0.0, min(100.0, $v)), 2);
    }

    /**
     * Zaznamená poslední chybu (automatického) generování — zobrazí se jako banner
     * na detailu šablony. Volá cron při selhání per-šablonu.
     */
    public function setLastError(int $id, string $message): void
    {
        $msg = mb_substr(trim($message), 0, 500);
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates SET last_error = ?, last_error_at = NOW() WHERE id = ?'
        )->execute([$msg, $id]);
    }

    /**
     * Vynuluje poslední chybu po úspěšném generování (cron i ruční). WHERE guard
     * zabrání zbytečnému zápisu u zdravých šablon při každém běhu cronu.
     */
    public function clearLastError(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates SET last_error = NULL, last_error_at = NULL
              WHERE id = ? AND last_error IS NOT NULL'
        )->execute([$id]);
    }

    private static function normalizeTaxDateMode(mixed $value): string
    {
        $v = is_string($value) ? $value : '';
        return $v === 'previous_month_last_day' ? 'previous_month_last_day' : 'same_as_issue';
    }

    private static function normalizeDraftOpenMode(mixed $value): string
    {
        $v = is_string($value) ? $value : '';
        return $v === 'period_start' ? 'period_start' : 'at_issue';
    }

    /** 0 = bez reminderu, jinak 1–14 dní předem. */
    private static function normalizeReminderDays(mixed $value): int
    {
        $n = is_numeric($value) ? (int) $value : 1;
        return max(0, min(14, $n));
    }

    public function replaceItems(int $templateId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')
            ->execute([$templateId]);

        $stmt = $pdo->prepare(
            'INSERT INTO recurring_invoice_template_items
                (template_id, price_list_item_id, catalog_policy, description_source,
                 catalog_price_source, catalog_source_currency_code,
                 catalog_source_unit_price, catalog_exchange_rate,
                 catalog_exchange_rate_date, description, quantity, unit,
                 unit_price_without_vat, vat_rate_id, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($items) as $i => $item) {
            $stmt->execute([
                $templateId,
                !empty($item['price_list_item_id']) ? (int) $item['price_list_item_id'] : null,
                (string) ($item['catalog_policy'] ?? 'fixed'),
                (string) ($item['description_source'] ?? 'template'),
                $item['catalog_price_source'] ?? null,
                $item['catalog_source_currency_code'] ?? null,
                isset($item['catalog_source_unit_price']) ? (float) $item['catalog_source_unit_price'] : null,
                isset($item['catalog_exchange_rate']) ? (float) $item['catalog_exchange_rate'] : null,
                $item['catalog_exchange_rate_date'] ?? null,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                (int) ($item['vat_rate_id'] ?? 0),
                (int) ($item['order_index'] ?? $i),
            ]);
        }
    }

    /** Posun next_run_date + last_run_date po úspěšném vygenerování faktury. */
    public function advanceSchedule(int $id, string $newNextRunDate, string $lastRunDate, string $newStatus): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates
                SET next_run_date = ?, last_run_date = ?, status = ?
              WHERE id = ?'
        )->execute([$newNextRunDate, $lastRunDate, $newStatus, $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_templates SET status = ? WHERE id = ?'
        )->execute([$status, $id]);
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE smaže items.
        // invoices.recurring_template_id má ON DELETE SET NULL → vygenerované faktury zůstanou.
        $this->db->pdo()->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')
            ->execute([$id]);
    }

    private function cast(array $row): array
    {
        $row['id']             = (int) $row['id'];
        $row['supplier_id']    = (int) $row['supplier_id'];
        $row['client_id']      = (int) $row['client_id'];
        $row['project_id']     = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        if (array_key_exists('branding_profile_id', $row)) {
            $row['branding_profile_id'] = $row['branding_profile_id'] !== null ? (int) $row['branding_profile_id'] : null;
        }
        $row['currency_id']    = (int) $row['currency_id'];
        if (array_key_exists('revenue_category_id', $row)) {
            $row['revenue_category_id'] = $row['revenue_category_id'] !== null ? (int) $row['revenue_category_id'] : null;
        }
        if (array_key_exists('day_of_month', $row)) {
            $row['day_of_month'] = $row['day_of_month'] !== null ? (int) $row['day_of_month'] : null;
        }
        foreach (['end_of_month', 'reverse_charge', 'prices_include_vat', 'increment_month_in_descriptions', 'auto_issue', 'auto_send_email'] as $f) {
            if (array_key_exists($f, $row)) $row[$f] = (bool) $row[$f];
        }
        if (array_key_exists('payment_due_days', $row)) {
            $row['payment_due_days'] = (int) $row['payment_due_days'];
        }
        if (array_key_exists('reminder_days_before', $row)) {
            $row['reminder_days_before'] = (int) $row['reminder_days_before'];
        }
        if (array_key_exists('invoices_generated_count', $row)) {
            $row['invoices_generated_count'] = (int) $row['invoices_generated_count'];
        }
        if (array_key_exists('total_with_vat', $row)) {
            $row['total_with_vat'] = (float) $row['total_with_vat'];
        }
        if (array_key_exists('discount_percent', $row)) {
            $row['discount_percent'] = (float) $row['discount_percent'];
        }
        return $row;
    }

    private function resolveBrandingProfileId(mixed $value, int $supplierId): ?int
    {
        if ($value === null || $value === '') {
            $stmt = $this->db->pdo()->prepare('SELECT CASE WHEN branding_profiles_enabled = 1 THEN default_branding_profile_id ELSE NULL END FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $default = $stmt->fetchColumn();
            return $default !== false && $default !== null ? (int) $default : null;
        }
        $id = (int) $value;
        $stmt = $this->db->pdo()->prepare(
            'SELECT bp.id FROM branding_profiles bp
               JOIN supplier s ON s.id = bp.supplier_id AND s.branding_profiles_enabled = 1
              WHERE bp.id = ? AND bp.supplier_id = ? AND bp.is_active = 1'
        );
        $stmt->execute([$id, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException("Brandingový profil #$id nenalezen.");
        }
        return $id;
    }

    private function castItem(array $row): array
    {
        $row['id']                     = (int) $row['id'];
        $row['template_id']            = (int) $row['template_id'];
        $row['price_list_item_id']     = $row['price_list_item_id'] !== null ? (int) $row['price_list_item_id'] : null;
        $row['vat_rate_id']            = (int) $row['vat_rate_id'];
        $row['order_index']            = (int) $row['order_index'];
        $row['quantity']               = (float) $row['quantity'];
        $row['unit_price_without_vat'] = (float) $row['unit_price_without_vat'];
        if (isset($row['vat_rate_percent'])) {
            $row['vat_rate_percent'] = (float) $row['vat_rate_percent'];
        }
        foreach (['catalog_source_unit_price', 'catalog_exchange_rate'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (float) $row[$key] : null;
            }
        }
        if (array_key_exists('price_list_item_archived', $row)) {
            $row['price_list_item_archived'] = $row['price_list_item_archived'] !== null
                ? (bool) $row['price_list_item_archived']
                : null;
        }
        return $row;
    }
}
