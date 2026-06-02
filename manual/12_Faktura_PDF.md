# 12. Faktura — PDF, QR platba, odeslání e-mailem

Vystavená faktura má **immutable PDF** — vygeneruje se v okamžiku vystavení a
od té chvíle se nemění (snapshot dodavatele, klienta, banky). Tím se zajišťuje
neměnnost dokladu i kdybyste si v Nastavení změnil adresu nebo bankovní účet.

## 12.1 Detail faktury

Klik na číslo faktury v seznamu otevře detail.

![Detail faktury](img/10_detail.webp)

Detail ukazuje:

- **Hlavičku** — variabilní symbol, typ, klient, data, částka, stav
- **Položky** — read-only zobrazení řádků
- **Náhled PDF** — embed iframe (lze otevřít na celou obrazovku)
- **Activity log** — kdo a kdy fakturu vytvořil / vystavil / odeslal / označil
  zaplacenou

### 12.1.1 Akční tlačítka (vpravo nahoře)

Závisí na stavu faktury:

| Stav | Dostupné akce |
|---|---|
| `issued` | Stáhnout PDF, Odeslat e-mailem, Označit zaplacené, Storno, Dobropis, Test odeslání, Test upomínky, **Editovat (force)** |
| `sent` | Stáhnout PDF, Odeslat znovu, Označit zaplacené, Upomínka, Dobropis |
| `reminded` | Stáhnout PDF, Další upomínka (cooldown 14 dní), Označit zaplacené |
| `paid` | Stáhnout PDF, Dobropis (vrátit peníze) |

> 💡 **Test odeslání / Test upomínky** — pošle e-mail jen na **tvůj** e-mail
> (ne klientovi). Užitečné pro vyzkoušení šablony nebo SMTP konfigurace.

## 12.2 PDF struktura

Vygenerované PDF obsahuje:

1. **Hlavičku** — logo dodavatele, jméno, adresa, IČO, DIČ, kontakt
2. **Adresát** — klient (firma + adresa + IČO + DIČ)
3. **Číslo faktury** + **typ** (Faktura / Proforma / Dobropis / Storno)
4. **Data** — vystaveno, DUZP, splatnost
5. **Bankovní spojení** — číslo účtu / IBAN, BIC, banka, variabilní symbol
6. **Položky** — tabulka (Popis / Množství / Cena / DPH / Celkem)
7. **Sumář** — mezisoučet, sleva, DPH rozpis, **CELKEM**
8. **(EUR / cizí měna) Přepočet do CZK** — kurz ČNB + tabulka základů DPH a DPH v CZK (světle šedé podbarvení)
9. **QR platbu** — vpravo dole (CZK SPAYD nebo EUR SEPA EPC)
10. **Patičku** — text z Nastavení dodavatele (volitelný)
11. **(volitelně) 2. strana** — Výkaz víceprací. Pokud faktura výkaz má, položka „Výkaz víceprací" v tabulce položek je **proklikávací odkaz** (podtržená) — kliknutí přeskočí přímo na stránku s výkazem.

> 💡 **Branding hlavičky** — pokud má dodavatel v *Nastavení → Branding* zapnutý
> branding, PDF přebere jeho **logo** a **akcentovou barvu** (čára pod hlavičkou,
> nadpisy, hlavička tabulky položek, světlá podbarvení). Když je logo malé nebo
> neobsahuje název firmy, zapni **„Zobrazit i název firmy vedle loga"** — vedle
> loga se pak vykreslí obchodní (nebo firemní) název. Sémantické barvy zůstávají
> vždy stejné (dobropis červená, storno šedá).

### 12.2.1 Přepočet do CZK (faktury v cizí měně)

Pokud je faktura v jiné měně než CZK, PDF obsahuje navíc:

- **Drobnou řádku v hlavním sumáři**: „Kurz ČNB: 24,360 CZK / 1 EUR (2026-05-03)"
- **Samostatnou tabulku „Přepočet do CZK"** pod sumářem se světle šedým
  podbarvením, kde je rozpis základů a DPH per sazba v CZK + celkové součty.

Kurz se ukládá na fakturu v okamžiku **prvního uložení** a nemění se ani po
vystavení, ani po editaci items (pokud se nezmění `issue_date` nebo měna).
Pokud je faktura starší a nemá zafixovaný kurz (legacy data), MyInvoice ho
**doplní automaticky** při příštím otevření detailu nebo PDF (cache → ČNB →
poslední známý). Detail viz [§ 11.4.2 Faktura v cizí měně](11_Faktura_editor.md#1142-faktura-v-cizí-měně-eur--usd---přepočet-do-czk).

## 12.3 QR platba

![QR platba na PDF](img/10_qr_platba.webp)

Pro **CZK** se generuje **SPAYD** (Short Payment Descriptor — český národní
standard). Aplikace banky to umí přečíst (KB, FIO, Air Bank, Raiffeisen,
Revolut, Wise…).

Pro **EUR** (a další non-CZK měny) se generuje **SEPA EPC** (European Payments
Council) QR — funguje pro všechny EUR účty v EU.

QR obsahuje:

- Číslo účtu / IBAN
- Částku v měně faktury
- Variabilní symbol (jen CZK SPAYD; SEPA EPC ho používá jen v poznámce)
- Měnu
- Zprávu pro příjemce (varsymbol + jméno odběratele)

> ⚠️ QR se vygeneruje jen pokud bankovní účet projde **mod-11 kontrolou**
> (CZ účty) nebo **IBAN checksum** (EUR). Neplatný účet → QR se v PDF
> nezobrazí, zbytek faktury OK.

> 💡 **CZK vs SEPA QR pro koncepty (drafts):** CZK SPAYD vyžaduje variabilní
> symbol jako povinné pole — koncepty bez VS proto nemají QR. SEPA EPC VS
> jako identifikátor nepoužívá (jen volitelný text v poznámce), takže
> **EUR/SEPA koncepty mají QR i bez VS** — užitečné pro náhled klientovi
> před vystavením.

## 12.4 Odeslání e-mailem

### 12.4.1 Manuální odeslání

Tlačítko **Odeslat e-mailem** (na detailu faktury). E-mail jde na:

- `klient.hlavni_email`
- `+ zakazka.fakturacni_emaily[]` (až 3 dodatečné adresy)

Předmět + tělo e-mailu se vezme ze šablony `invoice_new` (CZ / EN podle jazyka
klienta) — viz [19. Nastavení → § 15.5](19_Nastaveni.md).

Po odeslání:

- Status faktury → `sent`
- V activity logu záznam `invoice.sent` s adresami příjemců

### 12.4.2 Hromadné odeslání

Z [Seznamu faktur](09_Faktury.md) vybereš více faktur a klikneš **Odeslat
klientovi (N)** — bulk action.

### 12.4.3 Odesílatel a Reply-To

Per dodavatel lze nastavit:

- **From: jméno** — co se zobrazí jako odesílatel (např. „MyWebdesign.cz s.r.o.")
- **Reply-To** — kam má klient odpovědět (např. `fakturace@mywebdesign.cz` ≠
  technická adresa, ze které jde SMTP)

Nastavuje se v **Systém → Dodavatelé → [tvůj dodavatel] → Editovat**.

### 12.4.4 Volitelné přílohy emailu

V detailu faktury (i u **konceptu**) je sekce **Přílohy emailu**, kam lze
nahrát další soubory, které se přibalí k PDF faktury při odeslání klientovi.
Typické použití: smlouva, cenová nabídka, fotodokumentace, předávací protokol.

- **Přidání** — drag-and-drop nebo tlačítko **Přidat přílohu** (multi-select).
- **Limity** — 10 MiB na soubor, 20 MiB celkem na fakturu.
- **Povolené formáty** — PDF, MS Office (DOC/DOCX, XLS/XLSX, PPT/PPTX),
  OpenDocument (ODT/ODS/ODP), TXT/CSV, obrázky (JPG/PNG/GIF/WEBP/HEIC/HEIF),
  ZIP. Kontroluje se reálný obsah souboru (ne jen přípona).
- **Odeslání** — přílohy se automaticky přibalí k mailu při akci **Odeslat
  e-mailem** i u **Test odeslání**.
- **Smazání** — křížek u řádku odstraní soubor i z disku.

> ⚠️ **Přílohy se NEpřibalují k upomínkám** ani k mailu schválení výkazu —
> jdou jen s běžným odesláním faktury / proformy / dobropisu. K internímu
> stornu nelze přílohy přidat (interní typ se klientovi neposílá).

> 💡 Přílohy přežijí editaci faktury i přečíslování — jsou navázané přes
> `invoice_id`. Smazání faktury (jen u konceptů) přílohy odstraní spolu s ní.

### 12.4.5 Elektronický podpis e-mailu (S/MIME)

Odchozí e-maily lze volitelně podepisovat S/MIME certifikátem. Nastavuje se v
**Systém -> Elektronické podpisy** per dodavatel a per typ e-mailového výstupu.
Podpis se aplikuje až na sestavený e-mail včetně příloh; příjemce ho ověří v
běžném e-mailovém klientovi.

Detail nastavení je v [kapitole 21. Elektronické podpisy](28_Elektronicke_podpisy.md).

## 12.5 Historie PDF

V detailu faktury je sekce **Historie PDF** — seznam všech archivovaných
verzí PDF, které tato faktura kdy měla:

| Stav v seznamu | Co znamená |
|---|---|
| **Odesláno** (zelený badge) | PDF v této verzi bylo skutečně odesláno klientovi e-mailem. Nikdy se neníčí — je to důkaz, co klient dostal. |
| **Vystavení** | PDF z okamžiku, kdy se draft povýšil na vystavenou fakturu (změna varsymbolu / snapshotů). |
| **Úprava faktury** | PDF z doby před tím, než někdo fakturu editoval (typicky admin force edit). |
| **Změna výkazu** | Výkaz víceprací se změnil → původní PDF s 2. stranou výkazu se odložilo. |
| **Změna bank. údajů** | V Číselníku → Měny se změnil bankovní účet → PDF konceptů (bez snapshotu) se invalidovala. |

Každý řádek má tlačítka **Zobrazit** (otevře v novém tabu) a **Stáhnout**.
U odeslaných verzí navíc vidíš **kam to šlo** (seznam příjemců).

> 🛈 **Proč to existuje:** vystavená faktura má snapshot dodavatele/klienta/
> banky, takže PDF nemůže být změněno tichou cestou. Ale když se faktura
> opraví přes admin force edit, původní verze by se ztratila — historie
> PDF zachová obě (původní + novou) a u odeslané varianty navíc eviduje,
> komu konkrétně šla.

> 💡 **Nastavení retention** — historie PDF se nemaže automaticky. Cron
> `cron-cleanup.sh` standardně odeslané (was_sent=1) verze nikdy nemaže.
> Pokud potřebuješ ušetřit místo, použij ruční smazání jen u
> neodeslaných invalidačních verzí (zatím není UI; přes SQL).

## 12.6 Admin akce nad vystavenou fakturou

Sekce **Další akce** v detailu faktury skrývá několik nástrojů, které jsou
přístupné jen adminovi a používají se v krajních případech.

### 12.6.1 Editace vystavené faktury (force=1)

V krajní nouzi (admin udělal v vystavené faktuře překlep, klient ji ještě
nedostal):

1. Z detailu faktury klikni **Upravit (admin)** — vyžaduje admin roli.
2. Otevře se editor s URL `?force=1`.
3. Změny se uloží + původní PDF se invaliduje + zaloguje se `invoice.force_updated`
   v activity logu.

> ⚠️ **Editace vystavené faktury obecně NENÍ doporučená.** Změny snapshotů
> mohou být rozpor s tím, co klient dostal e-mailem. Preferuj **storno + nová
> faktura** nebo **dobropis**.

> 🛈 **Var. symbol je immutable** — force-edit ho NEzmění. Pokud chceš číslo
> změnit, vystav storno/dobropis a fakturu znovu pod novým číslem.

### 12.6.2 Nezaplacené (vrátit ze stavu paid)

Tlačítko **Nezaplacené** je viditelné jen u faktur ve stavu `paid` (admin only).
Vrátí fakturu ze stavu zaplacené zpět do `sent` (pokud byla odeslaná) nebo
`issued`, vyčistí `paid_at` a přepočítá revenue stats.

Použití:

- Někdo omylem označil fakturu jako zaplacenou (špatný klik na „Označit jako
  zaplacené").
- Přišla ti vratka — peníze odešly zpět klientovi, takže faktura už není
  reálně zaplacená.

> ⚠️ **Pokud má faktura spárovanou bankovní transakci**, akce vrátí 409 chybu
> s návodem. V tom případě musíš nejdřív v detailu výpisu kliknout **Zrušit
> spárování** — ta cascade sama vrátí jak transakci, tak fakturu zpátky
> (faktura → `issued`, transakce → `unmatched`).

Activity log: `invoice.unmark_paid` s `previous_paid_at` pro forenzní stopu.

### 12.6.3 Smazání vystavené faktury (force-delete, admin)

Force-delete je 3. možnost ve **Storno / Dobropis** modalu (otevřeš tlačítkem
„Storno / Dobropis" v detailu vystavené faktury). Volby v modalu:

1. **Vystavit dobropis** (preferované) — vytvoří draft dobropisu se zápornými
   položkami, klient dostane oficiální opravu.
2. **Stornovat (interní)** — interní označení, klient nedostane nic.
3. **⚠ Smazat fakturu (admin, force-delete)** — admin only.

Třetí možnost **nenávratně odstraní účetní doklad** z databáze:

- Cached PDF se z disku smaže (`storage/invoices/sup-X/`).
- Archiv odeslaných verzí (PDF historie) se vymaže — fyzické soubory
  v `_archive/` i DB řádky.
- Uživatelské přílohy z `attachments/{invoiceId}/` se vymažou.
- Pokud má faktura **navazující storno nebo dobropis**, smažou se
  ZÁROVEŇ přes ON DELETE CASCADE (FK `parent_invoice_id`).
- Pokud byla spárovaná s bankovní transakcí, transakce zůstane jen ztratí
  pair (najdeš ji znovu v nespárovaných).
- Var. symbol se uvolní pro znovupoužití.
- Revenue / KPI dashboardu i u klienta/zakázky se přepočítají.
- Activity log: `invoice.force_deleted` s detaily (status, total, currency,
  cascade_deleted_ids, počet smazaných souborů).

Před skutečným smazáním systém ukáže **detailní per-status varování**
(jiné pro vystavenou / odeslanou / zaplacenou / stornovanou) s doporučenou
alternativou (storno / dobropis / Nezaplacené).

> ⚠️ **Force-delete vystavené faktury používej výjimečně.** Účetní doklad
> může být v evidenci u tvé účetní, klient ho má v emailu. Smazání u tebe
> nevymaže to, co má klient nebo účetní. Defaultní řešení je **vystavit
> dobropis** — účetně správné a nechá auditní stopu.

> 💡 **Typický legální use case:** vystavil jsi fakturu omylem (jiný klient,
> špatná částka) a klient ji ještě nedostal. Pokud už dostal, vystav dobropis.

## 12.7 Změna bankovního účtu po vystavení

Pokud změníš bankovní účet v **Systém → Číselníky → Měny**, automaticky se
**invalidují PDF všech faktur**, které renderují bank info live (drafty +
faktury bez snapshotu). Faktury v stavu `issued` a vyšším mají immutable
`bank_snapshot` — jejich PDF zůstává s **původními** údaji (správně, klient ji
už dostal).

V activity logu uvidíš `currency.updated` s počtem invalidovaných PDF.

## 12.8 Tipy

- **PDF náhled v iframe na detailu** se neobnoví automaticky po editaci —
  refreshni stránku (F5).
- **Test odeslání** je nejlepší způsob, jak ověřit, že máš správně SMTP
  + DKIM + e-mailovou šablonu, **bez rizika**, že to půjde klientovi.
- **Jeden e-mail s víc fakturami nelze poslat** — každá faktura jde
  v samostatném mailu. (Hromadné odeslání = N e-mailů, ne jeden.)
- **Po odeslání e-mailu nejde stáhnout PDF zpět** — pokud se klient zeptá, je
  to v jeho schránce.
