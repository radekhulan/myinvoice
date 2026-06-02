# 15. Pravidelné fakturace (Recurring invoices)

Šablony pro automatické generování faktur v pravidelných intervalech. Hodí se
pro paušální platby (hosting, předplatné, retainer …), kde se fakturuje stále
stejná částka stejnému klientovi.

Šablona drží konfiguraci (periodicita, položky, klient, dodavatel) a cron
`cron-generate-recurring-invoices.php` (běží denně) podle ní vytváří nové
faktury. Volitelně je rovnou **vystaví** (přidělí číslo faktury) a/nebo
**odešle klientovi e-mailem**.

## 15.1 Kdy použít

- Pravidelný měsíční / čtvrtletní / pololetní / roční paušál
- Stejné položky a stejné částky (drobné posouvání měsíce v popisech řeší
  jeden přepínač — viz dále)
- Fakturuješ tomu stejnému klientovi opakovaně

Pro **jednorázové znovuvystavení** stávající faktury (např. „udělej ze
faktury 5/2026 fakturu 6/2026") slouží klasický **klon faktury** v detailu
faktury — ne pravidelná šablona.

## 15.2 Vytvoření šablony

V menu **Systém → Pravidelné fakturace** klikni **+ Nová šablona**, nebo
v detailu existující faktury tlačítko **Vytvořit šablonu z této faktury**
(předvyplní klienta, položky, měnu, jazyk i payment method).

### 15.2.1 Sekce „Periodicita"

- **Periodicita** — Měsíčně / Čtvrtletně / Pololetně / Ročně
- **Den v měsíci** — 1–28 (28 je nejvyšší možná hodnota; cap kvůli únoru)
- **Poslední den měsíce** — pokud je zaškrtnuto, den v měsíci se ignoruje
  a faktura se vystaví vždy poslední den měsíce (28/29/30/31 dynamicky podle
  délky měsíce). Hodí se pro „vždy poslední den čtvrtletí".
- **Datum prvního vystavení** — kdy má vyjít první faktura. Šablona se po
  uložení rovnou „naplánuje" na tento den (`next_run_date`).
- **Datum ukončení** (volitelné) — po překročení tohoto data se šablona
  automaticky pozastaví (status **Vypršela**) a cron ji přeskakuje.

### 15.2.2 Sekce „Faktura"

Tady nastavíš metadata, která se zkopírují na každou vygenerovanou fakturu:

- **Typ dokladu** — Faktura nebo Zálohová faktura (proforma)
- **Měna** — určuje bankovní spojení a CNB kurz (u neCZK měn)
- **Jazyk** — `cs` nebo `en` (jazyk PDF + e-mailu)
- **Způsob úhrady** — Bankovní převod / Platební karta / Hotově / Jiný.
  U non-bank-transfer se v PDF i e-mailu nezobrazí QR kód ani bankovní
  spojení.
- **Splatnost** — počet dnů od vystavení
- **Sleva z celé faktury** — procentuální sleva (0–100 %), kterou zdědí každá
  vygenerovaná faktura. Na faktuře se projeví jako záporná položka „Sleva X %"
  (po sazbách DPH) — viz § 11.4.1.
- **Ceny s DPH / bez DPH** *(od v4.7.0)* — režim, ve kterém jsou zadané ceny
  položek šablony. „s DPH" (brutto) počítá daň „shora" koeficientem a propisuje
  se na každou vygenerovanou fakturu — viz [§ 11.2.6](11_Faktura_editor.md#1126-ceny-s-dph-vs-bez-dph-brutto--netto-režim).
- **DUZP** *(plátci DPH)* — režim, kterým se počítá datum uskutečnění
  zdanitelného plnění z `issue_date`:
    - **Stejné jako datum vystavení** *(default)* — DUZP = vystavení.
      Zachovává původní chování pro existující šablony.
    - **Poslední den předchozího měsíce** — typický CZ scénář „fakturuji
      1.6. za květnové služby". Faktura má vystavení 1.6.2026, ale DUZP
      31.5.2026. Měsíc v popiscích položek se synchronizuje k DUZP, takže
      „Hosting 05/2026" zůstane „05/2026" i když je vystavena 1.6.

### 15.2.3 Položky

Položky šablony se 1:1 kopírují na každou vygenerovanou fakturu (popis, mn.,
cena/j, sazba DPH). Sazba se bere podle vybraného `vat_rate_id` ze šablony.

> ⚠️ **Změna sazby DPH státem** — sazba je v šabloně přišpendlená na konkrétní
> řádek číselníku. Když se sazba změní (např. 21 % → 22 %), vznikne v `vat_rates`
> **nový řádek** a starý dostane konec platnosti. Šablona pak ukazuje na vypršelou
> sazbu — generování se **zastaví s jasnou chybou** (viz banner v § 15.3) a ty
> ve šabloně vybereš aktuální sazbu. Tím se nikdy tiše nevystaví doklad se starou
> sazbou. (Totéž hlídá i klonování faktury.)

> 💡 **Neplátce DPH** — pokud je dodavatel neplátce, pravidelná fakturace se chová
> stejně jako jednorázové vystavení: výběr sazby DPH se v šabloně skryje a každá
> vygenerovaná faktura je **bez DPH** (0 % „Osvobozeno"). Platí to i pro šablony
> založené dřív s nominální sazbou — generátor sazbu při vystavení sám sjednotí
> na 0 %.

### 15.2.4 Sekce „Automatizace"

- **Synchronizovat měsíc v popiscích položek s DUZP** — pokud je v popisu
  vzorec `M/YYYY` (např. „Hosting 03/2026"), automaticky se **nahradí**
  měsícem/rokem z DUZP (`tax_date`) generované faktury — případně z
  `issue_date` u proform, které DUZP nemají. Sync je idempotentní:
  šablonový popis „Hosting 03/2026" generuje „Hosting 05/2026" pokud DUZP
  spadá do 5/2026, a „Hosting 06/2026" pokud do 6/2026 — bez kumulativního
  driftu. Pattern detektor zvládá `M/YYYY`, `YYYY-MM`, `M.YYYY`, `M-YYYY`
  a varianty; plná data typu `2026-05-15` chrání lookaround a nemění je.
- **Po vygenerování rovnou vystavit** — cron rovnou přidělí číslo faktury
  z šablony číslování dodavatele a zafixuje snapshoty klienta/dodavatele/
  bankovního spojení (status = `issued`). Pokud vypneš, vygeneruje se jen
  draft a ty ho potom musíš ručně zkontrolovat a vystavit.
- **Po vystavení rovnou odeslat klientovi e-mailem** — automatický send PDF
  + e-mailu na klienta a fakturační e-maily zakázky. Vyžaduje předchozí
  volbu (nelze odeslat draft).
- **Kdy vytvořit koncept** — viz [15.2.5](#1525-otevřený-koncept-průběžný-výkaz-víceprací).
- **Připomenout dní před vystavením** — jen u režimu „Na začátku období";
  počet dní předem, kdy ti přijde e-mailová připomínka doplnit vícepráce
  (0 = neposílat).

**Default pro nové šablony** je obojí (vystavit + odeslat) zapnuté a režim
konceptu „Až při vystavení" → plně automatická pravidelná fakturace.

### 15.2.5 Otevřený koncept (průběžný výkaz víceprací)

Řeší fakturaci typu **fixní SLA + nepravidelné vícepráce**: část faktury je
stálý paušál, ke kterému během měsíce přibývá proměnný seznam víceprací.

Přepínač **„Kdy vytvořit koncept"** má dvě hodnoty:

- **Až při vystavení** *(default)* — původní chování. Faktura vznikne až
  v den vystavení (`next_run_date`) a podle automatizace se rovnou vystaví.
- **Na začátku období** — cron vytvoří **koncept** faktury (s fixními
  položkami ze šablony) **1. den fakturovaného měsíce**. Koncept pak celý
  měsíc zůstává ve stavu *draft* a ty do něj průběžně píšeš **vícepráce přes
  výkaz práce** (výkaz je editovatelný jen u konceptu). V den `next_run_date`
  (typicky konec měsíce) cron koncept automaticky **uzavře, přepočítá včetně
  víceprací, vystaví a odešle**.

  Datum vystavení i DUZP konceptu jsou od začátku nastavené na **plánovaný
  konec období** (`next_run_date` + zvolený režim DUZP) a při vystavení se
  nemění — i kdyby cron běžel o den později.

**Podmínky režimu „Na začátku období":**

- jen pro **měsíční** periodicitu,
- vyžaduje zapnuté **„Po vygenerování rovnou vystavit"** (koncept se na konci
  období uzavře sám).

**Typický scénář (fakturace za červen, vystavení a DUZP ke konci měsíce):**

1. Šablona: měsíčně, *Poslední den měsíce*, DUZP *Stejné jako datum
   vystavení*, režim konceptu *Na začátku období*, vystavit + odeslat zapnuto.
2. **1.6.** cron otevře koncept s fixním SLA řádkem (datum vystavení i DUZP
   = 30.6.).
3. **Během června** doplňuješ vícepráce do výkazu práce na tom konceptu.
4. **29.6.** (1 den předem) ti přijde e-mailová připomínka.
5. **30.6.** cron koncept uzavře, vystaví (SLA + vícepráce) a odešle klientovi.

> Pokud koncept během měsíce vystavíš ručně, cron to pozná a v den vystavení
> už nic nevytvoří — jen posune rozvrh na další měsíc.

## 15.3 Lifecycle šablony

Šablona má tři stavy:

- **Active** — cron ji každý den kontroluje; jakmile `next_run_date <= dnes`,
  vygeneruje fakturu a posune `next_run_date` o jeden cyklus
- **Paused** — cron ji přeskakuje (manuální *Vygenerovat teď* dál funguje)
- **Expired** — `next_run_date` překročil `end_date`; cron i UI ji odmítají
  spustit, dokud nezvýšíš `end_date`

V seznamu (a na detailu) šablony jsou tlačítka **Pozastavit / Obnovit**,
**Vygenerovat teď** a **Vygenerovat koncept** (jednorázový manuál run —
užitečné pro testování i pro ruční vytvoření dokladu mimo rozvrh).

- **Vygenerovat teď** — respektuje nastavení šablony: u `auto_issue=true`
  fakturu rovnou vystaví (a případně odešle). Otevře modal s **date pickerem**
  (default dnešní datum); u budoucího data upozorní žlutým warningem, že daňově
  by `issue_date` mělo odpovídat reálnému datu vystavení.
- **Vygenerovat koncept** — vytvoří **koncept** i u šablony s automatickým
  vystavením (nevystaví, neodešle) — ručně ho pak zkontroluješ a vystavíš.
  U režimu *Na začátku období* vytvoří přesně ten koncept, který by jinak
  otevřel cron 1. dne (idempotentní, k plánovanému datu, neposouvá rozvrh) —
  datum se proto nevybírá a varování o budoucím datu se nezobrazuje (budoucí
  DUZP je tu záměr, koncept se edituje celý měsíc).

> ⚠️ **Banner „Generování selhalo"** — když poslední automatické (cronové)
> generování selže (typicky kvůli vypršelé sazbě DPH nebo nekladné částce),
> uloží se poslední chyba a zobrazí se jako červený banner na detailu šablony
> a odznak v seznamu. Po úspěšném (ručním i cronovém) vygenerování banner zmizí.

## 15.4 Cron

Skript `api/bin/cron-generate-recurring-invoices.php` — spouštěj ho **jednou
denně**:

```cron
0 6 * * * cd /var/www/myinvoice.cz && php api/bin/cron-generate-recurring-invoices.php
```

Pro testy se hodí `--dry-run` (vypíše, co by se vygenerovalo, ale nic
nevytvoří).

Cron v jednom běhu zvládá tři fáze:

1. **Otevření konceptu** — u šablon v režimu *Na začátku období*, kde už začalo
   fakturované období, vytvoří koncept (idempotentně — jednou za období).
2. **Vystavení** — u šablon, kterým nastal `next_run_date`, vystaví (u režimu
   *Na začátku období* uzavře otevřený koncept, jinak vygeneruje a vystaví
   jako dřív).
3. **Připomínka** — pošle e-mailové připomínky k otevřeným konceptům, kterým
   se blíží vystavení (viz „Připomenout dní před vystavením").

**Catch-up:** pokud cron několik dní nešel, generuje jen **jednu** fakturu
za cyklus a posune o jeden krok — zbytek backlog se doplní postupně další
dny. Tím se zabrání tomu, aby po výpadku cron vygeneroval naráz 30 faktur
za poslední měsíc.

## 15.5 Kill-switch (Nastavení → Dodavatel)

V **Nastavení → Můj dodavatel** je přepínač **„Generovat pravidelné
fakturace cronem"**. Pokud je vypnutý, cron tohoto dodavatele úplně
přeskočí — všechny šablony se zastaví, dokud ho zase nezapneš. Manuální
tlačítko **Vygenerovat teď** funguje nezávisle.

## 15.6 Vazba na vygenerované faktury

Každá faktura vytvořená šablonou má vazbu `recurring_template_id` (sloupec
v `invoices`). V detailu faktury se zobrazí badge **↻ Pravidelná** s odkazem
na šablonu, ze které pochází.

Když šablonu smažeš, vygenerované faktury zůstanou (databáze má `ON DELETE
SET NULL` — vazba se vyčistí, faktura zůstane platná).

## 15.7 Activity log

Vše se zaznamenává:

- `recurring.created` / `updated` / `deleted`
- `recurring.paused` / `resumed`
- `recurring.draft_opened` — cron otevřel koncept na začátku období
  (režim *Na začátku období*)
- `recurring.generated` — když cron nebo *Vygenerovat teď* udělal fakturu
  (payload: `invoice_id`, `next_run`, `auto_issue`, `auto_send`, `sent_to`)
- `recurring.reminder_sent` — odeslána připomínka k otevřenému konceptu
- `cron.generate_recurring` — sumář jednoho běhu cronu (počet otevřených,
  vygenerovaných, vystavených, odeslaných, připomínek, chyb)

## 15.8 REST API

Pravidelné fakturace mají vlastní REST endpointy pod `/api/recurring/*`:

| Endpoint | Akce |
| --- | --- |
| `GET    /api/recurring` | seznam (filtry: `client_id`, `status`) |
| `POST   /api/recurring` | vytvořit šablonu |
| `GET    /api/recurring/{id}` | detail |
| `PUT    /api/recurring/{id}` | update |
| `DELETE /api/recurring/{id}` | smazat |
| `POST   /api/recurring/{id}/pause` | pozastavit |
| `POST   /api/recurring/{id}/resume` | obnovit |
| `POST   /api/recurring/{id}/run-now` | manuální spuštění (volitelně `issue_date`) |

Detailní schémata viz [`/api/reference`](../api/reference) (Redoc) nebo
[`/api/docs`](../api/docs) (Swagger UI, Try it out).
