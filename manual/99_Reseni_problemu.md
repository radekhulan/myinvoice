# 99. Řešení problémů (FAQ)

## 99.1 Přihlášení

### Zapomenuté heslo

Klik **Zapomenuté heslo?** na login → zadej e-mail → klik na odkaz v e-mailu
(platnost 1 h).

Pokud e-mail nedorazí:

- Zkontroluj spam.
- Ověř s adminem, že máš nakonfigurované SMTP (`cfg.php → smtp.*`).
- Krajní řešení: admin spustí `php api/bin/set-password.php tvuj@email.cz`.

### „Origin nesedí s app URL"

CSRF check selhal. Příčiny:

- **`cfg.php → app.url`** nesedí s URL, na kterou chodíš. Příklad: chodíš na
  `http://localhost:8080`, ale v cfg je `https://dev.example.com`. Oprav v cfg.
- Reverse proxy / IIS bez správně nastaveného Host headeru. Zkontroluj,
  že server vidí původní hostname.

### „Aplikace ještě není inicializována" (HTTP 423)

Setup wizard ještě neproběhl. Otevři `/setup` v prohlížeči.

Pokud setup wizard nefunguje (špatně nakonfigurovaná DB):

```bash
php api/bin/migrate.php --status     # zkontroluj, že DB má migrace
php api/bin/setup.php                # interaktivní fallback z CLI
```

### Lockout po brute-force

Po 10 neúspěšných pokusech / 15 min jsi zablokovaný na 15 min. Po 30 / hod
na 24 h. Počkej, nebo požádej admina o reset z DB:
`DELETE FROM login_attempts WHERE bucket_key LIKE '%tvuj_email%';`

### 2FA — ztratil jsem telefon

MyInvoice nemá záložní kódy ani UI pro deaktivaci 2FA. Doporučený postup je
CLI rescue:

```bash
php api/bin/reset-2fa.php tvuj@email.cz
```

Po resetu se přihlásíš jen s heslem a 2FA si znovu aktivuješ na novém telefonu.
Detail viz [§ 18.2.3](18_Bezpecnost.md).

Pokud nemáš shell přístup ke kontejneru/serveru, použij legacy SQL fallback:

```sql
UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE email = 'tvuj@email.cz';
```

### Varování `secret_encryption_key` (špatná délka klíče)

Od v3.1.0 backend vrací v `GET /api/health` pole `warnings[]` a admin vidí
upozornění i v UI (**Systém → Aktualizace**), pokud je problém s
`app.secret_encryption_key` (typicky omyl: 24B místo 32B).

Oprav konfiguraci v `cfg.php` / `cfg.docker.php`:

```bash
openssl rand -base64 32
```

Vygenerovanou hodnotu ulož do `app.secret_encryption_key`. Klíč musí být
base64, který po dekódování dává přesně 32 bajtů.

## 99.2 Faktury

### Nemůžu editovat vystavenou fakturu

Schválně. Vystavená faktura je **immutable** (snapshot dodavatele, klienta,
banky). Pokud potřebuješ změnu:

- **Drobná chyba (překlep, špatná částka)** → admin: detail faktury → klik
  **Editovat (force)**, vyžaduje admin roli, zaloguje se v activity logu.
- **Klient ji ještě nedostal** → udělej **Storno** (interní) + nová.
- **Klient ji už dostal** → udělej **Dobropis** (oficiální oprava) + nová.

### Klonování / „Vystavit znovu" inkrementuje měsíc špatně

Inkrement funguje pro popisy obsahující vzor `M/YYYY` (např. „Konzultace
3/2026" → „Konzultace 4/2026"). Pokud máš vzor jiný (např. „březen 2026"),
musíš ručně.

### QR platba se na PDF nezobrazuje

Bankovní účet musí projít **mod-11 kontrolou** (CZ účty) nebo **IBAN
checksum** (EUR). Zkontroluj v **Systém → Číselníky → Měny**, jestli máš
platný účet. Příklad platného CZ testovacího účtu: `1000000005 / 0100`.

### Faktura má v PDF špatné údaje dodavatele

Vystavená faktura má snapshot v `supplier_snapshot` (JSON). Pokud jsi po
vystavení změnil údaje dodavatele (logo, adresa, …), faktura zůstává
s původními. **Toto je zamýšlené** — vystavený doklad nelze měnit.

Pokud potřebuješ regenerovat PDF s novými údaji (např. opravil jsi překlep
v názvu firmy), použij **Editovat (force)** s admin rolí.

## 99.3 E-maily

### Faktura odešla, ale klient ji nedostal

1. Zkontroluj v **Systém → Activity log** záznam `invoice.sent` — měl by být
   s adresou klienta.
2. Zkontroluj log SMTP serveru (mailhog / SMTP relay).
3. Klient: zkontroluje spam.
4. Pošli **Test odeslání** na svůj e-mail — pokud nedorazí, problém je v SMTP
   konfiguraci.

### „Test odeslání" funguje, ale klientovi nic nechodí

- E-mail klienta v MyInvoice je špatný (typo) → uprav v detailu klienta.
- Klient má restriktivní spam filtr → zkontroluj, jestli máš správně
  nastavený SPF + DKIM + DMARC pro doménu, ze které posíláš.

### DKIM podpis se nedaří aktivovat

1. Vygeneruj klíče: viz [18. Bezpečnost § 16.8](18_Bezpecnost.md).
2. Publikuj DNS TXT — počkej 5–60 minut na propagaci.
3. Ověř DKIM přes [mxtoolbox.com](https://mxtoolbox.com/dkim.aspx).
4. Až DNS funguje, zapni v `cfg.php → smtp.dkim.enabled => true`.

## 99.4 Banka

### GPC výpis se nenahraje („tento výpis už byl importovaný")

SHA-256 hash souboru se shoduje s nějakým dříve importovaným. Buď:

- Skutečně už je naimportovaný (zkontroluj **Banka → Výpisy**)
- Stáhl jsi stejný výpis 2× → použij jiný (nebo si vyžádej z banky export
  s jiným časovým rozsahem)

### Auto-matching nefunguje

- Klient nezadal **variabilní symbol** → musíš spárovat ručně
- Částka neodpovídá (klient zaplatil méně, kurz EUR/CZK, bankovní poplatek) →
  manuální párování s checkmarkem „částečná platba"
- Faktura je v jiné měně než platba (klient pošle EUR na CZK fakturu) →
  manuálně, doúčtuj kurzový rozdíl

### Bankovní účet z výpisu „nepatří aktuálnímu dodavateli"

Multi-supplier ochrana — výpis musí být z účtu, který je v **Systém →
Číselníky → Měny** aktuálního dodavatele. Pokud chceš nahrát výpis pro jiného
dodavatele, **přepni na něj** přes přepínač v horní liště.

## 99.5 Exporty

### ISDOC import do Pohody hodí chybu

ISDOC je univerzální standard, ale Pohoda má vlastní quirks. Doporučujeme
spíš **Pohoda XML export** (nativní formát), pro kterého je import
spolehlivější.

### Pohoda XML import vyžaduje kódy

Před exportem nastav v **Systém → Dodavatelé → [tvůj] → záložka Pohoda**:
číselnou řadu, středisko, činnost, předkontace. Bez toho Pohoda hlásí varování
při importu.

### Měsíční PDF ZIP je velký (>100 MB)

Normální při ~100 fakturách/měsíc s 2. stranou výkazu. Pokud chceš menší ZIP,
exportuj jen menší rozsah období (1 týden místo měsíce).

## 99.6 Cron / automatika

### Cron upomínek odeslal víc upomínek za den

Buď cron je spuštěný 2× (zkontroluj `crontab -l` / Task Scheduler), nebo
`--cooldown` je moc krátký. Default 14 dní by neměl pouštět více než 1 upomínku
na fakturu / 14 dní.

### Bank scan cron neimportuje nové výpisy

1. Zkontroluj, že soubory v `private/bank-incoming/` mají správný formát
   (ABO/GPC, ne XML).
2. Zkontroluj práva: `chmod -R 0750 private/`.
3. Spusť ručně: `php api/bin/cron-bank-scan.php` — uvidíš error.

## 99.7 Výkon

### Dashboard se otevírá pomalu

Stats cache možná chybí. Spusť `php api/bin/recompute-stats.php` — přepočítá
`project_revenue_cache` + `client_revenue_cache`.

### Aplikace pomalu reaguje pod zátěží

- Zapni Redis (`cfg.php → redis.enabled => true`) — sessions + brute-force
  cache jdou do paměti místo DB
- Zkontroluj `cfg.php → app.debug => false` v produkci (debug logs jsou
  drahé)
- Sledování v `log/app-YYYY-MM-DD.log` (pomalé queries = `slow_query` v DB)

## 99.8 Multi-supplier

### Po přepnutí dodavatele vidím prázdný seznam klientů

Klienti jsou per-dodavatel izolovaní. Buď přepneš zpět na původního, nebo si
v aktuálním dodavateli vytvoř klienty znovu (nelze migrovat klienta mezi
dodavateli — záměrně).

### Faktura mi nešla vystavit, hlásí „klient nepatří aktuálnímu dodavateli"

Multi-supplier guard. Buď přepni na dodavatele klienta, nebo si v aktuálním
vytvoř toho samého klienta (oddělená data).

## 99.9 Hlášení chyb

Pokud problém nevyřeší tato kapitola, kontaktuj:

- **GitHub Issues** repo MyInvoice.cz
- E-mail vývojáře — viz `cfg.php → smtp.from`
- IT administrátor tvé organizace

Užitečné pro hlášení:

- Verze (`/api/health` v prohlížeči ukáže)
- Browser / OS
- Krok-po-kroku, jak chybu reprodukovat
- Screenshot
- Excerpt z `log/app-YYYY-MM-DD.log` v okolí chyby
