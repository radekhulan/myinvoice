# 36. Nastavení

V hlavním menu **Systém** je rozbalovací podmenu se sekcemi pro konfiguraci
aplikace:

- **Dodavatelé** — viz [35. Multi-supplier](35_Multi_supplier.md)
- **Číselníky** — DPH sazby, země, jednotky a další pomocné seznamy
- **Uživatelé** — správa lidí, kteří se přihlašují
- **E-mail šablony** — texty automatických e-mailů
- **Activity log** — kdo co změnil
- **Exporty** — viz [15. Exporty](15_Exporty.md)

Správa opakovaně používaných fakturačních položek je kvůli návaznosti na
vystavování dokladů v menu **Prodej → Ceník**.

## Brandingové profily

Brandingové profily jsou volitelný modul, který se zapíná v nastavení dodavatele.
Dokud je vypnutý, faktury a e-maily používají původní údaje a branding dodavatele.
Po zapnutí lze vytvořit profily pro různé obchodní značky. Profil může změnit
logo, zobrazovaný název, slogan, barvu, kontaktní údaje a patičku e-mailu. Právní
údaje dodavatele (firma, adresa, IČ a DIČ) zůstávají společné a profilem se nemění.

Výchozí profil se použije vždy, když faktura, pravidelná fakturace ani zákazník
neurčují jiný profil. Výchozí profil není povinný. Přepínač **Používat vlastní
branding** v jednotlivém profilu řídí zobrazení jeho loga a barev v e-mailech i PDF.

Každému profilu lze přiřadit také e-mailový profil odesílatele. Ten určuje
SMTP účet, adresu odesílatele, Reply-To a případné podepisování zpráv. Není-li
vybrán, použije se výchozí e-mailový profil dodavatele.

E-mailový profil, který používá některý brandingový profil, nelze smazat.
Chybová zpráva vypíše dotčené profily; nejprve jim nastav jiného odesílatele
nebo výchozí profil dodavatele.

Výchozí profil lze přiřadit zákazníkovi. Nový koncept faktury jej převezme a
při vystavení se použitá identita uloží do snapshotu dokladu. Pozdější změna
profilu nebo nahrání nového loga proto nezmění již vystavené faktury.

Akce **Náhled e-mailu** u profilu zobrazí jeho logo, barvu, zobrazovaný název,
kontakty i vlastní patičku. V náhledu lze přepínat mezi českou a anglickou
variantou. Původní konfigurace brandingu e-mailů se používá při vypnutém modulu.
Obsahové e-mailové šablony zůstávají společné pro dodavatele a spravují se
odděleně na stránce **E-mail šablony**.

## 36.1 Číselníky

**Systém → Číselníky**.

![Číselníky — Měny](img/15_ciselniky_meny.webp)

4 záložky:

### 36.1.1 Měny

Měny a bankovní účty aktuálního dodavatele jsou soustředěné na stránce
**Finance → Bankovní účty** (viz [37. Bankovní účty](37_Bankovni_ucty.md)).
Každý řádek představuje jeden bankovní účet v dané
měně; pokud máš víc účtů pro stejnou měnu, založ více řádků se stejným kódem
měny.

| Pole | Význam |
|---|---|
| Kód | ISO 4217 — `CZK`, `EUR`, `USD`, `GBP` |
| Označení | „CZK — KB", „EUR — Fio" — pro UI rozlišení (víc účtů per měna) |
| Symbol | `Kč`, `€`, `$`, `£` |
| Název CS / EN | „Koruna" / „Crown" |
| Decimals | Počet desetinných míst (2 typicky) |
| Aktivní | Vypnutá měna nelze pro nové faktury |
| Default pro kód | Pokud máš víc účtů per měna (např. 2× CZK), který je default |
| **Účet** (CZK) | Číslo účtu (např. `1000000005`) + bank kód (`0100`) + název banky |
| **Účet** (EUR) | IBAN + BIC + název banky |

> ⚠️ Po **změně bankovního účtu** se **automaticky invaliduje PDF cache**
> všech faktur, které renderují bank info live (drafty + faktury bez
> snapshotu). Faktury v stavu `issued+` mají immutable `bank_snapshot`.

Na stejné stránce je i konfigurace **bankovních e-mailových avíz**: IMAP účty,
mapování bankovní účet → IMAP účet → parser, parser provideri a přehled
zpracovaných e-mailů. Detail je v [§ 24.7 Bankovní e-mailová avíza](24_Banka.md).

### 36.1.2 Sazby DPH

![Číselníky — DPH](img/15_ciselniky_dph.webp)

| Pole | Význam |
|---|---|
| Kód | `CZ-21`, `CZ-12`, `CZ-0`, `CZ-RC` |
| Sazba | `21`, `12`, `0`, `0` |
| Stát | `CZ` (zatím) |
| Popisek CS / EN | Pro UI / PDF |
| Default | Která sazba se předvyplní v editoru |
| Reverse charge | Zatrhneme pro `CZ-RC` |
| Platnost od | Pro historické faktury (15 % v roce 2023) |

### 36.1.3 Země

Statický číselník — nemělo by být potřeba editovat. Obsahuje 200+ zemí podle
ISO 3166-1.

### 36.1.4 Jednotky

Číselník měrných jednotek pro položky faktury. Globální (sdílený mezi
dodavateli), nahrazuje volný textový vstup za dropdown.

| Pole | Význam |
|---|---|
| Kód | Krátký identifikátor (`h`, `ks`, `den`, `měs.`) |
| Popisek CS / EN | Co se zobrazí v UI / PDF (`hodina` / `hour`) |
| Default | Která jednotka se předvyplní při přidání nové položky (typicky `h`) |
| Pořadí | Číslo pro řazení v dropdownu |

> 💡 **Default = `hodina`** dává smysl, protože nová položka přebírá
> hodinovou sazbu z projektu/klienta. Pro jednorázové položky (paušál,
> licence, materiál) jednotku ručně přepneš.

> 🛈 **Auto-clean prázdných položek** — při uložení faktury se řádky bez
> popisu i bez ceny tiše smažou. Můžeš tedy v editoru přidat víc řádků na
> zásobu a nepoužité se neuloží.

### 36.1.5 Ceníkové položky

**Prodej → Ceník** (jen administrátor) spravuje ceník aktuálního dodavatele.
Každá položka má kód, název, fakturační popis, jednotku, sazbu DPH a povinnou
základní cenu v jedné měně. Kód je unikátní pouze v rámci dodavatele.
Přehled lze prohledávat a filtrovat podle měny a aktivního či archivovaného
stavu.

Pro další aktivní měny lze zadat vlastní pevnou cenu. Když zapneš
**Povolit přepočet kurzem ČNB**, chybějící měnová cena se dopočte ze základní
ceny. Pevná cena v cílové měně má vždy přednost. Náhled ukazuje zdrojovou cenu,
výslednou částku, křížový kurz a skutečné datum použitého kurzovního lístku.

V sekci **Individuální ceny zákazníků** lze pro položku, zákazníka a měnu zadat
odlišnou cenu. Pořadí použití je:

1. individuální cena zákazníka v měně dokladu,
2. obecná pevná cena v měně dokladu,
3. individuální cena zákazníka v základní měně přepočtená kurzem,
4. obecná základní cena přepočtená kurzem.

U uložené individuální ceny se zobrazuje také počet opakovaných šablon daného
zákazníka, které jsou na položku napojené.

Ceníková položka určuje, zda jsou její ceny s DPH, nebo bez DPH. Do dokladu či
šablony ji lze vložit jen při shodném režimu. Používaná položka se při smazání
archivuje, aby zůstaly zachované vazby a pevné snapshoty šablon.

## 36.2 Uživatelé

**Systém → Uživatelé** (jen pro admina).

![Uživatelé](img/15_users.webp)

Tabulka uživatelů, kteří se mohou přihlásit. Tlačítko **+ Nový uživatel**.

### 36.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI |
| E-mail | Login |
| Heslo | Min. 12 znaků |
| Role | `admin` / `accountant` / `readonly` |
| Jazyk | `cs` / `en` |
| Aktivní | Vypnutý uživatel nemůže se přihlásit |

### 36.2.2 Role

| Role | Co může |
|---|---|
| **admin** | Vše — vystavování, konfigurace, uživatelé, force editace, smazání |
| **accountant** | Vystavování faktur, klienti, banka, exporty, daňové výkazy. **Bez** konfigurace systému, **bez** force editace, **bez** správy uživatelů |
| **readonly** | Vidí **totéž co účetní** — vč. exportů a daňových výkazů (DPH/KH/…) — a smí **data exportovat**, ale **nic nemění** (nezakládá, neupravuje, nemaže). Vhodné pro auditora / klienta |

> 🛈 Rozdíl mezi **accountant** a **readonly** je jediný: zápis. Obě role vidí a
> exportují stejná data; `readonly` jen nemá žádná tlačítka pro úpravy. Úplná
> matice oprávnění je v [§ 39.5 RBAC](39_Bezpecnost.md).

> 🛈 Systém má **guard proti odebrání posledního aktivního admina** — pokud
> jsi sám admin a zkusíš si snížit roli, vrátí 409. Musí být minimálně 1
> admin v systému.

## 36.3 Můj profil

**Pravý horní roh → klik na jméno → Můj profil**. Stejná obrazovka jako
[§ 7.5 Můj profil](07_Prihlaseni.md) — viz screenshot tam.

Můžeš si změnit:

- **Jméno + jazyk**
- **Heslo** — vyžaduje původní heslo
- **2FA** — zapnout / vypnout (vyžaduje heslo + ověření TOTP)

Viz [39. Bezpečnost § 37.2](39_Bezpecnost.md) pro detail TOTP.

## 36.4 E-mailové šablony

**Systém → E-mail šablony**.

![E-mail šablony](img/15_emails_list.webp)

Seznam šablon:

| Kód | Použití |
|---|---|
| `invoice_send` | Odeslání faktury klientovi |
| `invoice_reminder` | Upomínka po splatnosti |
| `proforma_reminder` | Připomínka nezaplacené zálohové faktury |
| `invoice_payment_thanks` | Poděkování za úhradu (viz § 33.5.5) — má i variantu pro zálohu |
| `invoice_approval` | Žádost o schválení výkazu víceprací zákazníkem |
| `recurring_draft_reminder` | Připomínka otevřeného konceptu pravidelné fakturace |
| `password_reset` | Reset hesla (system) |
| `login_otp` | Ověřovací kód pro přihlášení (system) |
| `welcome` | Uvítací e-mail novému uživateli |
| `test` | Pro Test odeslání (debug) |

### 36.4.1 Editor šablony

Klik na řádek → editor.

Záložky podle jazyka × formátu:

- **CS HTML** — česká verze, plný HTML
- **CS Text** — plain text fallback
- **EN HTML** — anglická verze
- **EN Text** — anglický plain text

Editor je **CodeMirror** s syntaxí Twig.

### 36.4.2 Předmět

Pole nahoře, podporuje placeholders (`{{ varsymbol }}`, …).

### 36.4.3 Test odeslání

Tlačítko **Test e-mail** dole — pošle vyplněnou šablonu na **tvůj** e-mail
(přihlášeného admina) s vzorovými daty (faktura `2605001`, klient „Test
Klient s.r.o.", …).

### 36.4.4 Placeholders

Závisí na typu šablony. `invoice_new`:

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol |
| `{{ amount }}` | Částka (formátovaná) |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Splatnost |
| `{{ client_name }}` | Klient |
| `{{ supplier_name }}` | Dodavatel |
| `{{ pdf_url }}` | Odkaz pro stažení PDF (pokud máš public link) |

## 36.5 Activity log

**Systém → Activity log**.

![Activity log](img/15_activity.webp)

Audit všech mutací — kdo a kdy co změnil. Lze filtrovat:

| Filtr | Hodnoty |
|---|---|
| Akce | `invoice.created`, `invoice.issued`, `invoice.sent`, `invoice.paid`, `client.updated`, … |
| Uživatel | Dropdown se všemi |
| Entita | Typ (`invoice` / `client` / `project` / …) + ID |
| IP | IPv4 / IPv6 |
| Období | Měsíc / vlastní rozsah |
| Dodavatel | Per-dodavatel filtrování |

Použití:

- **Audit chyby** — „Kdo upravil fakturu 2605007?" → filter `entity_type=invoice, entity_id=N`
- **Bezpečnostní audit** — „Bylo to z očekávané IP?" → filter `ip`
- **Outage timeline** — všechny akce v intervalu

> 🛈 Activity log se nepromaže automaticky. Cron `cron-cleanup.sh`
> standardně **neničí** activity log, ale lze nastavit retention v
> `cfg.php → app.activity_log_retention_days`.

## 36.6 Elektronické podpisy

Elektronické podpisy mají vlastní stránku **Systém -> Elektronické podpisy**.
Aktuální konfigurace už není jeden certifikát dodavatele, ale sada
podpisových profilů a mapování pro jednotlivé výstupy. Detailní postup je v
[kapitole 28. Elektronické podpisy](38_Elektronicke_podpisy.md).

## 36.7 Odesílací e-mailové profily

**Systém → E-maily → záložka Odesílací profily** definuje identitu, pod kterou
aplikace posílá odchozí e-maily aktuálního dodavatele.

Profil obsahuje:

- **From e-mail** a **From jméno** — adresa a jméno v hlavičce odesílatele,
- volitelnou volbu **Konfigurovat Reply-To** — po zapnutí lze vyplnit odpovědní
  adresu a jméno; když není zapnutá, profil hlavičku `Reply-To` do e-mailu
  nevkládá a odpovědi tak směřují na `From` z profilu,
- volitelný **S/MIME profil** — certifikát, který se použije pro podepsané
  e-mailové výstupy; formulář hlídá shodu certifikační e-mailové identity s
  `From e-mailem` a umí `From` z certifikátu předvyplnit,
- volitelnou volbu **Konfigurovat DKIM** — po zapnutí je nutné vyplnit DKIM
  doménu i selector pro tento profil; když není zapnutá, profil DKIM podpis
  nepoužije,
- **Transport** — výchozí globální `cfg.php`, vlastní SMTP účet nebo lokální
  `sendmail`; u vlastního SMTP lze nastavit server, port, šifrování, typ
  autentizace, TLS validaci, timeout a držení spojení, SMTP heslo/token se
  ukládá šifrovaně,
- volitelnou volbu **Ukládat kopii do IMAP složky odeslané pošty** — po
  úspěšném odeslání přes SMTP/transport se finální MIME zpráva uloží do zadané
  IMAP složky; IMAP heslo se ukládá šifrovaně; pole složky umí načíst seznam
  složek z aktuálně vyplněného IMAP účtu a ověřit připojení i cílovou složku
  včetně testovacího zápisu bez uložení profilu; lze nastavit timeout, označení
  uložené kopie jako přečtené a chování při chybě IMAP uložení,
- přepínače **Výchozí profil** a **Aktivní**.

Povinná pole jsou ve formuláři označená hvězdičkou. Před uložením i před
odesláním testu aplikace zkontroluje aktuálně zobrazené povinné položky
(`Reply-To`, DKIM, SMTP autentizace a IMAP podle zapnutých voleb) a bez jejich
vyplnění akci nespustí.

Ve formuláři profilu i u každého uloženého profilu je akce **Test**, která
pošle krátký testovací e-mail na e-mail přihlášeného uživatele, případně na
e-mail dodavatele nebo globální `cfg.php → smtp.from_email`. Test ve formuláři
použije aktuálně vyplněné hodnoty bez uložení do databáze. Test použije přímo
vybraný profil, i když není výchozí, takže ověřuje jeho `From`, `Reply-To`,
DKIM/S/MIME, transport i volitelné uložení do IMAP složky. Po testu formulář
zobrazí buď chybu vrácenou serverem, nebo informaci, že transport e-mail přijal,
včetně poslední SMTP/transport odpovědi, pokud ji backend získal. Pokud je
zapnuté IMAP ukládání, test zároveň zobrazí, zda se kopie uložila do zadané
složky. Při výchozí politice chyba IMAP uložení nemění fakt, že transport
e-mail přijal.
Pokud má profil nastaveno **Hlásit chybu archivace**, chyba uložení do IMAP se
zapíše jako chyba archivace po doručení. Aplikace ale e-mail znovu neposílá,
protože transport ho už přijal a opakování by mohlo vytvořit duplicitu u
příjemce.

Když existuje aktivní výchozí profil, používá ho `Mailer` pro všechny odchozí
e-maily daného dodavatele. Pokud žádný aktivní výchozí profil není, chování je
stejné jako bez profilů: `From` se bere z globální SMTP konfigurace a jméno
odesílatele z dodavatele. Fallback na e-mail dodavatele nebo globální
`cfg.php → smtp.reply_to_*` se pro `Reply-To` použije jen v tomto režimu bez
aktivního profilu. Stejně tak globální DKIM doména/selector z `cfg.php` platí
jen bez aktivního profilu; profil s vypnutým DKIM se nepodepisuje.
Ukládání do IMAP složky se také používá jen tehdy, když je zapnuté přímo v
aktivním profilu. Bez profilu ani při vypnuté volbě se žádný globální fallback
nepoužije.

Privátní DKIM klíč je stále globální v `cfg.php`. Odesílací profil může kromě
identity zprávy změnit i samotný transport, pokud je potřeba posílat pro různé
domény přes různé SMTP účty nebo lokální MTA.

## 36.8 SMTP log analýza

**Systém → E-maily → záložka SMTP log analýza**. Přístup pouze pro **admin**.

Zatímco *Odeslané e-maily* ukazují, co se aplikace pokusila poslat (z pohledu
aplikace), tahle záložka ukazuje, **co se reálně stalo na poštovním serveru** —
kam byla zpráva doručena a kde nastal problém. Čte přímo logy MTA (poštovního
serveru) a převádí je na přehledný seznam událostí. Jen čte; nic neodesílá ani
nemění.

### 36.8.1 Co uvidíš

- **Souhrnné karty** — počty doručovacích pokusů, doručeno / odloženo /
  odmítnuto a počet přijatých podání.
- **Cílové servery s problémy** — rychlé dlaždice serverů, kam se nedaří
  doručovat (klik nastaví filtr na daný server).
- **Tabulka událostí** s filtry (fulltext, typ, stav, rozsah dat). Každý řádek
  nese čas, stav, *od → komu*, cílový server + IP, předmět (pokud ho log nese)
  a doslovnou odpověď serveru.
- **Odkaz na fakturu** — pokud událost patří k e-mailu, který aplikace sama
  odeslala, doplní se klikací odkaz na příslušnou fakturu. Páruje se přes
  příjemce a čas odeslání (z interního auditu odeslané pošty); u serverů, které
  logují předmět, pomůže i číslo faktury v předmětu. Pošta, kterou neposlala
  aplikace (např. jiný systém na stejném serveru), se k faktuře neváže.

Druhy událostí (sloupec *typ*):

| Typ | Význam |
|---|---|
| **podání** | Zpráva vstoupila na server (klient/aplikace → MTA). Tady je vidět obálka tak, jak byla podána — pozná se tu např. chybějící příjemce. |
| **doručení** | Pokus o doručení na cílový MX. Nese výsledný stav a odpověď. |
| **událost** | Informativní/chybový záznam vázaný na zprávu (odložení, relay na smart host). |

Stavy:

| Stav | Význam |
|---|---|
| **Doručeno** | Cílový server zprávu přijal (2xx po DATA). |
| **Zařazeno** | Přijato k doručení (podání), zatím neodesláno dál. |
| **Odloženo** | Dočasné selhání (4xx) — greylisting, plná schránka, rDNS. Server to zkusí znovu. |
| **Odmítnuto** | Trvalé odmítnutí (5xx) — antispam politika, neexistující schránka, neověřený odesílatel. |
| **Chyba** | Neúplný dialog / chyba spojení. |

> 🛈 **Box „SMTP analýza" v detailu faktury.** Když je analýza zapnutá, najdeš
> u každé odeslané faktury (sekce pod historií PDF a aktivitou, jen pro admina)
> rozbalovací box, který na kliknutí dohledá v logu doručení právě této faktury —
> prohledá **den odeslání a následující den** pro její příjemce a ukáže per-příjemce
> stav (doručeno / odloženo / odmítnuto) i jednotlivé pokusy s odpovědí serveru.

### 36.8.2 Typické použití

- **„Došlo to klientovi?"** — fulltext na e-mail příjemce → uvidíš poslední stav
  doručení a odpověď jeho serveru.
- **Diagnostika odložení** — `450 4.7.1 cannot find your hostname` značí chybějící
  PTR/rDNS záznam tvé odchozí IP; `452 inbox out of storage` = plná schránka příjemce.
- **Diagnostika odmítnutí** — `541/554 antispam policy`, `550 unauthenticated`
  ukazují na problém s reputací / SPF / DKIM / DMARC.

### 36.8.3 Nastavení

Konfigurace je v `cfg.php` (vzor v `cfg.sample.php`) v sekci `smtp_log`:

| Klíč | Význam |
|---|---|
| `enabled` | `true` = záložka je aktivní. |
| `connector` | Parser pro konkrétní server: `hmailserver` nebo `mailenable`. |
| `path` | Glob vzor k log souborům (absolutní cesta). Hvězdička pokryje denní rotaci. |
| `max_files` | Strop počtu souborů (nejnovější dle data). |
| `max_bytes` | Strop velikosti čteného souboru; větší se čtou od konce. |

Příklady cest:

- **hMailServer** — `C:\Program Files (x86)\hMailServer\Logs\hmailserver_*.log`
- **MailEnable** — `C:\Program Files\Mail Enable\Logging\SMTP\SMTP-Activity-*.log`
  (čte se sada *SMTP-Activity*; *SMTP-Debug* a W3C `ex*` se ignorují)

> 🛈 Podpora dalších serverů (Postfix, Exim…) je připravená architektonicky —
> stačí doplnit nový konektor; konfigurace zůstává stejná, jen se změní
> `connector`.

## 36.8 Tipy

- **Test šablony** vždy před produkčním nasazením — typo v Twig syntaxi by
  rozbilo odesílání všem klientům.
- **Role accountant** je dobrá pro externí účetní — vidí faktury, banku,
  exporty i daňové výkazy, ale nemůže upravit uživatele ani konfiguraci.
- **Role readonly** dej auditorovi nebo klientovi — vidí a exportuje totéž co
  účetní (vč. DPH podkladů), ale nemůže nic změnit.
- **Z Activity logu** zjistíš všechno — i kdo neúspěšně se zkoušel přihlásit
  (filter akce `auth.login_failed`).
