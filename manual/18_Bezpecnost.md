# 18. Bezpečnost (2FA, IP allowlist, role, activity log)

Bezpečnost MyInvoice stojí na 4 vrstvách:

1. **Autentizace** — bcrypt hesla + peppered + brute-force ochrana + CAPTCHA
2. **2FA (TOTP)** — volitelné druhé ověření přes mobilní aplikaci
3. **Síťová izolace** — IP allowlist (volitelný, doporučeno v produkci)
4. **Autorizace** — role-based access (admin / accountant / readonly)
5. **Audit** — activity log všech mutací

## 18.1 Hesla

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

## 18.2 Dvoufaktorové ověření (TOTP)

TOTP = time-based one-time password (RFC 6238). Nejznámější standard pro 2FA.

### 18.2.1 Aktivace

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
> viz [§ 18.2.3](#1823-ztrata-telefonu--deaktivace).

### 18.2.2 Přihlášení s 2FA

Po zadání e-mailu + hesla aplikace vyzve k 6-cifernému kódu z autentikátoru.

![2FA výzva](img/04_2fa.webp)

Pokud autentikátor nemáš po ruce, nezbývá než provést rescue reset
(následující sekce).

### 18.2.3 Ztráta telefonu / deaktivace

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

## 18.3 Brute-force ochrana

| Pokusy během | Akce |
|---|---|
| 5 selhání / 5 minut | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 minut | Lockout 15 minut (per IP) |
| 30 selhání / 1 hodinu | Lockout 24 hodin + e-mail uživateli o pokusech |

Implementace: **Redis** pokud běží, jinak **MariaDB MEMORY engine** fallback.

## 18.4 IP allowlist (volitelné)

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

## 18.5 RBAC (role-based access)

Tři role:

| Role | UI | API | Vystavování | Editace vystavené | Konfigurace | Uživatelé |
|---|---|---|---|---|---|---|
| **admin** | ✅ | ✅ | ✅ | ✅ (force) | ✅ | ✅ |
| **accountant** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| **readonly** | ✅ (read) | ✅ (read) | ❌ | ❌ | ❌ | ❌ |

Per endpoint je v API kódu definovaná minimální role. UI se podle aktuální
role uživatele schovává tlačítka, na která nemá nárok.

## 18.6 CSRF + Origin check

Každý mutating request (POST / PUT / PATCH / DELETE) musí mít:

1. **Origin header** se shodující s `app.url` v `cfg.php`
2. **X-CSRF-Token** header se shodující s tokenem v session

Bez nich → 403 `csrf_failed` / `origin_mismatch`. UI to obsluhuje
automaticky (token v Pinia store, header v axios interceptoru).

## 18.7 Activity log

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

Viz [17. Nastavení → § 15.6](17_Nastaveni.md) pro UI.

### 18.7.1 Co log NEUKLÁDÁ

- **Hesla** — ani staré, ani nové
- **PII klientů** mimo to, co bylo změněno (jen fields seznam, ne hodnoty)
- **Bankovní transakce** — log obsahuje jen ID importovaného výpisu

## 18.8 DKIM podpis e-mailů

Pro **deliverabilitu** (aby gmail / o365 / seznam tvé maily nepoznačily jako
spam) doporučujeme aktivovat DKIM:

1. Vygeneruj RSA klíč: `openssl genrsa -out private/dkim/myinvoice.pem 2048`
2. Public key → DNS TXT záznam `myinvoice._domainkey.tvoje-domena.cz`
3. V `cfg.php → smtp.dkim.enabled => true`
4. Restart služby

Detaily v `README.md` v rootu repa.

## 18.9 Bezpečnostní audit

V `source/07-security-audit.md` najdeš výsledky interního auditu — všechny
identifikované findings (P1/P2/P3) jsou vyřešené nebo odůvodněně vynechané.

## 18.10 Tipy

- **Vždycky 2FA pro admin** — pokud admin účet padne, padá vše. Žádná výmluva.
- **Pravidelně rotuj hesla** každých 6–12 měsíců.
- **IP allowlist** v produkci pro non-veřejné použití (B2B accounting).
- **Activity log review** — alespoň 1× za měsíc projeďté podezřelé login
  selhání nebo neočekávané force-edit.
- **Backup `cfg.php` + `private/dkim/`** mimo repo — není v gitu, ztrátou
  přijdeš o pepper a nepřihlásíš se ke starým heslům.
