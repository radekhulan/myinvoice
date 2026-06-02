# 19. Nastavení

V hlavním menu **Systém** je rozbalovací podmenu se 6 sekcemi:

- **Dodavatelé** — viz [18. Multi-supplier](18_Multi_supplier.md)
- **Číselníky** — měny, DPH sazby, země, jednotky
- **Uživatelé** — správa lidí, kteří se přihlašují
- **E-mail šablony** — texty automatických e-mailů
- **Activity log** — kdo co změnil
- **Exporty** — viz [16. Exporty](16_Exporty.md)

## 19.1 Číselníky

**Systém → Číselníky**.

![Číselníky — Měny](img/15_ciselniky_meny.webp)

4 záložky:

### 19.1.1 Měny

Každá měna pro aktuálního dodavatele = **1 bankovní účet**.

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

### 19.1.2 Sazby DPH

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

### 19.1.3 Země

Statický číselník — nemělo by být potřeba editovat. Obsahuje 200+ zemí podle
ISO 3166-1.

### 19.1.4 Jednotky

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

## 19.2 Uživatelé

**Systém → Uživatelé** (jen pro admina).

![Uživatelé](img/15_users.webp)

Tabulka uživatelů, kteří se mohou přihlásit. Tlačítko **+ Nový uživatel**.

### 19.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI |
| E-mail | Login |
| Heslo | Min. 12 znaků |
| Role | `admin` / `accountant` / `readonly` |
| Jazyk | `cs` / `en` |
| Aktivní | Vypnutý uživatel nemůže se přihlásit |

### 19.2.2 Role

| Role | Co může |
|---|---|
| **admin** | Vše — vystavování, konfigurace, uživatelé, force editace, smazání |
| **accountant** | Vystavování faktur, klienti, banka, exporty, daňové výkazy. **Bez** konfigurace systému, **bez** force editace, **bez** správy uživatelů |
| **readonly** | Vidí **totéž co účetní** — vč. exportů a daňových výkazů (DPH/KH/…) — a smí **data exportovat**, ale **nic nemění** (nezakládá, neupravuje, nemaže). Vhodné pro auditora / klienta |

> 🛈 Rozdíl mezi **accountant** a **readonly** je jediný: zápis. Obě role vidí a
> exportují stejná data; `readonly` jen nemá žádná tlačítka pro úpravy. Úplná
> matice oprávnění je v [§ 20.5 RBAC](20_Bezpecnost.md).

> 🛈 Systém má **guard proti odebrání posledního aktivního admina** — pokud
> jsi sám admin a zkusíš si snížit roli, vrátí 409. Musí být minimálně 1
> admin v systému.

## 19.3 Můj profil

**Pravý horní roh → klik na jméno → Můj profil**. Stejná obrazovka jako
[§ 4.5 Můj profil](04_Prihlaseni.md) — viz screenshot tam.

Můžeš si změnit:

- **Jméno + jazyk**
- **Heslo** — vyžaduje původní heslo
- **2FA** — zapnout / vypnout (vyžaduje heslo + ověření TOTP)

Viz [20. Bezpečnost § 20.2](20_Bezpecnost.md) pro detail TOTP.

## 19.4 E-mailové šablony

**Systém → E-mail šablony**.

![E-mail šablony](img/15_emails_list.webp)

Seznam šablon:

| Kód | Použití |
|---|---|
| `invoice_new` | Odeslání nové faktury klientovi |
| `invoice_reminder` | Upomínka po splatnosti |
| `password_reset` | Reset hesla (system) |
| `welcome` | Uvítací e-mail novému uživateli |
| `test` | Pro Test odeslání (debug) |

### 19.4.1 Editor šablony

Klik na řádek → editor.

Záložky podle jazyka × formátu:

- **CS HTML** — česká verze, plný HTML
- **CS Text** — plain text fallback
- **EN HTML** — anglická verze
- **EN Text** — anglický plain text

Editor je **CodeMirror** s syntaxí Twig.

### 19.4.2 Předmět

Pole nahoře, podporuje placeholders (`{{ varsymbol }}`, …).

### 19.4.3 Test odeslání

Tlačítko **Test e-mail** dole — pošle vyplněnou šablonu na **tvůj** e-mail
(přihlášeného admina) s vzorovými daty (faktura `2605001`, klient „Test
Klient s.r.o.", …).

### 19.4.4 Placeholders

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

## 19.5 Activity log

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

## 19.6 Elektronické podpisy

Elektronické podpisy mají vlastní stránku **Systém -> Elektronické podpisy**.
Aktuální konfigurace už není jeden certifikát dodavatele, ale sada
podpisových profilů a mapování pro jednotlivé výstupy. Detailní postup je v
[kapitole 21. Elektronické podpisy](28_Elektronicke_podpisy.md).

## 19.7 Tipy

- **Test šablony** vždy před produkčním nasazením — typo v Twig syntaxi by
  rozbilo odesílání všem klientům.
- **Role accountant** je dobrá pro externí účetní — vidí faktury, banku,
  exporty i daňové výkazy, ale nemůže upravit uživatele ani konfiguraci.
- **Role readonly** dej auditorovi nebo klientovi — vidí a exportuje totéž co
  účetní (vč. DPH podkladů), ale nemůže nic změnit.
- **Z Activity logu** zjistíš všechno — i kdo neúspěšně se zkoušel přihlásit
  (filter akce `auth.login_failed`).
