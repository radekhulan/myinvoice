# MyInvoice.cz — REST API

> **Base URL:** `https://myinvoice.cz/api` (prod), `https://dev.myinvoice.cz/api` (dev), `http://localhost:8080/api` (lokál).
> **Verze v URL není** — jediný konzument je vlastní SPA, breaking changes řeší deploy.
> **Formát:** JSON, charset UTF-8, datum ISO 8601 (`YYYY-MM-DD`), timestamp `YYYY-MM-DD HH:MM:SS` (lokální čas Europe/Prague).
> **Auth:** session cookie + `X-CSRF-Token` header pro mutace.
> **Multi-supplier scope:** `X-Supplier-Id` header (FE Pinia store) nebo `?supplier_id=N` query (přímé navigace na PDF/ZIP). Pokud chybí, fallback = MIN(supplier.id).
> **Zdroj pravdy** je `api/src/Routes.php` — pokud zde něco nesedí, věřte kódu.

## Stav implementace (2026-05-05)

Tento dokument zrcadlí aktuální stav `Routes.php`. Verze 1.9.x.

### Přehled cest

```
# System
GET  /api/health

# Auth
GET  /api/auth/setup-status              POST /api/auth/setup
POST /api/auth/setup-ares-lookup         POST /api/auth/setup-sample
POST /api/auth/login                     POST /api/auth/logout
GET  /api/auth/me                        POST /api/auth/change-password
POST /api/auth/forgot                    POST /api/auth/reset
GET  /api/auth/totp/status               POST /api/auth/totp/setup
POST /api/auth/totp/enable

# ARES / VIES (auth required)
POST /api/clients/lookup-ares            POST /api/clients/lookup-vies

# Codebooks (read-only, sdílené pro UI)
GET  /api/codebooks/countries            GET /api/codebooks/currencies
GET  /api/codebooks/vat-rates

# Clients
GET/POST  /api/clients
GET/PUT/DELETE  /api/clients/{id}
POST /api/clients/{id}/archive           POST /api/clients/{id}/unarchive

# Projects (zakázky)
GET   /api/clients/{client_id}/projects
GET   /api/projects                      GET /api/projects/stats
POST  /api/projects
GET/PUT/DELETE  /api/projects/{id}
POST  /api/projects/{id}/archive

# Invoices
GET   /api/invoices                      GET /api/invoices/export.csv
POST  /api/invoices
GET/PUT/DELETE  /api/invoices/{id}
GET   /api/invoices/{id}/activity
POST  /api/invoices/{id}/issue           POST /api/invoices/{id}/mark-paid
POST  /api/invoices/{id}/cancel          POST /api/invoices/{id}/clone
POST  /api/invoices/{id}/issue-final     POST /api/invoices/bulk-reissue
GET   /api/invoices/{id}/pdf
GET   /api/invoices/{id}/pdfs            GET /api/invoices/{id}/pdfs/{archiveId}
POST  /api/invoices/{id}/send            POST /api/invoices/{id}/send-test
POST  /api/invoices/{id}/reminder        POST /api/invoices/{id}/reminder-test
POST  /api/invoices/bulk-reminder

# Work reports
GET/PUT/DELETE  /api/invoices/{id}/work-report

# Schvalování výkazu zákazníkem
POST /api/invoices/{id}/request-approval
POST /api/invoices/{id}/request-approval-test
PUT  /api/invoices/{id}/approval-status
GET  /api/public/approval/{token}        POST /api/public/approval/{token}/decide

# Dashboard
GET  /api/dashboard/summary

# Admin
GET  /api/admin/activity-log             GET /api/admin/approvals
GET  /api/admin/invoices-zip             GET /api/admin/export
POST /api/admin/import
GET/POST  /api/admin/users
PUT/DELETE  /api/admin/users/{id}
GET  /api/admin/email-templates
GET/PUT/DELETE  /api/admin/email-templates/{code}/{locale}

# Multi-supplier (admin)
GET/POST  /api/suppliers
GET/PUT/DELETE  /api/suppliers/{id}

# Settings (akt. supplier dle X-Supplier-Id)
GET/PUT  /api/settings/supplier
GET/POST  /api/settings/currencies
PUT/DELETE  /api/settings/currencies/{id}
GET/POST  /api/settings/vat-rates
PUT/DELETE  /api/settings/vat-rates/{id}
GET/POST  /api/settings/countries
PUT/DELETE  /api/settings/countries/{id}

# Bank (M5b)
POST /api/bank-statements/upload         POST /api/bank-statements/scan
GET  /api/bank-statements                GET /api/bank-statements/{id}
POST /api/bank-transactions/{id}/match
POST /api/bank-transactions/{id}/unmatch
POST /api/bank-transactions/{id}/ignore
```

### Klíčové změny od původní specifikace

- **Multi-supplier (M7):** všechny scoped endpointy čtou `X-Supplier-Id` header (resp. `?supplier_id=` fallback). Globální admin sekce `/api/suppliers` spravuje samotné dodavatele.
- **Settings:** sloučené pod `/api/settings/*`, číselníky se identifikují **numerickým `id`** (ne `code`).
- **Bank import (M5b):** `/api/bank-statements/*`, `/api/bank-transactions/*` (upload, parse, párování s fakturami).
- **Schvalování (M8):** klient může výkaz schvalovat přes veřejný `/api/public/approval/{token}` flow.
- **Email šablony (M9):** plná CRUD nad `/api/admin/email-templates/{code}/{locale}` (locale = `cs|en`).
- **Reminders:** `/api/invoices/{id}/reminder`, `reminder-test` a `bulk-reminder` (po splatnosti).
- **PDF historie:** `/api/invoices/{id}/pdfs` listuje archivované PDF, `/{archiveId}` stahuje konkrétní snapshot.
- **Generic export/import (M6/M7):** `/api/admin/export?format=pdf-zip|isdoc|pohoda&month=YYYY-MM` nebo `period=quarterly&year=YYYY&quarter=1..4`, `/api/admin/import` (Pohoda XML / ISDOC, single i ZIP).
- **TOTP/2FA:** `/api/auth/totp/*` pro nastavení a aktivaci.
- **IP allowlist a další security policies** se konfigurují v `cfg.php` (resp. v DB přes setup), **nikoli** přes API. Endpointy `/admin/security/*`, `/admin/smtp/test`, `/admin/sessions` neexistují.

---

## Obecné konvence

### HTTP statusy

| Kód | Kdy |
|---|---|
| 200 | OK (GET, úspěšný PUT/POST s tělem) |
| 201 | Created (POST vytvoření) |
| 204 | No content (DELETE, některé idempotentní mutace) |
| 400 | Validace selhala / špatný request |
| 401 | Nepřihlášený |
| 403 | Přihlášený, ale chybí role (typicky `admin`) |
| 404 | Nenalezeno (včetně cizího supplier_id) |
| 409 | Konflikt stavu (např. faktura už issued, nelze editovat / smazat) |
| 422 | Sémantická chyba (záporné množství, neplatný datum) |
| 423 | Locked (setup wizard běží, captcha required) |
| 429 | Rate limit |
| 500 | Server error |
| 502 | Gateway error (SMTP, ARES/VIES upstream) |

### Error response

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Validace selhala",
    "fields": {
      "main_email": ["Email je povinný"],
      "ic": ["IČO musí mít 8 číslic"]
    }
  }
}
```

`Json::error()` v `api/src/Http/Json.php` je jediný producent error responses — vždy obal `{"error": {...}}`.

### Paginated list

```json
{
  "data": [ ... ],
  "meta": { "total": 142, "page": 1, "per_page": 20, "pages": 8 }
}
```

### Query parameters pro listy

- `page` (default 1)
- `per_page` (default 20, max 100)
- `sort` (např. `-issue_date,client_name` — minus = DESC)
- `q` (full-text search, kde dává smysl)
- `filter[<field>]` (např. `filter[status]=issued&filter[client_id]=42`)

### Multi-supplier headery

```
X-Supplier-Id: 1            # který supplier scope se aplikuje
X-CSRF-Token: <token>       # u všech mutací (POST/PUT/PATCH/DELETE) mimo public/auth
```

`X-Supplier-Id` se ignoruje na: `/auth/*`, `/health`, `/codebooks/*`, `/public/approval/*`, `/suppliers/*`. Pro stažení PDF/ZIP přes přímé navigace v browseru lze fallback `?supplier_id=N`.

---

## 1. Auth

### `GET /auth/setup-status`

Vždy dostupný. Vrací stav prvotního nastavení + public captcha info.

```json
{
  "needs_setup": true,
  "version": "1.9.0",
  "captcha": { "provider": "turnstile", "site_key": "0x4...", "script_url": "https://..." }
}
```

Pokud `needs_setup=true`, frontend přesměruje na `/setup` wizard. Všechny ostatní endpointy (kromě `/health`, `/auth/setup`, `/auth/setup-ares-lookup`, `/auth/setup-sample`) vrací **423 Locked** (`FirstRunLockMiddleware`).

### `POST /auth/setup`

First-run admin setup — funguje jen pokud `users` je prázdná. Body obsahuje `admin{}` a volitelně `supplier{}` se základními údaji a prvním bankovním účtem (viz `01-spec.md`). Response 201 s `{user, next}`. Errors: `409 setup_already_done`, `400 validation_failed`, `429 rate_limited`.

### `POST /auth/setup-ares-lookup`

Public proxy pro ARES během setup wizardu (uživatel ještě není přihlášený). Body: `{ "ic": "12345678" }`. Stejný response shape jako `/clients/lookup-ares`. Rate-limited, funguje **jen pokud setup ještě neproběhl**.

### `POST /auth/setup-sample`

Vygeneruje sample data (klienti, projekty, ukázkové faktury) pro účely demo. Public, funguje **jen pokud `users` ještě prázdná nebo je první uživatel bez dat**.

### `POST /auth/login`

```json
{ "email": "...", "password": "...", "cf_turnstile_response": "...", "totp_code": "123456" }
```

- `cf_turnstile_response` povinný **jen** pokud předchozí response byla `423 captcha_required` (5+ selhání v okně 5 min).
- `totp_code` povinný pokud má uživatel zapnuté TOTP (jinak ignored).

Response 200: `{ "user": { "id":1, "email":"...", "name":"...", "role":"admin", "locale":"cs" }, "csrf_token": "..." }`
Cookie: `myinvoice_session=<token>; HttpOnly; Secure; SameSite=Lax; Max-Age=2592000`

Errors: `401 invalid_credentials`, `401 totp_required`, `401 totp_invalid`, `423 captcha_required`, `423 captcha_failed`, `429 too_many_attempts`.

### `POST /auth/logout` → 204

### `GET /auth/me` → aktuální user nebo 401

### `POST /auth/change-password`

`{ current_password, new_password, new_password_confirm }` → 204. Invaliduje **ostatní** sessions.

### `POST /auth/forgot`

`{ email }` → **vždy 204** (ochrana proti enumeraci). Rate limit: 3/hod/email.

### `POST /auth/reset`

`{ token, password, password_confirm }` → 204. Invaliduje všechny sessions uživatele. Errors: `400 invalid_token`, `410 token_expired`.

### TOTP (2FA)

- `GET /auth/totp/status` — `{ "enabled": true|false, "enrolled_at": "..." }`
- `POST /auth/totp/setup` — vygeneruje secret + QR kód. Response: `{ "secret": "BASE32...", "qr_data_url": "data:image/png;base64,...", "uri": "otpauth://..." }`. Není ještě aktivováno.
- `POST /auth/totp/enable` — `{ "code": "123456" }` ověří první kód a TOTP zapne. Errors: `400 invalid_code`.

---

## 2. ARES / VIES lookup

### `POST /clients/lookup-ares`

```json
{ "ic": "12345678" }
```

Response:
```json
{
  "found": true,
  "data": {
    "company_name": "ACME s.r.o.",
    "street": "Václavské náměstí 1",
    "city": "Praha", "zip": "11000", "country_iso2": "CZ",
    "ic": "12345678", "dic": "CZ12345678"
  }
}
```

Cache 24h. Rate limit 30/min/user.

### `POST /clients/lookup-vies`

```json
{ "vat_id": "CZ12345678" }
```

Response: `{ "valid": true, "name": "...", "address": "...", "fetched_at": "..." }`. Rate limit 30/min/user.

---

## 3. Codebooks (read-only)

| Endpoint | Co vrací |
|---|---|
| `GET /codebooks/countries` | Seznam ISO zemí (id, iso2, iso3, name_cs, name_en, eu_member) |
| `GET /codebooks/currencies` | Seznam měn (id, code, name, decimals) |
| `GET /codebooks/vat-rates` | Seznam VAT sazeb. Query: `?country=CZ&active_on=2026-04-30`. Default: aktivní dnes pro CZ. |

Vše cacheable ve frontend store na celou session. Editace přes `/api/settings/*`.

---

## 4. Clients

### `GET /clients`

Query: `q`, `filter[archived]=0|1`, `filter[country_iso2]`, paginated.

```json
{
  "id": 42, "company_name": "ACME s.r.o.", "ic": "12345678",
  "main_email": "...", "language": "cs", "currency_default_code": "CZK",
  "reverse_charge": false, "active_projects_count": 3, "archived_at": null
}
```

### `GET /clients/{id}`

Plný detail + `projects[]` (preview, max 10) + `last_invoice_at`.

### `POST /clients` — body viz sloupce `clients` (bez billing emailů — ty jsou per-zakázka). Vrací 201 + Location.

### `PUT /clients/{id}` — částečný update.

### `POST /clients/{id}/archive` → 204

### `POST /clients/{id}/unarchive` → 204

### `DELETE /clients/{id}`

Smazání **jen pokud klient nemá žádnou fakturu**. Jinak 409 + návod „použijte archivaci".

---

## 5. Projects (zakázky)

### `GET /clients/{client_id}/projects`

Seznam zakázek klienta (typicky < 20, bez paginace).

### `GET /projects`

Cross-client seznam (dashboard widget, archív). Paginated. Query: `q`, `filter[status]=active|archived`, `filter[client_id]`.

### `GET /projects/stats`

Souhrnná statistika napříč zakázkami pro dashboard (`active_count`, `archived_count`, `over_budget_count`, top-N podle obratu).

### `GET /projects/{id}`

Detail + `client` (embed) + statistika (`invoiced_total_year`, `invoiced_total_month`, `last_invoice_date`, `budget_used_pct`).

### `POST /projects`

```json
{
  "client_id": 42,
  "name": "Údržba webu 2026",
  "payment_due_days": 14,
  "project_number": "P-2026-001",
  "contract_number": "S-12/2025",
  "budget_total": 500000,
  "budget_yearly": 200000,
  "budget_monthly": 20000,
  "hourly_rate": 1500,
  "currency_id": 1,
  "status": "active",
  "billing_emails": [
    { "position": 1, "email": "ucetni@acme.cz",  "label": "účetní" },
    { "position": 2, "email": "pm@acme.cz",      "label": "PM" }
  ]
}
```

### `PUT /projects/{id}` — částečný update včetně `billing_emails[]` (replace).

### `POST /projects/{id}/archive` → 204

### `DELETE /projects/{id}` — jen pokud zakázka nemá faktury.

---

## 6. Invoices

### `GET /invoices`

Query:
- `filter[status]` — `draft`, `issued`, `sent`, `reminded`, `paid`, `cancelled` (čárkou víc)
- `filter[type]` — `invoice`, `proforma`, `credit_note`, `cancellation` (čárkou víc; default vše)
- `filter[client_id]`, `filter[project_id]`, `filter[parent_invoice_id]`
- `filter[year]=2026`, `filter[month]=4`
- `filter[overdue]=1`, `filter[unpaid_only]=1`
- `q` — hledání ve `varsymbol`, `client.company_name`
- `sort` (default `-issue_date,-id`)

### `GET /invoices/export.csv`

Stejné query jako `/invoices` (bez paginace). Stáhne CSV s vybranými fakturami (bez položek — pro účetní reporting). Content-Type `text/csv; charset=utf-8`.

### `GET /invoices/{id}`

```json
{
  "id": 123, "varsymbol": "2026040001", "status": "issued", "invoice_type": "invoice",
  "client": { "id": 42, "company_name": "ACME", ... },
  "project": { "id": 7, "name": "Údržba webu 2026", "hourly_rate": 1500 },
  "supplier_id": 1,
  "bank_account": { "id": 1, "currency": "CZK", "account_number": "1000000005", "bank_code": "0100", "iban": null },
  "issue_date": "2026-04-30", "tax_date": "2026-04-30", "due_date": "2026-05-07",
  "currency": "CZK", "reverse_charge": false, "language": "cs",
  "items": [
    { "id":1, "description":"Konzultace 4/2026", "quantity":10, "unit":"h",
      "unit_price_without_vat":1500, "vat_rate_id":1, "vat_rate_snapshot":21.00,
      "total_without_vat":15000, "total_vat":3150, "total_with_vat":18150,
      "linked_work_report_id": null, "order_index": 0 }
  ],
  "work_report": null,
  "totals": { "without_vat":15000, "vat":3150, "with_vat":18150, "rounding":0 },
  "vat_breakdown": [ { "rate":21.00, "base":15000, "vat":3150 } ],
  "snapshots": { ... },
  "approval": { "status": "none|requested|approved|rejected", "decided_at": null, "comment": null },
  "sent_at": null, "paid_at": null, "reminded_at": null,
  "created_at": "2026-04-30 10:15:00"
}
```

### `POST /invoices`

```json
{
  "client_id": 42,
  "project_id": 7,
  "bank_account_id": 1,
  "issue_date": "2026-04-30",
  "tax_date": "2026-04-30",
  "due_date": null,
  "currency_id": 1,
  "reverse_charge": false,
  "language": "cs",
  "invoice_type": "invoice",
  "items": [
    { "description": "Konzultace 4/2026", "quantity": 10, "unit": "h",
      "unit_price_without_vat": 1500, "vat_rate_id": 1, "order_index": 0 }
  ],
  "work_report": null
}
```

Response 201 + plný detail. Default chování: `bank_account_id=null` → dosadí se default pro currency, `due_date=null` → `issue_date + project.payment_due_days`.

### `PUT /invoices/{id}`

Edit draftu. Pokud `status != 'draft'` → 409 (`invalid_state`).

### `DELETE /invoices/{id}`

Pokud `status != 'draft'` → 409.

### `POST /invoices/{id}/issue`

Draft → issued. Vygeneruje `varsymbol` (formát `YYYYMMNNNN`), zapíše snapshot klienta/dodavatele/banky. Vrací plný detail.

### `POST /invoices/{id}/mark-paid`

```json
{ "paid_at": "2026-05-05" }
```

Default = today. Přechod issued/sent/reminded → paid.

### `POST /invoices/{id}/cancel`

```json
{ "mode": "internal" | "credit_note", "reason": "..." }
```

- `mode=internal` — vytvoří `cancellation` s `parent_invoice_id`, na původní faktuře `cancelled_at`. Pro klienta žádný doklad. Response: `{ "cancellation_id": 144 }`.
- `mode=credit_note` — vytvoří **draft** `credit_note` se zápornými položkami. User musí v editoru zkontrolovat a kliknout `/issue`. Původní faktura je označena `cancelled` až po vystavení dobropisu. Response: `{ "credit_note_id": 145, "edit_url": "/invoices/145" }`.

### `POST /invoices/{id}/clone`

```json
{ "increment_month_in_descriptions": true, "issue_date": "2026-05-30" }
```

Vytvoří nový draft podle zdrojové faktury (kopie všech položek + work_report). Při `increment_month_in_descriptions=true` (default): regex `/\b(\d{1,2})\/(\d{4})\b/` na `description` u `items[]` a `title`/`description` u `work_report`, M/Y → (M+1)/(Y) nebo (1)/(Y+1) když M=12.

### `POST /invoices/{id}/issue-final`

**Jen pro proformu se statusem `paid`.** Vystaví finální daňový doklad k zaplacené záloze.

```json
{
  "tax_date": "2026-05-15",
  "due_date": "2026-05-15",
  "advance_paid_amount": null
}
```

Vytvoří **draft** typu `invoice` s `parent_invoice_id`, kopií položek, `advance_paid_amount` (default = `proforma.total_with_vat`), `amount_to_pay = total_with_vat - advance_paid_amount` (typicky 0). User pak vystaví přes `/issue`.

### `POST /invoices/bulk-reissue`

```json
{
  "invoice_ids": [101, 102, 103],
  "increment_month_in_descriptions": true,
  "issue_date": null
}
```

Pro každou fakturu vytvoří draft (logika klonování + auto-increment měsíce). **Žádný draft není automaticky vystaven ani odeslán.**

```json
{
  "created": [
    { "source_id": 101, "draft_id": 201 },
    { "source_id": 102, "draft_id": 202 }
  ],
  "errors": []
}
```

### `GET /invoices/{id}/pdf`

`application/pdf`, `Content-Disposition: inline; filename="Faktura-26-04-001.pdf"`. Query: `?download=1` → `attachment`, `?regenerate=1` → ignore cache.

### `GET /invoices/{id}/pdfs`

Seznam archivovaných PDF snapshotů (každý send/regenerate ukládá kopii). Vrací `[{ "id": 1, "created_at": "...", "size_bytes": 12345, "trigger": "issue|send|manual|reissue" }, ...]`.

### `GET /invoices/{id}/pdfs/{archiveId}`

Stáhne konkrétní archivovanou verzi.

### `POST /invoices/{id}/send`

```json
{
  "to": ["billing@acme.cz"],
  "cc": [], "bcc": [],
  "subject_override": null, "body_override": null,
  "language": null
}
```

Default `to` = `client.main_email + project.billing_emails`. Response 200: `{ "sent_to": [...], "sent_at": "...", "message_id": "..." }`. Funguje jen na `issued+`.

### `POST /invoices/{id}/send-test`

Pošle fakturu **pouze na `cfg.smtp.from_email`** (odesílatel sám sobě). Body volitelně `{ "language": null, "subject_prefix": "[TEST] " }`. `to/cc/bcc` se ignoruje.

Důsledky: `invoice.sent_at` se **nenastaví**, status se **nezmění**, activity log = `email.sent_test`. Funguje i pro `draft`.

### `POST /invoices/{id}/reminder`

Pošle upomínku k nezaplacené faktuře po splatnosti. Email používá šablonu `invoice_reminder` (locale dle `invoice.language`). Body shodný se `/send` (override recipientů, language, atd.). Nastaví `reminded_at` a status `reminded` (pokud byl `issued/sent`). Errors: `409 already_paid`, `409 not_overdue` (pokud `due_date` ještě nenastal).

### `POST /invoices/{id}/reminder-test`

Test verze — pošle upomínku jen na `cfg.smtp.from_email`. `reminded_at` se nenastaví. Activity log `email.reminder_test`.

### `POST /invoices/bulk-reminder`

```json
{ "invoice_ids": [101, 102, 103] }
```

Hromadné upomínky pro vybrané faktury. Response shape stejný jako `bulk-reissue` (`created[]`, `errors[]`).

### `GET /invoices/{id}/activity`

Activity log filtrovaný na tuto fakturu (kdo kdy co — issue/send/cancel/reminder/edit/...).

---

## 7. Work reports (výkaz víceprací)

### `GET /invoices/{id}/work-report` — detail výkazu nebo 404.

### `PUT /invoices/{id}/work-report`

```json
{
  "title": "Vícepráce za měsíc 4/2026",
  "project_id": 7,
  "items": [
    { "description": "Refaktor login flow", "hours": 4.5, "rate": 1500, "order_index": 0 },
    { "description": "Bugfix QR generátor", "hours": 1.0, "rate": 1500, "order_index": 1 }
  ],
  "auto_create_invoice_item": true
}
```

Upsert (`PUT` slouží jako create i update). Pokud `auto_create_invoice_item=true`, přidá/aktualizuje řádek faktury "Vícepráce za měsíc M/Y". Recompute sumy.

### `DELETE /invoices/{id}/work-report`

Smaže výkaz. Volitelně `?remove_linked_item=1` smaže i navázanou položku faktury.

---

## 8. Schvalování výkazu zákazníkem

### `POST /invoices/{id}/request-approval`

Pošle klientovi email s veřejným tokenem k odsouhlasení výkazu/faktury. Body:

```json
{
  "to": ["pm@acme.cz"],
  "expires_in_days": 14,
  "message": "Prosím o schválení výkazu za duben."
}
```

Response: `{ "approval_id": 12, "token": "...", "url": "https://.../approval/<token>" }`.

### `POST /invoices/{id}/request-approval-test`

Test verze — pošle žádost na `cfg.smtp.from_email`. Token se vytvoří, ale je `is_test=1` a nelze přes něj fakturu reálně schválit.

### `PUT /invoices/{id}/approval-status`

Manuální override stavu schvalování (admin). Body: `{ "status": "approved|rejected|none", "comment": "..." }`.

### `GET /api/public/approval/{token}`

**Bez auth.** Vrací data faktury + work_report pro veřejný náhled. Token musí mít hex 32-128 znaků.

### `POST /api/public/approval/{token}/decide`

**Bez auth.** Body:

```json
{ "decision": "approved" | "rejected", "comment": "..." }
```

Zaznamená rozhodnutí, pošle notifikaci dodavateli. Token po prvním rozhodnutí expiruje. Errors: `410 token_expired`, `409 already_decided`, `404 invalid_token`.

---

## 9. Dashboard

### `GET /dashboard/summary`

```json
{
  "unpaid_count": 5, "unpaid_total": 123450,
  "overdue_count": 2, "overdue_total": 45000,
  "this_month_issued": 8, "this_month_total": 234000,
  "this_year_issued": 32, "this_year_total": 1234000,
  "recent_invoices": [ ... ],
  "approvals_pending": 1
}
```

Aktuálně dle `X-Supplier-Id`.

---

## 10. Admin

> Všechny `/api/admin/*` vyžadují `role=admin` (`RoleMiddleware`).

### `GET /admin/activity-log`

Query: `filter[user_id]`, `filter[action]`, `filter[entity_type]`, `filter[entity_id]`, `from`, `to`, paginated.

### `GET /admin/approvals`

Globální seznam všech schvalovacích žádostí (přes všechny faktury). Query: `filter[status]`, `filter[client_id]`, paginated.

### `GET /admin/invoices-zip`

Legacy endpoint pro ZIP export PDF za měsíc. Query: `?month=YYYY-MM[&type=invoice|proforma|credit_note]`. Drží se kvůli historickým bookmark URL — pro nové integrace použijte `/admin/export?format=pdf-zip`.

### `GET /admin/export`

Generic export. Query:
- `?format=pdf-zip&month=YYYY-MM` — ZIP s PDF faktur za měsíc (legacy zkratka)
- `?format=isdoc&period=monthly&year=YYYY&month=M` — ZIP s ISDOC XML za měsíc
- `?format=pohoda&period=quarterly&year=YYYY&quarter=1..4` — Pohoda XML za celé čtvrtletí

Společné volitelné parametry:
- `type=invoice|proforma|credit_note` — omezení typu vystaveného dokladu
- `date_by=issue|tax` — filtrování podle data vystavení nebo DUZP (`tax_date`, fallback `issue_date`)

### `POST /admin/import`

Multipart upload. Akceptuje:
- Pohoda XML (single soubor)
- ISDOC XML (single)
- ZIP s libovolnou kombinací výše

Vystavené faktury se importují s původním `varsymbol` (zachová číselnou řadu). Response:
```json
{
  "imported": 12, "skipped_duplicates": 3, "errors": [
    { "filename": "FA-2024-001.xml", "error": "..." }
  ]
}
```

### Users

| Endpoint | Co dělá |
|---|---|
| `GET /admin/users` | Seznam |
| `POST /admin/users` | `{ email, name, role, locale, password }` (initial password emailem) |
| `PUT /admin/users/{id}` | kromě hesla (na to je `/auth/change-password` nebo `/auth/reset`) |
| `DELETE /admin/users/{id}` | soft (`is_active=0`); nikdy hard delete (FK do `activity_log`) |

### Email šablony

Šablony emailů (subject + body, Twig syntax). Per-locale (`cs`/`en`).

| Endpoint | Co vrací |
|---|---|
| `GET /admin/email-templates` | Seznam: `[{ code, locale, subject, updated_at, is_default }, ...]`. Pokud uživatel nemá custom, vrátí default ze souboru. |
| `GET /admin/email-templates/{code}/{locale}` | Plný obsah šablony. `code` je např. `invoice_send`, `invoice_reminder`, `approval_request`. |
| `PUT /admin/email-templates/{code}/{locale}` | `{ subject, body }` — uloží custom override. |
| `DELETE /admin/email-templates/{code}/{locale}` | Smaže custom override → fallback na default. |

---

## 11. Multi-supplier (admin)

Spravuje samotné dodavatele (na rozdíl od `/api/settings/supplier`, které edituje aktuálního). Vidí jen admin.

| Endpoint | Co dělá |
|---|---|
| `GET /suppliers` | Seznam všech dodavatelů (id, company_name, ic, default flag). |
| `POST /suppliers` | Vytvoří nového. Body = pole z `supplier` tabulky. |
| `GET /suppliers/{id}` | Plný detail včetně `bank_accounts[]`. |
| `PUT /suppliers/{id}` | Update. |
| `DELETE /suppliers/{id}` | Jen pokud nemá faktury. Není možné smazat posledního. |

---

## 12. Settings (aktuální supplier dle `X-Supplier-Id`)

### `GET /settings/supplier` / `PUT /settings/supplier`

Data aktuálního dodavatele včetně `bank_accounts[]` a logo path. PUT akceptuje partial update.

### Currencies

| Endpoint | Co dělá |
|---|---|
| `GET /settings/currencies` | Seznam měn s aktivními bankovními účty pro aktuální supplier. |
| `POST /settings/currencies` | `{ code, account_number, bank_code, bank_name, iban, bic, is_default }` — přidá další účet. |
| `PUT /settings/currencies/{id}` | Update účtu (id = `bank_accounts.id`). |
| `DELETE /settings/currencies/{id}` | Smaže účet. Nelze smazat poslední účet pro currency, která je použitá ve fakturách. |

### VAT rates

| Endpoint | Co dělá |
|---|---|
| `GET /settings/vat-rates` | Seznam VAT sazeb (per-supplier override + globální defaults). |
| `POST /settings/vat-rates` | `{ country_iso2, rate, label, valid_from, valid_to }`. |
| `PUT /settings/vat-rates/{id}` | Update. |
| `DELETE /settings/vat-rates/{id}` | Soft (nelze smazat sazbu použitou na vystavené faktuře — snapshoty jsou bezpečné, ale UI by ji ztratilo). |

### Countries

`GET/POST/PUT/DELETE /settings/countries[/{id}]` — per-supplier custom země (nad rámec ISO 3166-1).

---

## 13. Bank statements (M5b)

### `POST /bank-statements/upload`

Multipart upload (CSV/XML/ABO). Parser detekuje formát. Response:

```json
{
  "statement_id": 5,
  "imported_transactions": 42,
  "auto_matched": 15,
  "needs_review": 27
}
```

### `POST /bank-statements/scan`

Spustí re-scan: pokusí se znovu auto-spárovat transakce, které byly uloženy jako `unmatched` (např. po nově vystavené faktuře). Nezávislé na upload.

### `GET /bank-statements`

Seznam výpisů. Paginated.

### `GET /bank-statements/{id}`

Detail výpisu + všechny transakce s match info.

### `POST /bank-transactions/{id}/match`

```json
{ "invoice_id": 123 }
```

Manuální spárování transakce s konkrétní fakturou. Pokud je shoda v částce a měně, faktura se automaticky označí jako paid (s `paid_at = transaction.value_date`).

### `POST /bank-transactions/{id}/unmatch`

Rozváže párování (faktura se ale **nevrátí** automaticky do unpaid — admin musí mark-paid manuálně přepnout). Užitečné při omylem spárované transakci.

### `POST /bank-transactions/{id}/ignore`

Označí transakci jako ignorovanou (nezahrne do reportů, neptá se na párování).

---

## 14. Rate limity

| Endpoint | Limit |
|---|---|
| `POST /auth/login` | 10/min/IP, navíc brute-force guard per email+IP/24 |
| `POST /auth/forgot` | 3/hod/email, 10/hod/IP |
| `POST /auth/reset` | 5/hod/IP |
| `POST /clients/lookup-ares` | 30/min/user (chrání ARES) |
| `POST /clients/lookup-vies` | 30/min/user |
| `POST /auth/setup-ares-lookup` | 30/min/IP |
| Ostatní mutace | 60/min/user |
| GET endpointy | 300/min/user |

Rate-limit response 429:
```json
{ "error": { "code": "rate_limited", "message": "...", "retry_after": 45 } }
```
Header: `Retry-After: 45`.

---

## 15. Auth-free routes (bez `AuthMiddleware`)

- `GET /health`
- `GET /auth/setup-status`
- `POST /auth/setup`, `/auth/setup-ares-lookup`, `/auth/setup-sample`
- `POST /auth/login`, `/auth/forgot`, `/auth/reset`
- `GET /codebooks/*` (pomáhá login screen lokalizovat)
- `GET /api/public/approval/{token}` + `POST /api/public/approval/{token}/decide`

---

## 16. CSRF

Všechny `POST/PUT/PATCH/DELETE` mimo public endpointy (login/forgot/reset/setup, public approval) vyžadují header `X-CSRF-Token`. Token získá klient z odpovědi `/auth/login` nebo `/auth/me` a Pinia store ho automaticky přidá axios interceptorem.

---

## 17. 404 fallback

Cokoli pod `/api/*`, co neodpovídá žádné registrované cestě, vrací:

```json
{ "error": { "code": "not_found", "message": "Route not found" } }
```

s HTTP 404.

---

## 18. Příklad full-flow: vystavení faktury z předchozí

```
1.  GET  /api/invoices?filter[client_id]=42&sort=-issue_date&per_page=1
        → poslední faktura, id=120
2.  POST /api/invoices/120/clone
        → nový draft id=121, status=draft, varsymbol=null,
          popisky mají zvednutý měsíc (3/2026 → 4/2026)
3.  PUT  /api/invoices/121
        → uživatel upraví množství, položky
4.  PUT  /api/invoices/121/work-report
        → updatuje výkaz víceprací (volitelně)
5.  POST /api/invoices/121/issue
        → varsymbol=2026040002, snapshots uloženy, status=issued
6.  GET  /api/invoices/121/pdf
        → PDF ke stažení
7.  POST /api/invoices/121/send
        → email s PDF přílohou na main + billing emails
        → status=sent, sent_at nastaven
```

S volitelným schvalovacím krokem mezi 5 a 7:

```
6a. POST /api/invoices/121/request-approval
        → klient dostane email s tokenem
6b. (klient klikne na link a /api/public/approval/{token}/decide)
        → status `approval=approved`, lze pokračovat odesláním
```

---

## 19. OpenAPI / Swagger

Tento dokument není OpenAPI spec a není automaticky generovaný — drží se jako editovaný overview pro vývojáře a externí konzumenty (žádní dnes nejsou; SPA + API jsou jeden monorepo deploy).

**Možnosti vygenerování OpenAPI:**

1. **Anotace v PHP přes `zircote/swagger-php`** — anotace `#[OA\Get(...)]` nad každou Action třídou. Plus: 100 % synchronní s kódem. Mínus: ~20–30 anotací na endpoint × ~80 endpointů ≈ velký jednorázový boilerplate, údržba u každé změny shape. Generování přes `vendor/bin/openapi src/ -o public/openapi.json`.
2. **Ruční `openapi.yaml`** — napsat jeden spec soubor (~1500 řádků) a hostovat přes Swagger UI nebo Redoc na `/api/docs`. Plus: rychlejší než anotovat, lepší pro veřejnou dokumentaci. Mínus: může se rozejít s kódem (žádný compile-time check).
3. **Reflexe + heuristika** — script projde `Routes.php`, spáruje na Action třídy, z konstruktoru vyčte service deps, ze `__invoke` parsuje validation rules. Plus: low-touch. Mínus: shape requestu/response stejně musí jít do anotací nebo doc-bloků; ušetří jen cestu+method.

**Doporučení:** pokud OpenAPI bude nutný (veřejné API, partner integrace, generované klienty), nejjistější cesta je **(1) `zircote/swagger-php` se schématy v `#[OA\Schema]`** atributech na entitách (`Invoice`, `Client`, …) a `#[OA\Response]`/`#[OA\RequestBody]` na Actions. Schémata se sdílejí přes `$ref`. Odhad rozsahu: 3–4 dny implementace, pak **automatický `/api/openapi.json`** + Swagger UI na `/api/docs`. Nutné dodat:
- composer dependence `zircote/swagger-php` a `swagger-api/swagger-ui` (CDN stačí).
- Build krok / cache (anotace se parsují za runtime, kešovat do souboru).
- CI test, že spec validuje (`spectral lint`).

Pokud je cíl jen **interní reference**, postačí tento markdown — udržuje se rychleji než OpenAPI a je čitelný v repu.
