# MyInvoice.cz — Databázové schéma

> MariaDB 11+, charset `utf8mb4`, collation `utf8mb4_unicode_ci`, engine InnoDB (kromě `login_attempts` = MEMORY).
> DB: `myinvoice`, user: `root`, heslo v `cfg.local.php` (gitignored). Instance v `c:/inetpub/MariaDB`.

## Konvence
- PK: `id BIGINT UNSIGNED AUTO_INCREMENT`
- FK: `<entity>_id BIGINT UNSIGNED`, vždy `ON DELETE RESTRICT` (kromě `activity_log` → SET NULL)
- Timestamps: `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- Soft delete jen tam, kde dává smysl (`clients`, `projects` mají `archived_at` místo skutečného mazání pokud existují faktury)
- Jména tabulek: anglicky, snake_case, plural (`invoices`, `clients`)
- Peněžní částky: `DECIMAL(12,2)` (CZK/EUR), pro hodiny `DECIMAL(6,2)`

## ERD (textově)

```
users ───────────────┐
                     │
supplier ─── supplier_bank_accounts
   │
clients ─── client_billing_emails
   │
   ├── price_list_customer_overrides ── price_list_items ── price_list_item_prices
   │                                      │
   │                                      └── recurring_invoice_template_items
   │
   └── projects ── invoices ── invoice_items
                       │
                       └── work_reports ── work_report_items

vat_rates (číselník)
countries (číselník)
currencies (číselník)
sessions
password_resets
login_attempts (MEMORY)
activity_log
ares_cache, vies_cache
invoice_counters
settings (key/value)
```

## Ceníkové položky

`price_list_items` obsahuje ceník oddělený podle `supplier_id`. Kód položky je
unikátní v rámci dodavatele, základní měna je ISO kód a režim
`prices_include_vat` určuje interpretaci všech jejích cen.

`price_list_item_prices` ukládá nejvýše jednu explicitní cenu položky pro každou
měnu dodavatele. `price_list_customer_overrides` přidává přednostní cenu pro
konkrétního zákazníka a měnu. Obě tabulky používají tenant klíč `supplier_id` a
vazbu na ceníkovou položku.

`recurring_invoice_template_items.price_list_item_id` je volitelná trvalá vazba
šablony na ceník. `catalog_policy` rozlišuje fixní snapshot (`fixed`), aktuální
cenu (`current`) a změnu vyžadující kontrolu (`review_required`). Ostatní
`catalog_*` sloupce uchovávají zdroj a kurz schváleného snapshotu.

## Migrace

Migrace ve složce `db/migrations/`, formát `NNNN_description.sql` (sequenční), spouštěč `api/bin/migrate.php` (vlastní jednoduchý, ne Doctrine).

**Stav 2026-05-01:** Migrace 0001–0006 byly konsolidovány do jediného **`0001_init.sql`**:
- 0002 (seed countries/currencies/vat_rates) — inline na konci 0001
- 0003 (`supplier.tagline`) — již v supplier definici
- 0004 (bank_statements + bank_transactions) — sekce 7 v 0001
- 0005 (INET6 → VARBINARY(16)) — všechny IP sloupce použity jako VARBINARY(16) (kompat. s MariaDB 10.6+)
- 0006 (drop email_templates + settings) — tabulky vyhozeny ze schématu

**Min. MariaDB:** 10.6 (po konsolidaci). Před tím byl požadavek 10.10 kvůli typu INET6.

**IP storage v PHP:** `inet_pton($string)` při zápisu, `inet_ntop($binary)` při čtení.
Délka 4 B (IPv4) nebo 16 B (IPv6), oba sedí do `VARBINARY(16)`.

---

## 1. `users`

```sql
CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(190) NOT NULL,
  password_hash   CHAR(60) NOT NULL,
  name            VARCHAR(120) NOT NULL,
  role            ENUM('admin','accountant','readonly') NOT NULL DEFAULT 'admin',
  locale          ENUM('cs','en') NOT NULL DEFAULT 'cs',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at   TIMESTAMP NULL,
  last_login_ip   VARCHAR(45) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;
```

## 2. `sessions`

```sql
CREATE TABLE sessions (
  id          CHAR(64) PRIMARY KEY,           -- sha256 session token
  user_id     BIGINT UNSIGNED NOT NULL,
  csrf_token  CHAR(64) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  user_agent  VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  KEY idx_sess_user (user_id, expires_at),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```
> Použito jen jako fallback, primárně Redis. Cron každou hodinu maže expirované.

## 3. `password_resets`

```sql
CREATE TABLE password_resets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,             -- sha256 raw tokenu
  expires_at  TIMESTAMP NOT NULL,
  used_at     TIMESTAMP NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip          VARCHAR(45) NOT NULL,
  KEY idx_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

## 4. `login_attempts` (MEMORY engine)

```sql
CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_key  VARCHAR(80) NOT NULL,          -- "<sha1(email)>:<ip_class>"
  email       VARCHAR(190) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  success     TINYINT(1) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_bucket (bucket_key, created_at),
  KEY idx_la_email (email, created_at)
) ENGINE=MEMORY;
```
> Jen fallback pokud není Redis. Cron každých 5 min: `DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 HOUR`.

## 5. `supplier`

```sql
CREATE TABLE supplier (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_name             VARCHAR(190) NOT NULL,
  display_name             VARCHAR(190) NULL,        -- pro OSVČ "Jméno Příjmení"
  street                   VARCHAR(190) NOT NULL,
  city                     VARCHAR(120) NOT NULL,
  zip                      VARCHAR(10) NOT NULL,
  country_id               SMALLINT UNSIGNED NOT NULL,
  ic                       VARCHAR(10) NULL,
  dic                      VARCHAR(20) NULL,
  is_vat_payer             TINYINT(1) NOT NULL DEFAULT 1,
  email                    VARCHAR(190) NOT NULL,
  phone                    VARCHAR(40) NULL,
  web                      VARCHAR(190) NULL,
  default_currency         CHAR(3) NOT NULL DEFAULT 'CZK',
  default_vat_rate_id      INT UNSIGNED NOT NULL,
  default_payment_due_days INT UNSIGNED NOT NULL DEFAULT 7,
  default_hourly_rate      DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
  logo_path                VARCHAR(255) NULL,
  signature_path           VARCHAR(255) NULL,        -- pro PDF (transparent PNG)
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sup_country FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_sup_vat FOREIGN KEY (default_vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB;
```

> Pozn.: Multi-supplier — `supplier.id` je `AUTO_INCREMENT`, lze mít N řádků.

## 6. ~~`supplier_bank_accounts`~~ — sloučeno do `currencies`

Bankovní spojení dodavatele je sloučeno do tabulky `currencies` přes
`supplier_id` FK. Každý dodavatel má vlastní řádek per měna (lze mít víc účtů
per měna — např. 2× CZK pro různé banky). Viz § 11.

## 7. `clients`

```sql
CREATE TABLE clients (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_name         VARCHAR(190) NOT NULL,
  first_name           VARCHAR(60) NULL,
  last_name            VARCHAR(60) NULL,
  ic                   VARCHAR(10) NULL,
  dic                  VARCHAR(20) NULL,
  street               VARCHAR(190) NOT NULL,
  city                 VARCHAR(120) NOT NULL,
  zip                  VARCHAR(10) NOT NULL,
  country_id           SMALLINT UNSIGNED NOT NULL,
  main_email           VARCHAR(190) NOT NULL,
  phone                VARCHAR(40) NULL,
  language             ENUM('cs','en') NOT NULL DEFAULT 'cs',
  currency_default     CHAR(3) NOT NULL DEFAULT 'CZK',
  vat_rate_default_id  INT UNSIGNED NULL,    -- override default DPH
  reverse_charge       TINYINT(1) NOT NULL DEFAULT 0,
  payment_due_default  INT UNSIGNED NULL,    -- override default splatnosti
  note                 TEXT NULL,
  archived_at          TIMESTAMP NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_clients_company (company_name),
  KEY idx_clients_ic (ic),
  KEY idx_clients_archived (archived_at),
  CONSTRAINT fk_cli_country FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_cli_vat FOREIGN KEY (vat_rate_default_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB;
```

## 8. `project_billing_emails`

Fakturační emaily jsou na úrovni **zakázky**, ne klienta — každá zakázka může mít vlastní účetní/PM/asistentku. Klient má volitelný `main_email` jako hlavní kontakt.

Při odeslání faktury: `client.main_email + project_billing_emails[]`. Pokud faktura nemá `project_id`, jdou maily jen na `client.main_email`.

```sql
CREATE TABLE project_billing_emails (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  position    TINYINT UNSIGNED NOT NULL,            -- 1, 2, 3
  label       VARCHAR(60) NULL,                     -- "účetní", "PM", volitelný popis
  KEY idx_pbe_project (project_id),
  UNIQUE KEY uq_pbe_pos (project_id, position),
  CONSTRAINT fk_pbe_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT chk_pbe_pos CHECK (position BETWEEN 1 AND 3)
) ENGINE=InnoDB;
```

## 9. `projects` (zakázky)

```sql
CREATE TABLE projects (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id         BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(190) NOT NULL,
  payment_due_days  INT UNSIGNED NOT NULL DEFAULT 7,
  project_number    VARCHAR(50) NULL,
  contract_number   VARCHAR(50) NULL,
  budget_total      DECIMAL(12,2) NULL,
  budget_yearly     DECIMAL(12,2) NULL,
  budget_monthly    DECIMAL(12,2) NULL,
  hourly_rate       DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
  currency          CHAR(3) NOT NULL DEFAULT 'CZK',
  status            ENUM('active','paused','closed') NOT NULL DEFAULT 'active',
  note              TEXT NULL,
  archived_at       TIMESTAMP NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_proj_client (client_id, status),
  CONSTRAINT fk_proj_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

## 10. `vat_rates`

```sql
CREATE TABLE vat_rates (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20) NOT NULL,        -- "CZ-21"
  rate_percent  DECIMAL(5,2) NOT NULL,       -- 21.00
  country       CHAR(2) NOT NULL DEFAULT 'CZ',
  label_cs      VARCHAR(60) NOT NULL,
  label_en      VARCHAR(60) NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  is_reverse_charge TINYINT(1) NOT NULL DEFAULT 0,
  valid_from    DATE NOT NULL,
  valid_to      DATE NULL,
  display_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_vat_code (code)
) ENGINE=InnoDB;

INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, display_order) VALUES
('CZ-21', 21.00, 'CZ', 'Základní 21 %',  'Standard 21 %',     1, 0, '2024-01-01', 10),
('CZ-12', 12.00, 'CZ', 'Snížená 12 %',   'Reduced 12 %',      0, 0, '2024-01-01', 20),
('CZ-0',   0.00, 'CZ', 'Osvobozeno',     'Exempt',            0, 0, '2024-01-01', 30),
('CZ-RC',  0.00, 'CZ', 'Reverse charge', 'Reverse charge',    0, 1, '2024-01-01', 40);
```

## 11. `currencies`, `countries` (číselníky)

```sql
-- Měny + bankovní spojení dodavatele (per-supplier, lze víc účtů per měna).
-- Sloučeno z původní supplier_bank_accounts kvůli zjednodušení.
CREATE TABLE currencies (
  code            CHAR(3) PRIMARY KEY,           -- 'CZK', 'EUR'
  symbol          VARCHAR(8) NOT NULL,
  name_cs         VARCHAR(60) NOT NULL,
  name_en         VARCHAR(60) NOT NULL,
  decimals        TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,        -- vypnout = nelze pro nové faktury

  -- Supplier bank account pro tuto měnu
  account_number  VARCHAR(30) NULL,                      -- CZK "1000000005"
  bank_code       CHAR(4) NULL,                          -- CZK "0100"
  bank_name       VARCHAR(120) NULL,
  iban            VARCHAR(34) NULL,                      -- EUR / non-CZK
  bic             VARCHAR(11) NULL
) ENGINE=InnoDB;

-- Účty (account_number/iban/...) jsou NULL při seedu, vyplní setup wizard nebo Settings UI.
INSERT INTO currencies (code, symbol, name_cs, name_en, decimals, is_active) VALUES
('CZK','Kč','Česká koruna','Czech Koruna',2,1),
('EUR','€','Euro','Euro',2,1);

CREATE TABLE countries (
  id        SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  iso2      CHAR(2) NOT NULL,
  iso3      CHAR(3) NOT NULL,
  name_cs   VARCHAR(120) NOT NULL,
  name_en   VARCHAR(120) NOT NULL,
  is_eu     TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_country_iso2 (iso2)
) ENGINE=InnoDB;
-- seed: ISO 3166-1 list
```

## 12. `invoices`

```sql
CREATE TABLE invoices (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  varsymbol           VARCHAR(20) NULL,           -- NULL pro draft a cancellation, generuje se při issue
  invoice_type        ENUM('invoice','proforma','credit_note','cancellation') NOT NULL DEFAULT 'invoice',
  parent_invoice_id   BIGINT UNSIGNED NULL,       -- proforma → invoice; invoice → credit_note/cancellation
  client_id           BIGINT UNSIGNED NOT NULL,
  project_id          BIGINT UNSIGNED NULL,
  issue_date          DATE NOT NULL,
  tax_date            DATE NULL,                  -- DUZP — NULL pro proformu, jinak povinné
  due_date            DATE NOT NULL,
  currency            CHAR(3) NOT NULL,           -- jen 'CZK' nebo 'EUR' (zatím)
  reverse_charge      TINYINT(1) NOT NULL DEFAULT 0,
  language            ENUM('cs','en') NOT NULL DEFAULT 'cs',
  note_above_items    TEXT NULL,
  note_below_items    TEXT NULL,
  -- Záloha (jen u finální faktury vystavené z proformy):
  advance_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,    -- odečet zaplacené zálohy
  amount_to_pay       DECIMAL(12,2) NOT NULL DEFAULT 0,    -- = total_with_vat - advance_paid_amount
  -- snapshoty pro neměnnost po vystavení:
  client_snapshot     JSON NOT NULL,              -- celá adresa, IČO, DIČ v okamžiku vystavení
  supplier_snapshot   JSON NOT NULL,
  bank_snapshot       JSON NOT NULL,
  -- spočtené součty (denormalizované, recompute při edit draftu):
  total_without_vat   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_with_vat      DECIMAL(12,2) NOT NULL DEFAULT 0,
  rounding            DECIMAL(6,2) NOT NULL DEFAULT 0,
  -- stav:
  status              ENUM('draft','issued','sent','paid','cancelled') NOT NULL DEFAULT 'draft',
  sent_at             TIMESTAMP NULL,
  paid_at             DATE NULL,
  cancelled_at        TIMESTAMP NULL,
  pdf_path            VARCHAR(255) NULL,
  pdf_generated_at    TIMESTAMP NULL,
  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_varsymbol (varsymbol),
  KEY idx_inv_client (client_id, issue_date DESC),
  KEY idx_inv_project (project_id, issue_date DESC),
  KEY idx_inv_status (status, due_date),
  KEY idx_inv_type_month (invoice_type, issue_date),
  KEY idx_inv_parent (parent_invoice_id),
  CONSTRAINT fk_inv_client   FOREIGN KEY (client_id)         REFERENCES clients(id),
  CONSTRAINT fk_inv_project  FOREIGN KEY (project_id)        REFERENCES projects(id),
  CONSTRAINT fk_inv_currency FOREIGN KEY (currency)          REFERENCES currencies(code),
  CONSTRAINT fk_inv_parent   FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_inv_user     FOREIGN KEY (created_by)        REFERENCES users(id)
) ENGINE=InnoDB;
```

## 13. `invoice_items`

```sql
CREATE TABLE invoice_items (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id               BIGINT UNSIGNED NOT NULL,
  description              TEXT NOT NULL,
  quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit                     VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat   DECIMAL(12,2) NOT NULL,
  vat_rate_id              INT UNSIGNED NOT NULL,
  vat_rate_snapshot        DECIMAL(5,2) NOT NULL,    -- denormalizace pro neměnnost
  total_without_vat        DECIMAL(12,2) NOT NULL,   -- quantity * unit_price (zaokrouhleno)
  total_vat                DECIMAL(12,2) NOT NULL,
  total_with_vat           DECIMAL(12,2) NOT NULL,
  order_index              INT NOT NULL DEFAULT 0,
  linked_work_report_id    BIGINT UNSIGNED NULL,
  KEY idx_ii_invoice (invoice_id, order_index),
  CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_vat     FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id),
  CONSTRAINT fk_ii_wr      FOREIGN KEY (linked_work_report_id) REFERENCES work_reports(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

## 14. `work_reports`

```sql
CREATE TABLE work_reports (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  project_id  BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(190) NOT NULL,             -- "Vícepráce za měsíc 4/2026"
  total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,   -- denormalizace
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wr_invoice (invoice_id),         -- 0..1 per faktura
  CONSTRAINT fk_wr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_wr_project FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB;
```

## 15. `work_report_items`

```sql
CREATE TABLE work_report_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_report_id  BIGINT UNSIGNED NOT NULL,
  description     TEXT NOT NULL,
  hours           DECIMAL(6,2) NOT NULL,
  rate            DECIMAL(10,2) NOT NULL,        -- snapshot project.hourly_rate
  total_amount    DECIMAL(12,2) NOT NULL,        -- hours * rate
  order_index     INT NOT NULL DEFAULT 0,
  KEY idx_wri_wr (work_report_id, order_index),
  CONSTRAINT fk_wri_wr FOREIGN KEY (work_report_id) REFERENCES work_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

## 16. `invoice_counters`

Per typ dokladu samostatný čítač per měsíc.

```sql
CREATE TABLE invoice_counters (
  invoice_type ENUM('invoice','proforma','credit_note') NOT NULL,
  year_month   CHAR(6) NOT NULL,           -- "202604"
  last_number  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (invoice_type, year_month)
) ENGINE=InnoDB;
```

> Generování `varsymbol` (atomicky):
> ```sql
> START TRANSACTION;
>   INSERT INTO invoice_counters (invoice_type, period, last_number)
>          VALUES ('invoice', '202604', 1)
>     ON DUPLICATE KEY UPDATE last_number = last_number + 1;
>   SELECT last_number FROM invoice_counters
>          WHERE invoice_type='invoice' AND period='202604' FOR UPDATE;
> COMMIT;
> ```
>
> Pak v PHP se podle typu poskládá výsledný varsymbol:
> | Typ | Vzorec | Příklad pro 1. doklad v 4/2026 |
> |---|---|---|
> | `invoice` | `YYYYMM` + 4 číslice | `2026040001` |
> | `proforma` | `9` + `YYMM` + 4 číslice | `9260400001` |
> | `credit_note` | `7` + `YYMM` + 4 číslice | `7260400001` |
> | `cancellation` | (nedostává — interní) | — |

## 17. `activity_log`

```sql
CREATE TABLE activity_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  action       VARCHAR(50) NOT NULL,       -- 'invoice.created', 'auth.login', ...
  entity_type  VARCHAR(40) NULL,           -- 'invoice', 'client', 'project', 'user'
  entity_id    BIGINT UNSIGNED NULL,
  payload      JSON NULL,                  -- před/po, ID emailu, atd.
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_al_user (user_id, created_at),
  KEY idx_al_entity (entity_type, entity_id, created_at),
  KEY idx_al_action (action, created_at),
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

## 18. `ares_cache`, `vies_cache`

```sql
CREATE TABLE ares_cache (
  ic         VARCHAR(10) PRIMARY KEY,
  payload    JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE vies_cache (
  vat_id     VARCHAR(20) PRIMARY KEY,         -- vč. country code
  is_valid   TINYINT(1) NOT NULL,
  payload    JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

## 19. `bank_statements` + `bank_transactions` (M5b)

```sql
CREATE TABLE bank_statements (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name         VARCHAR(255) NOT NULL,
  file_hash         CHAR(64) NOT NULL,            -- SHA256 pro dedupe
  account_number    VARCHAR(40) NOT NULL,
  statement_date    DATE NOT NULL,
  prev_balance      DECIMAL(14,2) NULL,
  curr_balance      DECIMAL(14,2) NULL,
  credit_total      DECIMAL(14,2) NULL,
  debit_total       DECIMAL(14,2) NULL,
  transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
  matched_count     INT UNSIGNED NOT NULL DEFAULT 0,
  imported_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_by       BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_bs_hash (file_hash)
) ENGINE=InnoDB;

CREATE TABLE bank_transactions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  statement_id      BIGINT UNSIGNED NOT NULL,
  posted_at         DATE NOT NULL,
  amount            DECIMAL(14,2) NOT NULL,        -- + příchozí, - odchozí
  variable_symbol   VARCHAR(20) NULL,
  matched_invoice_id BIGINT UNSIGNED NULL,
  match_status      ENUM('unmatched','auto_exact','auto_partial','manual','ignored') NOT NULL DEFAULT 'unmatched',
  matched_at        TIMESTAMP NULL,
  -- + counterparty_*, constant_symbol, specific_symbol, description, bank_ref
) ENGINE=InnoDB;
```

GPC parser (`Service/Bank/GpcParser`) parsuje 074 (header) + 075 (transaction) řádky.
`StatementImporter` dedupe-uje podle SHA256 file_hash. `StatementMatcher` mapuje VS+amount
na `invoices.varsymbol` se status `issued/sent` — exact match → faktura `paid`.

## ~~20. `email_templates`~~, ~~21. `settings`~~ (vyhozeno migrací 0006)

Tabulky byly v původním schématu, ale nikdy se nezačaly používat — systémová
nastavení žijí v `supplier` (single row) a `currencies` (per-měna bank účet).
Šablony e-mailů jsou hardcoded v Twigu (`api/templates/email/`). Pokud bude potřeba
admin editor šablon, je nutné nejdřív přepsat mailer na render z DB.

---

## Důležité invariants (vynucené v aplikaci, ne v DB)

1. **`supplier`** má vždy přesně 1 řádek (`id=1`).
2. **`currencies`**: bankovní účet (account_number/bank_code/bank_name pro CZK, iban/bic/bank_name pro EUR) — pole jsou NULL dokud uživatel nevyplní v setup wizardu nebo Settings.
3. **`invoices.varsymbol`** je NULL právě tehdy když `status='draft'` nebo `invoice_type='cancellation'`.
4. **`invoices.tax_date`** je NULL právě tehdy když `invoice_type='proforma'` (proforma nemá DUZP).
5. **`invoices.client_snapshot/supplier_snapshot/bank_snapshot`** se zapíše při přechodu na `issued` a od té chvíle se **nemění** (i kdyby user editoval klienta).
6. **`invoice_items.vat_rate_snapshot`** stejná logika — historický snímek.
7. **`work_reports`** je 0..1 per faktura (UNIQUE na `invoice_id`).
8. Edit/delete jsou povolené **jen pro `status='draft'`**. Po `issued` lze pouze:
   - `paid_at` nastavit (issued/sent → paid)
   - `sent_at` nastavit (issued → sent)
   - `cancelled_at` nastavit (storno nebo dobropis — viz spec 4.5)
9. Sumy (`invoices.total_*`, `invoice_items.total_*`, `work_reports.total_*`) jsou denormalizované, recompute v `InvoiceCalculator::recompute(int $invoiceId)` po každém edit.
10. **`invoices.amount_to_pay`** = `total_with_vat - advance_paid_amount`, nesmí být záporné (validace v service).
11. **`invoices.advance_paid_amount > 0`** povolené jen pro `invoice_type='invoice'` s vyplněným `parent_invoice_id` směřujícím na proformu.
12. **`invoices.parent_invoice_id`**:
    - `proforma` → vždy NULL
    - `invoice` → NULL nebo proforma
    - `credit_note`, `cancellation` → vždy vyplněno, vždy směřuje na `invoice` nebo `proforma`
13. **First-run lock**: pokud `users` je prázdná, povolené jsou jen endpointy `/auth/setup-status`, `/auth/setup` a `/health`. Ostatní vrací 423 *Locked*.

---

## Velikost a růst

Odhad pro typické použití (1 dodavatel, ~30 klientů, ~300 faktur/rok):
- `invoices`: ~3 MB / 10 let
- `activity_log`: ~50 MB / rok (nejvíce)
- Doporučeno: archivovat `activity_log` starší než 2 roky do `activity_log_archive` (samostatná tabulka, méně indexů).
