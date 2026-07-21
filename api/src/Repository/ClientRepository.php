<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class ClientRepository
{
    /** Název sběrného systémového dodavatele hotovostních účtenek (viz findOrCreateCashReceiptVendor). */
    private const CASH_RECEIPT_VENDOR_NAME = 'Hotovostní nákup (účtenka)';

    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.is_eu AS country_is_eu,
                    cur.code AS currency_default
               FROM clients c
               JOIN countries co ON co.id = c.country_id
               JOIN currencies cur ON cur.id = c.currency_default_id
              WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->cast($row) : null;
    }

    /**
     * Rychlé hledání pro globální search box — název / e-mail / IČ / DIČ.
     * Jen aktivní (nearchivovaní) klienti tenanta, malý limit (dropdown).
     *
     * @return list<array{id:int, company_name:string, main_email:?string, is_customer:bool, is_vendor:bool}>
     */
    public function searchQuick(string $q, int $supplierId, int $limit = 6): array
    {
        $q = trim($q);
        if ($q === '') return [];
        $esc = addcslashes($q, '%_\\');
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, company_name, main_email, is_customer, is_vendor
               FROM clients
              WHERE supplier_id = ? AND archived_at IS NULL
                AND (company_name LIKE ? OR main_email LIKE ? OR ic LIKE ? OR dic LIKE ?)
              ORDER BY company_name
              LIMIT " . (int) $limit
        );
        $stmt->execute([$supplierId, '%' . $esc . '%', '%' . $esc . '%', $esc . '%', $esc . '%']);
        return array_map(static fn (array $r) => [
            'id'           => (int) $r['id'],
            'company_name' => (string) $r['company_name'],
            'main_email'   => $r['main_email'] !== null ? (string) $r['main_email'] : null,
            'is_customer'  => (bool) $r['is_customer'],
            'is_vendor'    => (bool) $r['is_vendor'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function list(array $filters = [], int $page = 1, int $perPage = 20, string $sort = 'name'): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'c.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['archived'])) {
            $where[] = 'c.archived_at IS NOT NULL';
        } else {
            $where[] = 'c.archived_at IS NULL';
        }
        // Role filter — backend-side aby paginace + total counter byly korektní.
        // Frontend dříve filtroval client-side, ale to dávalo špatné "X z Y" (vendors 15 z 15
        // i když jich je 45) — filtroval jen načtenou stránku, ne celou tabulku.
        $role = (string) ($filters['role'] ?? 'all');
        if ($role === 'vendors') {
            $where[] = 'c.is_vendor = 1';
        } elseif ($role === 'customers') {
            // is_customer default 1 v migraci 0026a — explicit !=0 pokrývá i historická data.
            $where[] = 'c.is_customer <> 0';
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query DoS / nečekanou shodu
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(c.company_name LIKE ? OR c.ic LIKE ? OR c.dic LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q . '%';
            $params[] = $q . '%';
        }
        // Vendor-only filtr na výchozí kategorii nákladu. Aplikuje se na výpis + total,
        // NE na role_counts — tab badge má ukazovat plné součty rolí (jako u role filtru).
        $listWhere = $where;
        $listParams = $params;
        if (!empty($filters['expense_category_id'])) {
            $listWhere[] = 'c.default_expense_category_id = ?';
            $listParams[] = (int) $filters['expense_category_id'];
        }
        $whereSql = implode(' AND ', $listWhere);

        // Count
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM clients c WHERE $whereSql");
        $stmt->execute($listParams);
        $total = (int) $stmt->fetchColumn();

        // Role counts pro tab badge (bez stránkování, bez role-filtru, ale se zbylými filtry
        // jako archived + q + supplier_id). UI ukáže "Klienti (X) | Dodavatelé (Y) | Vše (Z)".
        $whereForCounts = $where;
        $paramsForCounts = $params;
        // Odstranit role-podmínku z whereForCounts (je v něm jako 'c.is_vendor = 1' apod.)
        $whereForCounts = array_values(array_filter(
            $whereForCounts,
            fn ($w) => $w !== 'c.is_vendor = 1' && $w !== 'c.is_customer <> 0'
        ));
        $whereCountsSql = implode(' AND ', $whereForCounts);
        $stmtCounts = $this->db->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN c.is_customer <> 0 THEN 1 ELSE 0 END) AS customers,
                SUM(CASE WHEN c.is_vendor   =  1 THEN 1 ELSE 0 END) AS vendors,
                COUNT(*) AS all_clients
             FROM clients c
            WHERE $whereCountsSql"
        );
        $stmtCounts->execute($paramsForCounts);
        $roleCounts = $stmtCounts->fetch(PDO::FETCH_ASSOC) ?: ['customers' => 0, 'vendors' => 0, 'all_clients' => 0];

        // Whitelist řazení (defense proti SQLi přes user input).
        // Role-aware: u dodavatelů řadíme podle purchase aktivity (costs / last_purchase_date),
        // u zákazníků podle sales (revenue / last_invoice_date).
        $isVendorView = ($filters['role'] ?? 'all') === 'vendors';
        $orderBy = match ($sort) {
            'revenue'       => $isVendorView
                ? 'costs DESC, c.company_name'
                : 'revenue DESC, c.company_name',
            'last_activity' => $isVendorView
                ? 'last_purchase_date IS NULL, last_purchase_date DESC, c.company_name'
                : 'last_invoice_date IS NULL, last_invoice_date DESC, c.company_name',
            default         => 'c.company_name',
        };

        // Page — LIMIT/OFFSET přes bindValue(PARAM_INT) pro defense-in-depth proti SQLi
        $offset = max(0, ($page - 1) * $perPage);
        // Cache `client_revenue_cache` — primární řádek vybíráme přes c.currency_default_id
        $sql = "SELECT c.id, c.supplier_id, c.company_name, c.ic, c.dic, c.tax_number, c.main_email, c.language,
                       c.currency_default_id, cur.code AS currency_default,
                       c.reverse_charge, c.is_vat_payer, c.is_customer, c.is_vendor, c.is_fuel_station,
                       c.auto_send_reminders,
                       c.payment_due_default, c.payment_due_unit, c.hourly_rate,
                       c.default_expense_category_id, c.default_revenue_category_id,
                       c.archived_at, co.iso2 AS country_iso2, co.is_eu AS country_is_eu,
                       (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id AND p.status = 'active' AND p.archived_at IS NULL) AS active_projects_count,
                       COALESCE(crc.revenue, 0) AS revenue,
                       crc.last_invoice_date,
                       COALESCE(crc.invoice_count, 0) AS invoice_count,
                       COALESCE(pi_agg.costs, 0) AS costs,
                       COALESCE(pi_agg.purchase_count, 0) AS purchase_count,
                       pi_agg.last_purchase_date
                  FROM clients c
                  JOIN countries  co  ON co.id  = c.country_id
                  JOIN currencies cur ON cur.id = c.currency_default_id
             LEFT JOIN client_revenue_cache crc ON crc.client_id = c.id AND crc.currency_id = c.currency_default_id
             LEFT JOIN (
                       -- Costs sumarizace přes vendory. Multi-currency:
                       -- EUR/USD/... přepočítáme na CZK přes pi.exchange_rate (CNB k DUZP).
                       -- Bez exchange_rate (CZK řádky) je multiplier 1.
                       -- Výsledek `costs` je tedy v CZK (tenant base ccy), nezávisle na
                       -- vendor.currency_default. UI zobrazí jako Kč.
                       --
                       -- purchase_count zahrnuje i drafty (koncepty z AI importu) — uživatel
                       -- chce vidět celkový počet faktur od vendora včetně rozpracovaných.
                       -- Costs ale jen z non-draft non-cancelled (draft není ekonomicky reálný).
                       SELECT pi.vendor_id,
                              -- Spárovaná/zaplacená záloha (advance) → náklad nese vyúčtovací
                              -- faktura, jinak 2× započteno (shoda s GetClientAction / CRM).
                              SUM(IF(pi.status NOT IN ('draft', 'cancelled')
                                     AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                                              AND (pi.status = 'paid'
                                                   OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                                               WHERE adv_s.advance_purchase_invoice_id = pi.id))),
                                     pi.total_with_vat * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1),
                                     0)) AS costs,
                              SUM(IF(pi.status != 'cancelled', 1, 0)) AS purchase_count,
                              MAX(IF(pi.status != 'cancelled', pi.issue_date, NULL)) AS last_purchase_date
                         FROM purchase_invoices pi
                    LEFT JOIN currencies cur ON cur.id = pi.currency_id
                        WHERE pi.supplier_id = ?
                     GROUP BY pi.vendor_id
                   ) pi_agg ON pi_agg.vendor_id = c.id
                 WHERE $whereSql
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        // supplier_id pro pi_agg subquery (purchase costs) — bind PŘED whereSql params,
        // protože subquery v FROM clauseu je evaluated jako první v SQL parser order.
        $stmt->bindValue($idx++, (int) ($filters['supplier_id'] ?? 0), PDO::PARAM_INT);
        foreach ($listParams as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map(fn (array $r) => $this->cast($r), $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
                'role_counts' => [
                    'all'       => (int) $roleCounts['all_clients'],
                    'customers' => (int) $roleCounts['customers'],
                    'vendors'   => (int) $roleCounts['vendors'],
                ],
            ],
        ];
    }

    public function create(array $data, int $supplierId): int
    {
        $countryId = $this->countryIdFromIso2((string) ($data['country_iso2'] ?? 'CZ'));
        $currencyId = $this->resolveCurrencyId($data, $supplierId);

        // Role flagy — default is_customer=1, is_vendor=0 (BC); ovládá frontend formulář.
        $isCustomer = array_key_exists('is_customer', $data) ? (int) (bool) $data['is_customer'] : 1;
        $isVendor   = array_key_exists('is_vendor',   $data) ? (int) (bool) $data['is_vendor']   : 0;
        if ($isCustomer === 0 && $isVendor === 0) {
            // Default fallback — entita musí mít aspoň jednu roli
            $isCustomer = 1;
        }

        $this->assertTemplatesUnique($supplierId, null, $data);
        $defaultExpenseCategoryId = $this->resolveExpenseCategoryId($data, $supplierId);
        $defaultRevenueCategoryId = $this->resolveRevenueCategoryId($data, $supplierId);

        $sql = 'INSERT INTO clients
            (supplier_id, company_name, first_name, last_name, ic, dic, tax_number, street, city, zip, country_id,
             main_email, phone, language, currency_default_id, reverse_charge, is_vat_payer,
             is_customer, is_vendor, is_fuel_station,
             auto_send_reminders, payment_due_default, payment_due_unit, hourly_rate, note,
             default_expense_category_id, default_revenue_category_id,
             invoice_number_format, proforma_number_format, credit_note_number_format, invoice_number_period,
             default_branding_profile_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId,
            (string) $data['company_name'],
            $this->nullable($data, 'first_name'),
            $this->nullable($data, 'last_name'),
            $this->nullable($data, 'ic'),
            $this->nullable($data, 'dic'),
            // Národní daňové číslo (#120) — SK DIČ / DE Steuernummer / PL NIP / HU Adószám;
            // `dic` u takových klientů nese VAT ID s prefixem (u SK = IČ DPH).
            $this->nullable($data, 'tax_number'),
            (string) $data['street'],
            (string) $data['city'],
            (string) $data['zip'],
            $countryId,
            $this->nullable($data, 'main_email'),
            $this->nullable($data, 'phone'),
            (string) ($data['language'] ?? 'cs'),
            $currencyId,
            !empty($data['reverse_charge']) ? 1 : 0,
            // Plátcovství DPH — default 1 (BC); import doplní z ARES/VIES (neplátce = 0).
            // isset() → null (nezjištěno) spadne na default 1; VendorVatPayerResolver opraví.
            isset($data['is_vat_payer']) ? ((int) (bool) $data['is_vat_payer']) : 1,
            $isCustomer,
            $isVendor,
            array_key_exists('is_fuel_station', $data) ? ((int) (bool) $data['is_fuel_station']) : 0,
            array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1,
            isset($data['payment_due_default']) ? (int) $data['payment_due_default'] : null,
            $this->nullablePaymentDueUnit($data, 'payment_due_unit'),
            (float) ($data['hourly_rate'] ?? 0),
            $this->nullable($data, 'note'),
            $defaultExpenseCategoryId,
            $defaultRevenueCategoryId,
            $this->nullableTemplate($data, 'invoice_number_format'),
            $this->nullableTemplate($data, 'proforma_number_format'),
            $this->nullableTemplate($data, 'credit_note_number_format'),
            $this->nullablePeriod($data, 'invoice_number_period'),
            $this->resolveBrandingProfileId($data['default_branding_profile_id'] ?? null, $supplierId),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Označí klienta jako dodavatele (is_vendor=1). Idempotentní — pokud je už označen,
     * nedělá nic. Volá se z CreatePurchaseInvoiceAction po ověření vendor scope.
     * is_customer flag se NEMĚNÍ — klient může být současně zákazník i dodavatel.
     */
    public function markAsVendor(int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE clients SET is_vendor = 1 WHERE id = ? AND is_vendor = 0')
            ->execute([$id]);
    }

    /**
     * Systémový (sběrný) dodavatel „Hotovostní nákup (účtenka)" — pod něj se navazují
     * hotovostní účtenky bez identifikace protistrany (iDoklad Partner == null). Jeden na
     * tenanta; poznává se podle názvu (bez potřeby extra sloupce — iDoklad import je okrajový).
     * Zakládá se jako neplátce DPH (neznámý/neověřený dodavatel) → bezpečný default pro odpočet.
     * Idempotentní: při opakovaném importu vrací stejný řádek.
     */
    public function findOrCreateCashReceiptVendor(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM clients WHERE supplier_id = ? AND company_name = ? AND is_vendor = 1 LIMIT 1'
        );
        $stmt->execute([$supplierId, self::CASH_RECEIPT_VENDOR_NAME]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        return $this->create([
            'company_name' => self::CASH_RECEIPT_VENDOR_NAME,
            'street'       => '',
            'city'         => '',
            'zip'          => '',
            'main_email'   => '',
            'country_iso2' => 'CZ',
            'is_customer'  => false,
            'is_vendor'    => true,
            'is_vat_payer' => false,
        ], $supplierId);
    }

    /**
     * Nastaví plátcovství DPH klienta (z ARES dle IČO / VIES dle DIČ). Volá se z importu,
     * online refresh endpointu i backfill skriptu. Idempotentní.
     */
    public function setVatPayer(int $id, bool $isVatPayer): void
    {
        $this->db->pdo()
            ->prepare('UPDATE clients SET is_vat_payer = ? WHERE id = ?')
            ->execute([$isVatPayer ? 1 : 0, $id]);
    }

    /**
     * Označí klienta jako zákazníka (is_customer=1). Symetrické s markAsVendor.
     * Volá se např. při importu vystavené faktury pro nový kontakt.
     */
    public function markAsCustomer(int $id): void
    {
        $this->db->pdo()
            ->prepare('UPDATE clients SET is_customer = 1 WHERE id = ? AND is_customer = 0')
            ->execute([$id]);
    }

    /**
     * Vrací počty dokladů, do kterých byla doplněna výchozí kategorie (backfill
     * při nastavení/změně default_expense_category_id / default_revenue_category_id):
     *   ['expense' => počet přijatých faktur, 'revenue' => počet vydaných faktur].
     *
     * @return array{expense:int, revenue:int}
     */
    public function update(int $id, array $data): array
    {
        $pdo = $this->db->pdo();

        // Klient nemůže měnit supplier — odvodíme z aktuálního DB záznamu pro currency lookup
        $stmt = $pdo->prepare('SELECT supplier_id, is_customer, is_vendor, default_expense_category_id, default_revenue_category_id, default_branding_profile_id FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new \InvalidArgumentException("Client #$id nenalezen.");
        }
        $supplierId = (int) $current['supplier_id'];
        $oldDefaultCategory = $current['default_expense_category_id'] !== null
            ? (int) $current['default_expense_category_id']
            : null;
        $oldDefaultRevenueCategory = $current['default_revenue_category_id'] !== null
            ? (int) $current['default_revenue_category_id']
            : null;

        // Role flagy — pokud chybí v payloadu, zachovat aktuální hodnotu (BC).
        $newIsCustomer = array_key_exists('is_customer', $data) ? (int) (bool) $data['is_customer'] : (int) $current['is_customer'];
        $newIsVendor   = array_key_exists('is_vendor',   $data) ? (int) (bool) $data['is_vendor']   : (int) $current['is_vendor'];

        // Lock check: nelze vypnout is_customer pokud klient má vydané faktury.
        if ((int) $current['is_customer'] === 1 && $newIsCustomer === 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
            $stmt->execute([$id]);
            $cnt = (int) $stmt->fetchColumn();
            if ($cnt > 0) {
                throw new \InvalidArgumentException(
                    "Klient nemůže přestat být zákazníkem — má {$cnt} vystavených faktur. " .
                    'Pro skrytí ze seznamu použij archivaci.'
                );
            }
        }
        // Symmetric lock pro is_vendor proti purchase_invoices.
        if ((int) $current['is_vendor'] === 1 && $newIsVendor === 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE vendor_id = ?');
            $stmt->execute([$id]);
            $cnt = (int) $stmt->fetchColumn();
            if ($cnt > 0) {
                throw new \InvalidArgumentException(
                    "Klient nemůže přestat být dodavatelem — má {$cnt} přijatých faktur. " .
                    'Pro skrytí ze seznamu použij archivaci.'
                );
            }
        }
        // Nesmí se vypnout obě role současně (entita by neměla existovat).
        if ($newIsCustomer === 0 && $newIsVendor === 0) {
            throw new \InvalidArgumentException('Klient musí mít alespoň jednu roli (zákazník nebo dodavatel).');
        }

        $countryId = $this->countryIdFromIso2((string) ($data['country_iso2'] ?? 'CZ'));
        $currencyId = $this->resolveCurrencyId($data, $supplierId);

        $this->assertTemplatesUnique($supplierId, $id, $data);
        // Pokud klient default_expense_category_id v payloadu nemá, zachovat aktuální (BC).
        $newDefaultCategory = array_key_exists('default_expense_category_id', $data)
            ? $this->resolveExpenseCategoryId($data, $supplierId)
            : $oldDefaultCategory;
        $newDefaultRevenueCategory = array_key_exists('default_revenue_category_id', $data)
            ? $this->resolveRevenueCategoryId($data, $supplierId)
            : $oldDefaultRevenueCategory;
        $newDefaultBrandingProfileId = array_key_exists('default_branding_profile_id', $data)
            ? $this->resolveBrandingProfileId($data['default_branding_profile_id'], $supplierId)
            : ($current['default_branding_profile_id'] !== null ? (int) $current['default_branding_profile_id'] : null);

        $sql = 'UPDATE clients SET
                company_name = ?, first_name = ?, last_name = ?, ic = ?, dic = ?, tax_number = ?,
                street = ?, city = ?, zip = ?, country_id = ?,
                main_email = ?, phone = ?, language = ?, currency_default_id = ?,
                reverse_charge = ?, is_vat_payer = COALESCE(?, is_vat_payer), is_customer = ?, is_vendor = ?,
                is_fuel_station = COALESCE(?, is_fuel_station),
                auto_send_reminders = ?, payment_due_default = ?, payment_due_unit = ?,
                hourly_rate = ?, note = ?, default_expense_category_id = ?, default_revenue_category_id = ?,
                invoice_number_format = ?, proforma_number_format = ?,
                credit_note_number_format = ?, invoice_number_period = ?, default_branding_profile_id = ?
                WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            (string) $data['company_name'],
            $this->nullable($data, 'first_name'),
            $this->nullable($data, 'last_name'),
            $this->nullable($data, 'ic'),
            $this->nullable($data, 'dic'),
            $this->nullable($data, 'tax_number'),
            (string) $data['street'],
            (string) $data['city'],
            (string) $data['zip'],
            $countryId,
            $this->nullable($data, 'main_email'),
            $this->nullable($data, 'phone'),
            (string) ($data['language'] ?? 'cs'),
            $currencyId,
            !empty($data['reverse_charge']) ? 1 : 0,
            // COALESCE: null (klíč chybí) → zachová stávající is_vat_payer; jinak nastav.
            array_key_exists('is_vat_payer', $data) ? ((int) (bool) $data['is_vat_payer']) : null,
            $newIsCustomer,
            $newIsVendor,
            array_key_exists('is_fuel_station', $data) ? ((int) (bool) $data['is_fuel_station']) : null,
            array_key_exists('auto_send_reminders', $data) ? ((int) (bool) $data['auto_send_reminders']) : 1,
            isset($data['payment_due_default']) ? (int) $data['payment_due_default'] : null,
            $this->nullablePaymentDueUnit($data, 'payment_due_unit'),
            (float) ($data['hourly_rate'] ?? 0),
            $this->nullable($data, 'note'),
            $newDefaultCategory,
            $newDefaultRevenueCategory,
            $this->nullableTemplate($data, 'invoice_number_format'),
            $this->nullableTemplate($data, 'proforma_number_format'),
            $this->nullableTemplate($data, 'credit_note_number_format'),
            $this->nullablePeriod($data, 'invoice_number_period'),
            $newDefaultBrandingProfileId,
            $id,
        ]);

        // Backfill: pokud byla nastavena/změněna výchozí kategorie, doplnit ji do všech
        // dokladů tohoto klienta, které kategorii nemají vyplněnou.
        // Doklady s již vybranou kategorií zůstávají beze změny ("pokud tam není zvoleno jiné").
        $expenseBackfilled = 0;
        if ($newDefaultCategory !== null && $newDefaultCategory !== $oldDefaultCategory) {
            $backfill = $pdo->prepare(
                'UPDATE purchase_invoices
                    SET expense_category_id = ?
                  WHERE vendor_id = ? AND supplier_id = ? AND expense_category_id IS NULL'
            );
            $backfill->execute([$newDefaultCategory, $id, $supplierId]);
            $expenseBackfilled = $backfill->rowCount();
        }

        $revenueBackfilled = 0;
        if ($newDefaultRevenueCategory !== null && $newDefaultRevenueCategory !== $oldDefaultRevenueCategory) {
            $backfill = $pdo->prepare(
                'UPDATE invoices
                    SET revenue_category_id = ?
                  WHERE client_id = ? AND supplier_id = ? AND revenue_category_id IS NULL'
            );
            $backfill->execute([$newDefaultRevenueCategory, $id, $supplierId]);
            $revenueBackfilled = $backfill->rowCount();
        }

        return ['expense' => $expenseBackfilled, 'revenue' => $revenueBackfilled];
    }

    /**
     * Validace výchozí kategorie nákladu z payloadu. Vrací int id nebo null.
     * NULL / 0 / prázdné → null (bez defaultu). Jinak ověří, že kategorie patří
     * danému tenantovi (supplier_id), jinak vyhodí výjimku.
     */
    private function resolveExpenseCategoryId(array $data, int $supplierId): ?int
    {
        if (!array_key_exists('default_expense_category_id', $data)) {
            return null;
        }
        $raw = $data['default_expense_category_id'];
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $catId = (int) $raw;
        $check = $this->db->pdo()->prepare(
            'SELECT 1 FROM expense_categories WHERE id = ? AND supplier_id = ?'
        );
        $check->execute([$catId, $supplierId]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException("Kategorie nákladu #$catId nepatří tomuto tenantovi.");
        }
        return $catId;
    }

    /**
     * Validace výchozí kategorie tržby z payloadu. Vrací int id nebo null.
     * NULL / 0 / prázdné → null (bez defaultu). Jinak ověří, že kategorie patří
     * danému tenantovi (supplier_id). Symetrie k {@see resolveExpenseCategoryId}.
     */
    private function resolveRevenueCategoryId(array $data, int $supplierId): ?int
    {
        if (!array_key_exists('default_revenue_category_id', $data)) {
            return null;
        }
        $raw = $data['default_revenue_category_id'];
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $catId = (int) $raw;
        $check = $this->db->pdo()->prepare(
            'SELECT 1 FROM revenue_categories WHERE id = ? AND supplier_id = ?'
        );
        $check->execute([$catId, $supplierId]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException("Kategorie tržby #$catId nepatří tomuto tenantovi.");
        }
        return $catId;
    }

    /**
     * Resolve currency_id z `currency_default_id` (preferováno) nebo z `currency_default` (legacy code lookup).
     * Lookup je SCOPED per supplier — currencies patří jednomu supplier.
     * Pokud je dáno explicitní currency_default_id, ověří že patří danému supplier.
     */
    private function resolveCurrencyId(array $data, int $supplierId): int
    {
        if (isset($data['currency_default_id'])) {
            $id = (int) $data['currency_default_id'];
            $check = $this->db->pdo()->prepare('SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?');
            $check->execute([$id, $supplierId]);
            if (!$check->fetchColumn()) {
                throw new \InvalidArgumentException("Currency #$id nepatří supplier #$supplierId.");
            }
            return $id;
        }
        $code = strtoupper((string) ($data['currency_default'] ?? 'CZK'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \InvalidArgumentException("Currency not found: $code (supplier #$supplierId)");
        }
        return (int) $id;
    }

    public function archive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NOW() WHERE id = ?')->execute([$id]);
    }

    public function unarchive(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET archived_at = NULL WHERE id = ?')->execute([$id]);
    }

    public function projectsForClient(int $clientId, int $limit = 10): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.status, cur.code AS currency, p.hourly_rate, p.payment_due_days, p.project_number
               FROM projects p
               JOIN currencies cur ON cur.id = p.currency_id
              WHERE p.client_id = ? AND p.archived_at IS NULL
              ORDER BY p.status = "active" DESC, p.name
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function countryIdFromIso2(string $iso2): int
    {
        $iso2 = strtoupper($iso2);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmt->execute([$iso2]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $stmt->execute(['CZ']);
            $id = $stmt->fetchColumn();
        }
        return (int) $id;
    }

    private function cast(array $row): array
    {
        $row['id']                    = (int) $row['id'];
        if (isset($row['country_id'])) $row['country_id'] = (int) $row['country_id'];
        if (isset($row['supplier_id'])) $row['supplier_id'] = (int) $row['supplier_id'];
        if (isset($row['currency_default_id'])) $row['currency_default_id'] = (int) $row['currency_default_id'];
        if (array_key_exists('default_expense_category_id', $row)) {
            $row['default_expense_category_id'] = $row['default_expense_category_id'] !== null
                ? (int) $row['default_expense_category_id']
                : null;
        }
        if (array_key_exists('default_revenue_category_id', $row)) {
            $row['default_revenue_category_id'] = $row['default_revenue_category_id'] !== null
                ? (int) $row['default_revenue_category_id']
                : null;
        }
        if (array_key_exists('default_branding_profile_id', $row)) {
            $row['default_branding_profile_id'] = $row['default_branding_profile_id'] !== null
                ? (int) $row['default_branding_profile_id']
                : null;
        }
        $row['reverse_charge']        = (bool) ($row['reverse_charge'] ?? 0);
        // EU členství země klienta — editor podle něj u identifikované osoby (#94)
        // auto-zapíná RC jen pro EU klienty (3. země = mimo předmět DPH, bez klauzule).
        if (array_key_exists('country_is_eu', $row)) $row['country_is_eu'] = (bool) $row['country_is_eu'];
        if (array_key_exists('is_vat_payer', $row)) $row['is_vat_payer'] = (bool) $row['is_vat_payer'];
        if (array_key_exists('is_customer', $row)) $row['is_customer'] = (bool) $row['is_customer'];
        if (array_key_exists('is_vendor', $row))   $row['is_vendor']   = (bool) $row['is_vendor'];
        if (array_key_exists('is_fuel_station', $row)) $row['is_fuel_station'] = (bool) $row['is_fuel_station'];
        if (array_key_exists('auto_send_reminders', $row)) {
            $row['auto_send_reminders'] = (bool) $row['auto_send_reminders'];
        }
        if (array_key_exists('active_projects_count', $row)) {
            $row['active_projects_count'] = (int) $row['active_projects_count'];
        }
        if (isset($row['payment_due_default'])) {
            $row['payment_due_default'] = $row['payment_due_default'] !== null ? (int) $row['payment_due_default'] : null;
        }
        if (array_key_exists('hourly_rate', $row)) {
            $row['hourly_rate'] = (float) $row['hourly_rate'];
        }
        if (array_key_exists('revenue', $row))           $row['revenue'] = (float) $row['revenue'];
        if (array_key_exists('costs', $row))             $row['costs'] = (float) $row['costs'];
        if (array_key_exists('purchase_count', $row))    $row['purchase_count'] = (int) $row['purchase_count'];
        if (array_key_exists('last_purchase_date', $row)) $row['last_purchase_date'] = $row['last_purchase_date'] ?: null;
        if (array_key_exists('last_invoice_date', $row)) $row['last_invoice_date'] = $row['last_invoice_date'] ?: null;
        if (array_key_exists('invoice_count', $row))     $row['invoice_count'] = (int) $row['invoice_count'];
        return $row;
    }

    private function resolveBrandingProfileId(mixed $value, int $supplierId): ?int
    {
        if ($value === null || $value === '') return null;
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

    private function nullable(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /**
     * Per-client invoice template. Whitelistne placeholdery a délku, jinak null —
     * uživatel by neměl protlačit neplatný template, který by VarsymbolGenerator
     * vyhodil za invalid až při issue.
     */
    private function nullableTemplate(array $data, string $key): ?string
    {
        $v = $this->nullable($data, $key);
        if ($v === null) return null;
        if (strlen($v) > 60) {
            throw new \InvalidArgumentException("{$key} smí mít max 60 znaků.");
        }
        $stripped = preg_replace('/\{(YYYY|YY|MM|C+)\}/', '', $v) ?? '';
        if (preg_match('/[{}]/', $stripped)) {
            throw new \InvalidArgumentException("{$key} obsahuje neznámý placeholder. Dovolené: {YYYY} {YY} {MM} {C+}.");
        }
        return $v;
    }

    /**
     * Per-client period override. NULL = dědí ze supplieru.
     */
    private function nullablePeriod(array $data, string $key): ?string
    {
        $v = $this->nullable($data, $key);
        if ($v === null) return null;
        if (!in_array($v, ['year', 'month', 'none'], true)) {
            throw new \InvalidArgumentException("{$key} musí být year, month nebo none.");
        }
        return $v;
    }

    /**
     * Per-client splatnost unit override. NULL = dědí supplier.default_payment_due_unit.
     */
    private function nullablePaymentDueUnit(array $data, string $key): ?string
    {
        $v = $this->nullable($data, $key);
        if ($v === null) return null;
        if (!in_array($v, ['days', 'month'], true)) {
            throw new \InvalidArgumentException("{$key} musí být 'days' nebo 'month'.");
        }
        return $v;
    }

    /**
     * Pokud chce klient stejný číselný template jaký už používá jiný klient ve stejném
     * supplierském scope, vygenerují oba stejné varsymboly a druhý INSERT do `invoices`
     * spadne na unique `(supplier_id, varsymbol)`. Tady to zachytíme s hláškou předem.
     *
     * Kontrolujeme jen non-null templates — když je všude NULL (dědíme ze supplieru),
     * konflikt řeší supplier-wide counter sám.
     *
     * @throws \InvalidArgumentException pokud najdeme kolidujícího klienta
     */
    private function assertTemplatesUnique(int $supplierId, ?int $excludeClientId, array $data): void
    {
        $cols = ['invoice_number_format', 'proforma_number_format', 'credit_note_number_format'];
        foreach ($cols as $col) {
            $tpl = $this->nullableTemplate($data, $col);
            if ($tpl === null) continue;

            $sql = "SELECT id, company_name FROM clients
                     WHERE supplier_id = ? AND {$col} = ?";
            $params = [$supplierId, $tpl];
            if ($excludeClientId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $excludeClientId;
            }
            $sql .= ' LIMIT 1';

            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                throw new \InvalidArgumentException(
                    "Formát '{$tpl}' už používá klient \"{$row['company_name']}\". "
                    . 'Zvol jiný formát — např. přidej prefix s iniciálou klienta.'
                );
            }
        }
    }
}
