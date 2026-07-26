# 39. Bezpečnost (MFA, passkeys, zámek session, role)

Bezpečnost MyInvoice stojí na několika navazujících vrstvách:

1. **Autentizace** — heslo (bcrypt + pepper) nebo volitelně passkey bez hesla,
   brute-force ochrana a CAPTCHA
2. **Silné MFA** — passkey nebo TOTP
3. **Síťová izolace** — IP allowlist (volitelný, doporučeno v produkci)
4. **Autorizace** — role-based access (admin / accountant / readonly)
5. **Audit** — activity log všech mutací
6. **Zámek session** — serverové uzamčení PWA po nečinnosti

## 39.1 Hesla

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

## 39.2 Vícefaktorové ověření

MyInvoice podporuje dva silné faktory:

- **passkey (WebAuthn)** — kryptografický přístupový klíč chráněný zařízením,
- **TOTP** — šestimístný časový kód z autentikátoru.

E-mailové OTP je kompatibilní druhý krok pro účet bez silného faktoru, ale
nesplňuje povinnou silnou MFA politiku. Důvěryhodné zařízení se týká pouze
e-mailového OTP.

### 39.2.1 Passkeys

Passkey zaregistruješ v **Můj profil → Zabezpečení → Passkeys**. Každý klíč má
vlastní název, datum vytvoření a posledního použití. Lze jej přejmenovat nebo
odvolat. Aplikace podporuje více klíčů; doporučené jsou dvě passkeys nebo jedna
passkey spolu s TOTP.

Passkey se používá:

- samostatně k přihlášení bez e-mailu a hesla, pokud tuto možnost správce povolí,
- po správném e-mailu a hesle místo TOTP,
- k odemčení zamčené browserové/PWA session,
- jako čerstvé potvrzení citlivé operace, například vytvoření API tokenu.

Systémový dialog může podle zařízení použít otisk, obličej, PIN, gesto, heslo
zařízení nebo externí bezpečnostní klíč. MyInvoice konkrétní metodu nezjišťuje,
biometrická data neopouštějí zařízení a server ukládá pouze veřejný klíč.
Poskytovatel platformy nebo password manager může passkey end-to-end šifrovaně
synchronizovat mezi zařízeními.

Passkeys vyžadují stabilní veřejnou URL. V produkci musí `app.url` obsahovat
přesný HTTPS origin, například `https://faktury.example.cz`. Klíč je svázaný
s hostname; po změně domény jej na nové doméně nelze použít. Pro lokální vývoj
je podporované `http://localhost`, nikoli běžný HTTP přístup přes LAN IP.

Přidání a odvolání passkey vyžaduje nové ověření passkey nebo TOTP. U účtu bez
dosavadního silného faktoru první registrace vyžádá aktuální heslo. Při povinném
MFA nelze odvolat poslední povolený silný faktor.

Pokud správce přechází z TOTP na passkeys a vyřadí TOTP ze seznamu povolených
metod, uživatel smí existujícím TOTP potvrdit pouze registraci své první
passkey. Přechod je dostupný jen tehdy, když jsou passkeys povolené a účet ještě
nemá žádnou aktivní passkey. Stejné omezení platí pro registraci heslem u účtu
bez dosavadního silného faktoru. Server pod databázovým zámkem znovu ověří, že
jde skutečně o první klíč, takže nelze předem otevřít více registrací a dokončit
je až po přidání prvního klíče. Další klíče už vyžadují aktuálně povolený faktor.

TOTP = time-based one-time password (RFC 6238).

### 39.2.2 Aktivace TOTP

**Můj profil → 2FA → Aktivovat**.

![Aktivace 2FA](img/16_2fa_setup.webp)

1. Aplikace ukáže **QR kód** + textový **secret key**.
2. V mobilu otevři **autentikátor** (Google Authenticator, Authy, Microsoft
   Authenticator, 1Password, Bitwarden) → Přidat účet → Sken QR kódu.
3. Aplikace začne generovat 6-cifrené kódy každých 30 sekund.
4. Zadej aktuální kód do MyInvoice → **Potvrdit aktivaci**.

> ⚠️ MyInvoice **nepoužívá záložní jednorázové kódy** (recovery codes).
> Při ztrátě autentikátoru použij jinou passkey, nebo CLI rescue:
> `php api/bin/reset-mfa.php <email>` — viz [§ 39.2.4](#3924-obnova-pristupu).

### 39.2.3 Přihlášení s passkey a MFA

Po zadání e-mailu a hesla nabídne aplikace passkey, pokud ji účet má. Je-li
aktivní také TOTP, lze explicitně přepnout na šestimístný kód z autentikátoru.

![2FA výzva](img/04_2fa.webp)

Správce může navíc explicitně povolit přihlášení pouze pomocí passkey:

```php
'auth' => [
    'passwordless_login' => [
        'enabled' => true,
    ],
],
```

Totéž lze nastavit přes ENV:

```bash
MYINVOICE_AUTH_PASSWORDLESS_LOGIN=true
```

Výchozí hodnota je `false`, takže aktualizace nezmění dosavadní přihlašování.
Funkce je dostupná jen tehdy, když `auth.allowed_mfa_methods` obsahuje
`passkey` a WebAuthn konfigurace je platná. Přihlašovací stránka potom nabídne
**Přihlásit přístupovým klíčem**. Browser zobrazí passkeys pro aktuální doménu
a vybraný klíč bezpečně předá identitu účtu; e-mail ani heslo se neposílají.
Ověření uživatele na zařízení je povinné a úspěšná passkey rovnou vytvoří
silně ověřenou session, bez dalšího TOTP.

Passwordless režim neodstraňuje heslo ani standardní formulář. Ten zůstává
fallbackem pro jiné zařízení a cestou k TOTP. Pokud passkey není dostupná,
zruš systémový dialog a přihlas se e-mailem a heslem.

Účet s passkey nedostane automatický fallback na e-mailový kód. Pokud passkey
na aktuálním zařízení není dostupná, použij jinou passkey, TOTP nebo rescue.

### 39.2.4 Obnova přístupu

Kde passkey fyzicky leží, rozhoduje o tom, co se stane při ztrátě zařízení:

- **V zařízení** (Windows Hello, Touch ID, bezpečnostní klíč) — klíč je vázaný
  na hardware. S koncem zařízení končí i on.
- **Ve správci hesel nebo v cloudu účtu** (Keeper, 1Password, iCloud Keychain,
  Google Password Manager) — klíč se synchronizuje, takže přežije výměnu
  počítače a přihlásíš se jím i jinde.

Kam se klíč uloží, vybírá prohlížeč při registraci; aplikace to neřídí a ani to
nezjistí zpětně. Máš-li jediný klíč a ten je vázaný na zařízení, drž si jako
zálohu buď druhý klíč, nebo aktivní TOTP.

Nejprve použij jinou zaregistrovanou passkey nebo TOTP. Pokud není dostupný
žádný silný faktor, správce může na serveru spustit:

```bash
php api/bin/reset-mfa.php tvuj@email.cz
```

Skript vypne TOTP, odvolá všechny passkeys, zruší důvěryhodná zařízení,
čekající OTP, WebAuthn flow a step-up proofy a invaliduje všechny session
uživatele. Původní název `reset-2fa.php` zůstává kompatibilním aliasem.

> ⚠️ Rescue používej jen z důvěryhodného shellu serveru. Přímý SQL zásah není
> ekvivalentní: snadno ponechá aktivní session nebo rozpracované ověřovací flow.

### 39.2.5 Vynucení silného MFA

Pokud chceš, aby **každý** uživatel měl passkey nebo TOTP,
nastav v `cfg.php` (nebo `cfg.local.php`):

```php
'auth' => [
    'require_mfa' => true,
    'allowed_mfa_methods' => ['passkey', 'totp'],
],
```

Stejné lze přepnout přes ENV (Docker / PaaS):

```bash
MYINVOICE_AUTH_REQUIRE_MFA=true
MYINVOICE_AUTH_MFA_METHODS=passkey,totp
```

Chování:

- Uživatel bez povoleného silného faktoru dostane omezenou setup session a
  stránku `/setup-mfa`, kde zaregistruje passkey nebo zapne TOTP.
- Setup session smí pouze dokončit povolené MFA nastavení nebo se odhlásit.
  Business API zůstává serverově blokované.
- Po dokončení se setup session zneplatní a vydá se nové session ID i CSRF.

Starší `auth.require_totp = true` a `MYINVOICE_AUTH_REQUIRE_TOTP=true` zůstávají
podporované jako TOTP-only politika. Pro nové instalace používej obecné MFA
nastavení.

`allowed_mfa_methods` rozhoduje **co povinné MFA splní**, ne na co se přihlášení
zeptá. Zúžení seznamu (typicky na `['passkey']` při přechodu na passkey-only)
proto nikdy nezruší faktor, který uživatel reálně má:

- Kdo má zapnuté TOTP, zadává ho i dál. Když `totp` v seznamu není, výsledná
  session je jen `basic` — při `require_mfa = true` skončí uživatel na
  `/setup-mfa` a zaregistruje povolenou metodu.
- Kdo má passkey a WebAuthn je konfiguračně nedostupný (rozbité `app.url`),
  se přihlásí přes TOTP nebo e-mailové OTP, pokud je má. Bez jakéhokoliv jiného
  druhého faktoru vrací přihlášení `503 passkeys_unavailable` — nikdy nepropadne
  na samotné heslo. Řešením je opravit `app.url`, jinak `reset-mfa.php`.
- Totéž platí pro step-up při vydání API tokenu: zaregistrované TOTP se vyžaduje
  bez ohledu na `allowed_mfa_methods`.

Neznámá hodnota v seznamu (například `email_otp`, které sem nepatří) start
aplikace neshodí: použije se výchozí `['passkey', 'totp']` a přihlášený správce
uvidí na health endpointu warning `mfa_methods_configuration`.

> ⚠️ Povolení TOTP vyžaduje validní `app.secret_encryption_key` (32B base64).
> Health endpoint na chybnou konfiguraci upozorní; viz
> [§ 99 Řešení problémů](99_Reseni_problemu.md).

### 39.2.6 E-mailové ověření pro účet bez silného faktoru

Pro uživatele, kteří nechtějí (nebo neumí) authenticator aplikaci — typicky
externí účetní — lze zapnout **e-mailové OTP** jako druhý faktor. Kdo nemá
aktivní passkey ani TOTP, dostane po zadání hesla 6místný kód na e-mail a musí
ho opsat.

Zapnutí v `cfg.php` (výchozí stav je **vypnuto** — nejde o breaking change):

```php
'auth' => [
    'email_otp' => [
        'enabled'                 => true,  // kód jen pro účet bez passkey i TOTP
        'code_ttl_minutes'        => 10,    // platnost kódu
        'max_attempts'            => 5,     // pokusů na jeden kód, pak je nutný nový
        'resend_cooldown_seconds' => 60,    // min. prodleva mezi odesláním nového kódu
        'trusted_device_days'     => 30,    // „zapamatovat toto zařízení" na kolik dní
        'trusted_cookie_name'     => '__Host-myinvoice_td',
    ],
],
```

Chování:

- **Priorita silného faktoru.** Má-li uživatel použitelnou passkey nebo zapnuté
  TOTP, e-mailové OTP se neuplatní. E-mailový kód se použije jen tam, kde silný
  faktor chybí — nebo jako záchranná cesta pro účet s passkey, jejíž ověření
  instalace dočasně neumí (viz § 39.2.5).
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
> `php api/bin/reset-mfa.php <email>`.

### 39.2.7 Serverový zámek session

Automatický zámek browserové a PWA session je ve výchozím stavu vypnutý, aby se
po aktualizaci nezměnilo chování existujících instalací. Správce nastavuje
výchozí timeout pomocí `session.lock_after_minutes` nebo
`MYINVOICE_SESSION_LOCK_AFTER_MINUTES`. Hodnota `0` znamená, že správce zámek
nevynucuje. Uživatel jej přesto může dobrovolně zapnout v profilu na záložce
**Zámek aplikace**.

Hodnota musí být celé číslo od 0 do 1440; podporovaný je i kanonický numerický
řetězec, například `"15"`. Neplatná hodnota nesmí zablokovat start aplikace:
výchozí automatický zámek se bezpečně vypne a přihlášený uživatel uvidí
upozornění `session_lock_configuration` na health endpointu. Osobní explicitně
nastavené intervaly zůstávají účinné.

Osobní nastavení má tyto hranice:

- **Použít nastavení správce** zachová hodnotu správce; při `0` je automatický
  zámek vypnutý.
- Pokud správce nastavil kladnou hodnotu, osobní interval může být pouze stejný
  nebo kratší.
- Při hodnotě správce `0` lze zvolit vlastní interval 1 až 1440 minut.
- Pozdější snížení limitu správce okamžitě zpřísní i dříve uloženou delší osobní
  volbu.
- Zkrácení timeoutu se vyhodnotí serverově hned při uložení a může aktuální
  session rovnou zamknout.

Ruční **Zamknout** v uživatelském menu je dostupné bez ohledu na timeout, ale
jen pokud má účet alespoň jednu aktivní passkey a instalace ji umí použít.
Bez dostupné passkey se tlačítko nezobrazuje a server přímý požadavek odmítne,
aby nevznikla session, kterou lze ukončit pouze úplným odhlášením.

Stejnou podmínku má i **osobní interval**: kladnou hodnotu server uloží jen účtu
s použitelnou passkey, jinak vrátí `400 validation_failed`. Volba *Použít
nastavení správce* zůstává dostupná vždy.

> ⚠️ Správcovská hodnota `session.lock_after_minutes > 0` platí pro **všechny**
> účty, i pro ty bez passkey — a ty pak zamčenou session jen odhlásí (rozepsaný
> formulář se ztratí). Typicky se to týká instalací, kde uživatelé jedou na
> e-mailovém OTP. Aplikace na to upozorní health warningem
> `session_lock_without_unlock_method`; buď uživatelům registruj passkey, nebo
> nech `session.lock_after_minutes = 0` a osobní volbu na nich.

Aktivitu posouvají pouze skutečné vstupy do viditelné soukromé stránky, například
kliknutí, dotyk nebo klávesa. Polling, běžné API requesty, focus okna ani service
worker timeout neposouvají. Po dosažení limitu backend označí session jako
zamčenou a odmítne business API i v případě, že někdo odstraní frontendový
overlay.

Odemčení vyžaduje passkey a rotuje session ID i CSRF token, přičemž zachová
původní absolutní expiraci. TOTP existující zamčenou session přímo neodemkne;
volba **Přihlásit se znovu** provede bezpečný logout a celý login.

Zámek omezuje náhodný přístup k odloženému odemčenému zařízení. Nechrání data,
která už přečetl malware nebo XSS během aktivní session. Webová PWA negarantuje
zákaz screenshotu ani skrytí Android Recents. Rozpracovaný formulář zůstane
zachovaný jen dokud prohlížeč stránku drží v paměti; po ukončení stránky
Androidem se neuložená data ztratí. Offline odemčení není možné, protože server
musí vydat a ověřit jednorázovou challenge.

### 39.2.8 Nasazení změny autentizačního modelu

Aktivní session vytvořené před doplněním autentizačního kontextu se po migraci
označí jako `legacy`; migrace z pouhé existence TOTP neodvozuje, že konkrétní
session druhý faktor skutečně ověřila. Pokud instalace vyžaduje MFA, uživatelé
s takovou session se proto musí jednou znovu přihlásit. Jde o záměrné
fail-closed chování, které brání povýšení staré session bez důkazu o MFA.
Přihlašovací endpointy přítomnou starou cookie ignorují, takže stačí dokončit
standardní login; cookie není nutné ručně mazat v nastavení prohlížeče.

Browser session a její stav zámku jsou autoritativně uložené v MariaDB. Redis
slouží pro rate limiting, brute-force ochranu a best-effort cache; jeho výpadek
nesmí obnovit odvolanou, nahrazenou nebo zamčenou session.

Z toho plyne jedna změna configu: **`session.driver` už se nepoužívá**. Starší
`cfg.php` ho může dál obsahovat (`'auto'` / `'redis'` / `'db'`), hodnota se ale
ignoruje — session vždy čte a zapisuje MariaDB. Klíč lze bez náhrady smazat.

Migrace `0145` přestavuje tabulku `sessions` (dvanáct nových sloupců, backfill
a tři indexy), takže po dobu jejího běhu je tabulka zamčená a přihlašování
nefunguje. Naměřeno na MariaDB 11.8: **~16 s na 300 000 session**, u běžných
instalací s jednotkami až stovkami řádků je to pod sekundu. Před upgradem se
vyplatí spustit `php api/bin/cron-cleanup.php`, ať se nepřestavují dávno
expirované řádky.

## 39.3 Brute-force ochrana

| Pokusy během | Akce |
|---|---|
| 5 selhání / 5 minut | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 minut | Lockout 15 minut (per IP) |
| 30 selhání / 1 hodinu | Lockout 24 hodin + e-mail uživateli o pokusech |

Implementace: **Redis** pokud běží, jinak **MariaDB MEMORY engine** fallback.

## 39.4 IP allowlist (volitelné)

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

### 39.4.1 Za reverse proxy: `trusted_proxies` (důležité)

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

## 39.5 RBAC (role-based access)

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
   výkazy jsou `GET`, proto k nim `readonly` má přístup. Jediná výjimka z pravidla
   „jen GET": **hromadný export** (Daně → Hromadný export) běží jako background job,
   takže jeho spuštění/zrušení/smazání jsou technicky `POST`/`DELETE` — věcně jde
   ale o čtení (sbalení existujících dokladů do ZIP), proto je povolen všem rolím.
   Admin endpointy (uživatelé, nastavení, integrace…) mají navíc **kontrolu role
   přímo v akci**.
2. **API token (PAT)** — role uživatele se kontroluje **před** scope tokenu, takže
   `readonly` uživatel nemůže obejít omezení ani tokenem se scopem `read_write`.
3. **UI** — frontend podle role **skrývá zápisová tlačítka** (Nový / Upravit /
   Smazat i akce jako odeslat, zaplaceno, párování banky). Zápisové stránky
   (`/…/new`, `/…/edit`) jsou navíc chráněné route-guardem — `readonly` je z nich
   přesměrován na nástěnku.

## 39.6 CSRF + Origin check

Každý mutating request (POST / PUT / PATCH / DELETE) musí mít:

1. **Origin header** se shodující s `app.url` v `cfg.php`
2. **X-CSRF-Token** header se shodující s tokenem v session

Bez nich → 403 `csrf_failed` / `origin_mismatch`. UI to obsluhuje
automaticky (token v Pinia store, header v axios interceptoru).

## 39.7 Activity log

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

Viz [36. Nastavení](36_Nastaveni.md) pro UI.

### 39.7.1 Co log NEUKLÁDÁ

- **Hesla** — ani staré, ani nové
- **PII klientů** mimo to, co bylo změněno (jen fields seznam, ne hodnoty)
- **Bankovní transakce** — log obsahuje jen ID importovaného výpisu

### 39.7.2 Jak se do logu zapisuje IP adresa

Aplikace bere IP klienta z **IP síťového spojení** (`REMOTE_ADDR`). Když běží
**za reverse proxy** (Docker, nginx, Cloudflare…), je tím spojením proxy — bez
konfigurace by se proto do auditu zapisovala **IP proxy**, ne reálného klienta
(typicky uvidíš pořád stejnou IP, např. bránu Dockeru `172.x.0.1`).

Reálnou IP přečte aplikace z hlavičky `X-Forwarded-For` **pouze tehdy**, když
`REMOTE_ADDR` odpovídá rozsahu v `cfg.ip_allowlist.trusted_proxies` (viz
§ 39.4.1). Z hlavičky se bere **první** adresa (původní klient). Bez nastavené
`trusted_proxies` se `X-Forwarded-For` ignoruje (ochrana proti podvržení).

> 🛈 Stejná logika se zjišťování IP používá i pro **brute-force lockout**
> (kap. 20.3). Za reverse proxy bez `trusted_proxies` proto lockout počítá
> pokusy podle IP proxy = fakticky globálně. Po nastavení `trusted_proxies`
> začnou audit log i lockout pracovat s reálnou klientskou IP.

## 39.8 DKIM podpis e-mailů

Pro **deliverabilitu** (aby gmail / o365 / seznam tvé maily nepoznačily jako
spam) doporučujeme aktivovat DKIM:

1. Vygeneruj RSA klíč: `openssl genrsa -out private/dkim/myinvoice.pem 2048`
2. Public key → DNS TXT záznam `myinvoice._domainkey.tvoje-domena.cz`
3. V `cfg.php → smtp.dkim.enabled => true`
4. Restart služby

Detaily v `README.md` v rootu repa.

## 39.9 Bezpečnostní audit

V `source/07-security-audit.md` najdeš výsledky interního auditu — všechny
identifikované findings (P1/P2/P3) jsou vyřešené nebo odůvodněně vynechané.

## 39.10 Tipy

- **Vždycky 2FA pro admin** — pokud admin účet padne, padá vše. Žádná výmluva.
- **Pravidelně rotuj hesla** každých 6–12 měsíců.
- **IP allowlist** v produkci pro non-veřejné použití (B2B accounting).
- **Activity log review** — alespoň 1× za měsíc projeďté podezřelé login
  selhání nebo neočekávané force-edit.
- **Backup `cfg.php` + `private/dkim/`** mimo repo — není v gitu, ztrátou
  přijdeš o pepper a nepřihlásíš se ke starým heslům.
