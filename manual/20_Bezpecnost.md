# 20. Bezpečnost (2FA, IP allowlist, role, activity log)

Bezpečnost MyInvoice stojí na 4 vrstvách:

1. **Autentizace** — bcrypt hesla + peppered + brute-force ochrana + CAPTCHA
2. **2FA** — volitelné druhé ověření: TOTP (mobilní aplikace) nebo e-mailový kód
3. **Síťová izolace** — IP allowlist (volitelný, doporučeno v produkci)
4. **Autorizace** — role-based access (admin / accountant / readonly)
5. **Audit** — activity log všech mutací

## 20.1 Hesla

| Vrstva | Detail |
|---|---|
| Algoritmus | bcrypt cost 12 |
| Pepper | Sůl z `cfg.php → app.pepper` (32B base64), neukládá se v DB |
| Min. délka | 12 znaků |
| Max. délka | Bez limitu — passphrase je doporučená (20+ znaků) |
| Kontrola síly | Indikátor v UI (slabé / střední / silné) |
| Reset hesla | Odkaz na 1 hodinu, e-mailem |

> 💡 **Passphrase je bezpečnější než krátké složité heslo.** „korelace medvědí
> dýně přístav 2026" má 49 znaků a je odolnější vůči brute-force než „Hu1@n!22".

## 20.2 Dvoufaktorové ověření (TOTP)

TOTP = time-based one-time password (RFC 6238). Nejznámější standard pro 2FA.

### 20.2.1 Aktivace

**Můj profil → 2FA → Aktivovat**.

![Aktivace 2FA](img/16_2fa_setup.webp)

1. Aplikace ukáže **QR kód** + textový **secret key**.
2. V mobilu otevři **autentikátor** (Google Authenticator, Authy, Microsoft
   Authenticator, 1Password, Bitwarden) → Přidat účet → Sken QR kódu.
3. Aplikace začne generovat 6-cifrené kódy každých 30 sekund.
4. Zadej aktuální kód do MyInvoice → **Potvrdit aktivaci**.

> ⚠️ MyInvoice **nepoužívá záložní jednorázové kódy** (recovery codes).
> Při ztrátě autentikátoru použij CLI rescue:
> `php api/bin/reset-2fa.php <email>` —
> viz [§ 20.2.3](#2023-ztrata-telefonu--deaktivace).

### 20.2.2 Přihlášení s 2FA

Po zadání e-mailu + hesla aplikace vyzve k 6-cifernému kódu z autentikátoru.

![2FA výzva](img/04_2fa.webp)

Pokud autentikátor nemáš po ruce, nezbývá než provést rescue reset
(následující sekce).

### 20.2.3 Ztráta telefonu / deaktivace

Aplikace nemá UI pro deaktivaci 2FA — doporučený postup je CLI rescue tool:

```bash
php api/bin/reset-2fa.php tvuj@email.cz
```

Skript nastaví `totp_enabled = 0` a `totp_secret = NULL` pro zadaný účet.
Pak se přihlásíš jen s heslem a 2FA si můžeš znovu aktivovat na novém telefonu
(Můj profil → 2FA → Aktivovat).

Pokud **nemáš shell přístup ke kontejneru/serveru**, použij SQL fallback:

```sql
UPDATE users
SET totp_enabled = 0, totp_secret = NULL
WHERE email = 'tvuj@email.cz';
```

> ⚠️ Pro produkční nasazení doporučujeme mít k DB přístup přes admin
> (phpMyAdmin / Adminer / mysql CLI) připravený předem. Při ztrátě telefonu
> by jinak nikdo nešel do aplikace.

### 20.2.4 Vynucení 2FA pro všechny uživatele

Pokud chceš, aby **každý** uživatel po přihlášení musel mít aktivní TOTP,
nastav v `cfg.php` (nebo `cfg.local.php`):

```php
'auth' => [
    'require_totp' => true,
],
```

Stejné lze přepnout přes ENV (Docker / PaaS):

```bash
MYINVOICE_AUTH_REQUIRE_TOTP=true
```

Chování:

- Po loginu (s heslem, bez TOTP) je uživatel přesměrován na `/setup-totp`,
  kde naskenuje QR a aktivuje 2FA. Před aktivací není přístup do žádné
  jiné části aplikace.
- Backend tvrdě blokuje volání všech endpointů kromě
  `/api/auth/me`, `/api/auth/logout` a `/api/auth/totp/*`. Frontend bypass
  není možný.
- Jediná „escape route" je odhlášení (tlačítko na `/setup-totp`).

> 💡 Volbu lze zapnout i v instalačních skriptech:
> - **CLI**: `php api/bin/setup.php` se ptá *„Vynutit 2FA?"* a v případě
>   souhlasu zapíše `auth.require_totp = true` do `cfg.local.php`.
> - **Web wizard** (`/setup`): checkbox v kroku „Admin účet" má stejný
>   efekt; po dokončení je admin rovnou přesměrován na `/setup-totp`.

> ⚠️ Vyžaduje validní `app.secret_encryption_key` (32B base64). Při špatné
> konfiguraci by uživatelé skončili v silent-500 — health endpoint vrací
> warning, viz [§ 99 Řešení problémů](99_Reseni_problemu.md).

### 20.2.5 E-mailové ověření (pro uživatele bez authenticator app)

Pro uživatele, kteří nechtějí (nebo neumí) authenticator aplikaci — typicky
externí účetní — lze zapnout **e-mailové OTP** jako druhý faktor. Kdo nemá
aktivní TOTP, dostane po zadání hesla 6místný kód na e-mail a musí ho opsat.

Zapnutí v `cfg.php` (výchozí stav je **vypnuto** — nejde o breaking change):

```php
'auth' => [
    'email_otp' => [
        'enabled'                 => true,  // vyžadovat e-mailový kód u uživatelů bez TOTP
        'code_ttl_minutes'        => 10,    // platnost kódu
        'max_attempts'            => 5,     // pokusů na jeden kód, pak je nutný nový
        'resend_cooldown_seconds' => 60,    // min. prodleva mezi odesláním nového kódu
        'trusted_device_days'     => 30,    // „zapamatovat toto zařízení" na kolik dní
        'trusted_cookie_name'     => '__Host-myinvoice_td',
    ],
],
```

Chování:

- **Priorita TOTP.** Má-li uživatel aktivní authenticator app, vyžaduje se
  TOTP a e-mailové OTP se neuplatní. E-mailový kód je pouze fallback.
- **Po heslu** se zobrazí pole pro kód z e-mailu + tlačítko *„Kód nedorazil?
  Odeslat znovu"* s odpočtem (cooldown). Kód je jednorázový a hashovaný v DB
  (sloupec `login_otps.code_hash`, nikdy plaintext).
- **„Zapamatovat toto zařízení na 30 dní"** (checkbox) vystaví cookie
  důvěryhodného zařízení; na něm se druhý faktor po danou dobu nevyžaduje.
  Heslo se vyžaduje vždy. Týká se jen e-mailového OTP, ne TOTP.
- **Brute-force.** Šestimístný kód je chráněn per-user lockoutem (10 selhání /
  10 min) stejně jako TOTP.

> ⚠️ Vyžaduje funkční **SMTP**. Když e-maily nechodí, uživatelé bez TOTP se
> nepřihlásí — buď oprav SMTP, nebo nastav `enabled => false`. Nouzově lze
> uživateli zrušit i důvěryhodná zařízení a čekající kódy:
> `php api/bin/reset-2fa.php <email>` (vedle vypnutí TOTP smaže i
> `trusted_devices` a `login_otps` daného účtu).

## 20.3 Brute-force ochrana

| Pokusy během | Akce |
|---|---|
| 5 selhání / 5 minut | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 minut | Lockout 15 minut (per IP) |
| 30 selhání / 1 hodinu | Lockout 24 hodin + e-mail uživateli o pokusech |

Implementace: **Redis** pokud běží, jinak **MariaDB MEMORY engine** fallback.

## 20.4 IP allowlist (volitelné)

V `cfg.php → ip_allowlist.allow` můžeš omezit přístup jen na vybrané IP /
CIDR rozsahy.

```php
'ip_allowlist' => [
    'enabled' => true,
    'mode' => 'block',           // 'block' = ne-allowlisted IP dostane 403
    'allow' => [
        '127.0.0.1',
        '203.0.113.42',          // tvoje kancelářská WAN (IPv4)
        '2001:db8:1234::/48',    // IPv6 prefix
    ],
],
```

Doporučení v produkci:

- Tvá kancelářská IP
- VPN endpoint (pokud používáš)
- Rezervní mobilní hotspot pro nouzový přístup

> 🛈 IP allowlist je v `cfg.php` (file-based config) → změna vyžaduje SSH /
> deploy. Není v UI **schválně** — v případě omylu by ses zablokoval
> a nemohl si ho přes UI sundat.

### 20.4.1 Za reverse proxy: `trusted_proxies` (důležité)

Pokud aplikace běží **za reverse proxy** (doporučené produkční nasazení — viz
kap. 2), vidí všechny požadavky přicházet z IP proxy (např. brána Dockeru
`172.x.0.1`), ne od reálného klienta. Bez konfigurace pak:

- **IP allowlist** filtruje podle IP proxy — buď zablokuje všechny, nebo (když
  přidáš proxy do `allow`) pustí všechny → ochrana je neúčinná.
- **Brute-force lockout** (kap. 20.3) je fakticky **globální** — všechny pokusy
  vypadají ze stejné IP.
- **Audit log** loguje IP proxy místo reálného klienta (ztráta forenzní hodnoty).

Proto za reverse proxy uveď proxy do `trusted_proxies` — aplikace pak vezme
skutečnou klientskou IP z hlavičky `X-Forwarded-For`:

```php
'ip_allowlist' => [
    'trusted_proxies' => [
        '172.16.0.0/12',         // Docker bridge sítě
        // '10.0.0.0/8',         // nebo konkrétní IP/rozsah tvé proxy
    ],
    'header' => 'X-Forwarded-For', // výchozí; odkud číst reálnou IP (jen za trusted proxy)
],
```

> ⚠️ Do `trusted_proxies` patří **jen** IP/rozsahy proxy, kterým věříš —
> klient za nedůvěryhodnou proxy by jinak mohl `X-Forwarded-For` podvrhnout.
> Aplikace hlavičku respektuje pouze tehdy, když `REMOTE_ADDR` odpovídá
> `trusted_proxies`.

## 20.5 RBAC (role-based access)

Tři role. Hierarchie: **admin > accountant > readonly**.

| Schopnost | admin | accountant | readonly |
|---|:---:|:---:|:---:|
| Prohlížení dat (faktury, klienti, zakázky, banka, CRM, statistiky) | ✅ | ✅ | ✅ |
| **Exporty** (PDF / ISDOC / Pohoda / ZIP) | ✅ | ✅ | ✅ |
| **Daňové výkazy** (DPH, KH, SHV, daň z příjmů, kniha DPH, archiv EPO) — náhled i stažení XML/PDF | ✅ | ✅ | ✅ |
| Vystavování a editace dokladů, klienti, zakázky, recurring | ✅ | ✅ | ❌ |
| Import faktur, párování / nahrávání bankovních výpisů | ✅ | ✅ | ❌ |
| Editace / smazání **vystavené** faktury (force) | ✅ | ❌ | ❌ |
| Konfigurace systému (nastavení, číselníky, integrace, e-mail šablony) | ✅ | ❌ | ❌ |
| Správa uživatelů, activity log, cron, schvalování | ✅ | ❌ | ❌ |

**Klíčový princip:** `readonly` vidí **přesně totéž co `accountant`** (včetně exportů
a daňových výkazů — to vše jsou operace čtení) a smí **data exportovat**, ale
**nesmí nic vytvořit, upravit ani smazat**. Rozdíl mezi `accountant` a `readonly`
je jediný: zápis.

Vhodné použití:

- **admin** — vlastník / správce instalace.
- **accountant** — interní i externí účetní: plná práce s doklady a bankou, ale
  bez konfigurace systému a správy uživatelů.
- **readonly** — auditor, kontrolor nebo klient, který si má jen prohlížet a
  stahovat data (vč. DPH podkladů) bez rizika nechtěné změny.

### Jak je to vynucené

1. **Backend (`RoleMiddleware`)** — `readonly` smí výhradně `GET` requesty; jakýkoli
   zápis (`POST` / `PUT` / `PATCH` / `DELETE`) je odmítnut s `403`. Exporty i daňové
   výkazy jsou `GET`, proto k nim `readonly` má přístup. Admin endpointy
   (uživatelé, nastavení, integrace…) mají navíc **kontrolu role přímo v akci**.
2. **API token (PAT)** — role uživatele se kontroluje **před** scope tokenu, takže
   `readonly` uživatel nemůže obejít omezení ani tokenem se scopem `read_write`.
3. **UI** — frontend podle role **skrývá zápisová tlačítka** (Nový / Upravit /
   Smazat i akce jako odeslat, zaplaceno, párování banky). Zápisové stránky
   (`/…/new`, `/…/edit`) jsou navíc chráněné route-guardem — `readonly` je z nich
   přesměrován na nástěnku.

## 20.6 CSRF + Origin check

Každý mutating request (POST / PUT / PATCH / DELETE) musí mít:

1. **Origin header** se shodující s `app.url` v `cfg.php`
2. **X-CSRF-Token** header se shodující s tokenem v session

Bez nich → 403 `csrf_failed` / `origin_mismatch`. UI to obsluhuje
automaticky (token v Pinia store, header v axios interceptoru).

## 20.7 Activity log

Každá mutace (vytvoření / změna / vystavení / smazání) se loguje. Záznamy
obsahují:

- Akce (`invoice.created`, `invoice.issued`, `client.updated`, `auth.login_success`,
  `auth.login_failed`, `bank.statement_imported`, `currency.updated`, …)
- Uživatel (NULL pro neautentizované akce jako neúspěšné login)
- Entita (typ + ID)
- IP adresa (binární `VARBINARY(16)` — IPv4 i IPv6)
- User-Agent
- Payload — JSON s relevantními detaily (např. fields=`['email', 'name']`
  u `client.updated`)
- Datum + čas

Viz [19. Nastavení → § 15.6](19_Nastaveni.md) pro UI.

### 20.7.1 Co log NEUKLÁDÁ

- **Hesla** — ani staré, ani nové
- **PII klientů** mimo to, co bylo změněno (jen fields seznam, ne hodnoty)
- **Bankovní transakce** — log obsahuje jen ID importovaného výpisu

### 20.7.2 Jak se do logu zapisuje IP adresa

Aplikace bere IP klienta z **IP síťového spojení** (`REMOTE_ADDR`). Když běží
**za reverse proxy** (Docker, nginx, Cloudflare…), je tím spojením proxy — bez
konfigurace by se proto do auditu zapisovala **IP proxy**, ne reálného klienta
(typicky uvidíš pořád stejnou IP, např. bránu Dockeru `172.x.0.1`).

Reálnou IP přečte aplikace z hlavičky `X-Forwarded-For` **pouze tehdy**, když
`REMOTE_ADDR` odpovídá rozsahu v `cfg.ip_allowlist.trusted_proxies` (viz
§ 20.4.1). Z hlavičky se bere **první** adresa (původní klient). Bez nastavené
`trusted_proxies` se `X-Forwarded-For` ignoruje (ochrana proti podvržení).

> 🛈 Stejná logika se zjišťování IP používá i pro **brute-force lockout**
> (kap. 20.3). Za reverse proxy bez `trusted_proxies` proto lockout počítá
> pokusy podle IP proxy = fakticky globálně. Po nastavení `trusted_proxies`
> začnou audit log i lockout pracovat s reálnou klientskou IP.

## 20.8 DKIM podpis e-mailů

Pro **deliverabilitu** (aby gmail / o365 / seznam tvé maily nepoznačily jako
spam) doporučujeme aktivovat DKIM:

1. Vygeneruj RSA klíč: `openssl genrsa -out private/dkim/myinvoice.pem 2048`
2. Public key → DNS TXT záznam `myinvoice._domainkey.tvoje-domena.cz`
3. V `cfg.php → smtp.dkim.enabled => true`
4. Restart služby

Detaily v `README.md` v rootu repa.

## 20.9 Bezpečnostní audit

V `source/07-security-audit.md` najdeš výsledky interního auditu — všechny
identifikované findings (P1/P2/P3) jsou vyřešené nebo odůvodněně vynechané.

## 20.10 Tipy

- **Vždycky 2FA pro admin** — pokud admin účet padne, padá vše. Žádná výmluva.
- **Pravidelně rotuj hesla** každých 6–12 měsíců.
- **IP allowlist** v produkci pro non-veřejné použití (B2B accounting).
- **Activity log review** — alespoň 1× za měsíc projeďté podezřelé login
  selhání nebo neočekávané force-edit.
- **Backup `cfg.php` + `private/dkim/`** mimo repo — není v gitu, ztrátou
  přijdeš o pepper a nepřihlásíš se ke starým heslům.
