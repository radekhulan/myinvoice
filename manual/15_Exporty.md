# 15. Exporty (PDF ZIP, ISDOC, Pohoda XML)

Pro účetní (interní oddělení nebo externí kancelář) nabízí MyInvoice tři
formáty hromadného exportu **vystavených faktur** a per-faktura export
**přijatých faktur** (ISDOC / Pohoda / naše PDF rekonstrukce — viz [Export přijatých faktur](18_Export_prijatych.md)).

> [!TIP]
> Pokud chceš účetní za daný měsíc předat **vše najednou v jednom ZIP** (vystavené
> i přijaté faktury, výpisy z účtu a knihu DPH, roztříděné do složek a s daňově
> korektním zařazením do období), použij **Měsíční export** v sekci Daně — viz
> [§ 30 Měsíční export (ZIP)](32_Mesicni_export.md). Exporty níže
> jsou cílené na jeden formát / jeden typ dokladu.

| Formát | Pro koho | Co obsahuje |
|---|---|---|
| **PDF ZIP** | Klasická archivace | Všechna PDF za zvolené období v ZIP archivu |
| **ISDOC 6.0.2** | Český národní standard pro B2B výměnu faktur | XML soubor pro každou fakturu, balené v ZIP |
| **Pohoda XML** | Stormware Pohoda — přímý import bez ručního opisu | Sloučený dataPack XML soubor |

## 15.1 Obrazovka exportů

V hlavním menu **Systém → Exporty**.

![Exporty](img/13_exporty.webp)

Formulář:

| Pole | Význam |
|---|---|
| Formát | `PDF ZIP` / `ISDOC` / `Pohoda XML` |
| Období | Měsíc-rok (např. „Duben 2026") nebo celé čtvrtletí (`Q1` až `Q4`) |
| Filtrovat podle | Datum vystavení nebo DUZP (u DUZP se při prázdné hodnotě použije datum vystavení) |
| Typ | Všechny / Faktury / Zálohové / Dobropisy |

Klik **Stáhnout** → soubor stažen do prohlížeče.

Měsíční export použij pro běžné předání dokladů za jeden měsíc. Čtvrtletní
export použij hlavně pro účetní předání za kvartál; aplikace vybere všechny
doklady v rozsahu příslušného čtvrtletí podle zvoleného data filtru.

## 15.2 PDF ZIP

Nejjednodušší archivace. ZIP obsahuje:

```
myinvoice-2026-Q2.zip
├── Faktura-2604001.pdf
├── Faktura-2604002.pdf
├── Faktura-2605001.pdf
├── Faktura-2606001.pdf
├── Proforma-92604001.pdf
├── Dobropis-72604001.pdf
└── ...
```

Název ZIPu obsahuje zvolené období (`2026-04` nebo `2026-Q2`). Název
jednotlivých PDF vychází z typu dokladu a variabilního symbolu.

Použití: **roční archivace** pro účetní (předáš ZIP/měsíc), **založení do
spisu**, **odeslání e-mailem revizorovi**.

## 15.3 ISDOC 6.0.2

ISDOC je český národní standard pro elektronickou výměnu faktur. Definovaný
[ISDOC.cz](http://www.isdoc.cz/) — používá ho většina českých účetních
softwarů (Money S3, Helios, Stereo, ABRA).

### 15.3.1 Struktura souboru

Každá faktura má vlastní `.isdoc` XML soubor podle ISDOC 6.0.2 schématu.
ZIP obsahuje:

```
isdoc-2026-04.zip
├── 2604001.isdoc       (XML)
├── 2604002.isdoc
├── ...
└── manifest.xml         (volitelný — seznam dokumentů)
```

### 15.3.2 DocumentType

Mapování v ISDOC:

| MyInvoice typ | ISDOC DocumentType |
|---|---|
| Faktura | `1` (běžná faktura) |
| Zálohová (proforma) | `2` (zálohová) |
| Dobropis | `5` (opravný daňový doklad) |
| Storno | (neexportuje se — interní) |

### 15.3.3 PaymentMeansCode

| Způsob platby | Kód |
|---|---|
| Bankovní převod (CZ) | `42` |
| SEPA převod (EU) | `31` |
| Hotovost | `10` |

### 15.3.4 Číslo zakázky a smlouvy

Pokud má faktura přiřazenou zakázku s vyplněným číslem zakázky / číslem
smlouvy, exportují se do ISDOC jako kolekce wrappers (XSD 6.0.2):

```xml
<OrderReferences>
  <OrderReference id="O1">
    <SalesOrderID>2026-042</SalesOrderID>      <!-- project_number -->
  </OrderReference>
</OrderReferences>
<ContractReferences>
  <ContractReference id="C1">
    <ID>SMLOUVA-001</ID>                       <!-- contract_number -->
    <IssueDate>2026-05-14</IssueDate>          <!-- IssueDate faktury -->
  </ContractReference>
</ContractReferences>
```

Některé účetní softwary tyto reference zachovávají při importu (Money S3,
Helios). MyInvoice je při [zpětném importu](16_Importy.md) také čte —
zakázka se podle `project_number` najde nebo automaticky vytvoří.

### 15.3.5 ISDOC v PDF příloze (3.6.2+)

Při generování PDF faktury se ISDOC XML přibalí jako PDF/A-3 attachment
(`/Names /EmbeddedFiles` + `/AF` v catalog). Účetní programy si data
extrahují přímo z PDF — stačí přeposlat jediný soubor. Pod variabilním
symbolem se v PDF zobrazí vizuální `ISDOC` badge.

- Vkládá se jen pro **CZK faktury s přiděleným VS**.
- Lze vypnout per-dodavatel v *Nastavení → Dodavatel → Vkládat ISDOC XML
  do PDF faktur* (default zapnuto).
- Adobe Reader / Foxit zobrazí ikonu sponky v sidebar „Attachments" panelu.

### 15.3.6 Import do účetního software

| Software | Kde naimportovat |
|---|---|
| **Money S3** | Karty → Faktury vydané → Načíst z ISDOC |
| **Pohoda** | Externí komunikace → Import dat → ISDOC |
| **Helios Orange** | Faktury vydané → Akce → Import ISDOC |
| **Stereo** | Účetní → Import → ISDOC |

## 15.4 Pohoda XML (Stormware data package)

Pohoda XML je **proprietary formát firmy Stormware** pro přímý import faktur
do účetního systému Pohoda. Na rozdíl od ISDOC je to **jeden velký XML**
(`dataPack`), ne soubor per fakturu.

### 15.4.1 Struktura

```xml
<?xml version="1.0" encoding="UTF-8"?>
<dat:dataPack xmlns:dat="..." xmlns:inv="..." xmlns:typ="..." version="2.0">
  <dat:dataPackItem id="2604001">
    <inv:invoice version="2.0">
      <inv:invoiceHeader>
        <inv:invoiceType>issuedInvoice</inv:invoiceType>
        <inv:number>
          <typ:numberRequested>2604001</typ:numberRequested>
        </inv:number>
        ...
```

### 15.4.2 Per-dodavatel konfigurace

Před prvním exportem do Pohody **musíš nastavit Pohoda kódy v dodavateli**:

**Systém → Dodavatelé → [tvůj] → Editovat → záložka Pohoda**

| Pole | Význam | Příklad |
|---|---|---|
| Číselná řada | Kód číselné řady v Pohodě | `FV` |
| Středisko | Kód střediska | `01` |
| Činnost | Kód činnosti | `100` |
| Předkontace | Kód předkontace | `300` |

Bez vyplnění některého z těchto polí export proběhne, ale **import do Pohody
hodí varování** — musíš v Pohodě dovyplnit při importu.

### 15.4.3 Číslo zakázky

Pokud má faktura zakázku s vyplněným číslem, exportuje se do hlavičky:

```xml
<inv:numberOrder>2026-042</inv:numberOrder>
```

Pohoda toto pole standardně načítá jako „Číslo zakázky" / „Číslo objednávky".
Pro per-supplier `pohoda_contract_code` (v Nastavení → Dodavatel → Pohoda)
nadále platí samostatný `<inv:contract>` blok — ten se zapisuje pro celou
číselnou řadu, `<inv:numberOrder>` per faktura.

### 15.4.4 VAT klasifikace

MyInvoice mapuje DPH sazby na **Pohoda kódy klasifikace**:

| MyInvoice DPH | Pohoda kód |
|---|---|
| 21 % | `UDA5` (úprava DPH 21 %) |
| 12 % | `UDA5_12` (úprava DPH 12 %) |
| 0 % osvobozeno | `UNX` (osvobozeno) |
| 0 % reverse charge | `PNAR` (přenesená daňová povinnost) |

### 15.4.5 Import do Pohody

1. Pohoda → **Soubor → Datová komunikace → XML import / export**
2. **Import** → vyber `myinvoice-pohoda-2026-04.xml`
3. Pohoda zobrazí náhled (kolik faktur, jaké částky)
4. Klik **Importovat** → faktury se založí

### 15.4.6 Co Pohoda XML neobsahuje

- **PDF přílohu faktury** (Pohoda generuje vlastní PDF z dat)
- **Výkaz víceprací** (přílohy se neexportují)
- **QR platbu** (Pohoda generuje vlastní)

Pokud klient potřebuje přesně tvoji PDF verzi, použij paralelně **PDF ZIP**.

## 15.5 Faktury v cizí měně (EUR / USD / …) — kurz CZK v exportu

Pro faktury v jiné měně než CZK MyInvoice automaticky přidává do exportů
**kurz ČNB** zafixovaný na faktuře — viz [§ 10.4.2](10_Faktura_editor.md#1042-faktura-v-cizi-mene-eur-usd-prepocet-do-czk).

### 15.5.1 ISDOC — `LocalCurrencyCode` + `CurrencyCode` + `CurrRate`

ISDOC export pro EUR fakturu obsahuje:

```xml
<LocalCurrencyCode>CZK</LocalCurrencyCode>     <!-- účetní měna dodavatele -->
<CurrencyCode>EUR</CurrencyCode>               <!-- faktur. měna -->
<CurrRate>24.360000</CurrRate>                 <!-- CZK / 1 EUR -->
<RefCurrRate>1</RefCurrRate>
```

Všechny `<…Amount currencyID="EUR">…</…Amount>` zůstávají v EUR. Účetní soft
si CZK ekvivalent dopočítá z `CurrRate`. Pokud faktura nemá zafixovaný kurz
(starší data před verzí 1.4 nebo selhal fetch z ČNB), `CurrRate=1` — uživatel
musí v účetním softu kurz ručně doplnit.

### 15.5.2 Pohoda XML — `inv:foreignCurrency` + `inv:homeCurrency`

Pohoda XML pro EUR fakturu obsahuje **oba** bloky v `<inv:invoiceSummary>`:

```xml
<inv:homeCurrency>                    <!-- CZK z přepočtu kurzem -->
  <typ:priceHigh>1218.00</typ:priceHigh>
  <typ:priceHighVAT>255.78</typ:priceHighVAT>
  <typ:priceSum>4055.94</typ:priceSum>
</inv:homeCurrency>
<inv:foreignCurrency>                 <!-- originál v EUR + kurz -->
  <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
  <typ:rate>24.360000</typ:rate>
  <typ:amount>1</typ:amount>
  <typ:priceHigh>50.00</typ:priceHigh>
  <typ:priceHighVAT>10.50</typ:priceHighVAT>
  <typ:priceSum>166.50</typ:priceSum>
</inv:foreignCurrency>
```

Položky (`<inv:invoiceItem>`) pro non-CZK fakturu používají `<inv:foreignCurrency>`
místo `<inv:homeCurrency>` — Pohoda po importu položkové CZK hodnoty dopočítá
z globálního kurzu.

### 15.5.3 Tipy

- **Konzultuj kurz s účetní** — některé účetní software (zejm. Pohoda) má
  vlastní kurzovní lístek a může při importu kurz přepsat. Pokud chceš mít
  v Pohodě přesný kurz z faktury, nech přepis vypnutý.
- **Backfill při exportu** — když exportuješ starší fakturu bez kurzu, MyInvoice
  ho automaticky doplní (cache → ČNB → poslední známý). Když ČNB nedostupné
  a žádný kurz není, v ISDOC dostaneš `CurrRate=1` s varováním.

## 15.6 Filtrování

| Volba | Použití |
|---|---|
| Typ = Faktury (jen) | Klasický měsíční export pro účetní |
| Stav = Zaplacené | Pro výplatu DPH (jen reálně přijaté) |
| Typ = Dobropisy | Pro samostatnou agendu oprav |

## 15.7 Tipy

- **Měsíční rytmus** — exportuj 1. den následujícího měsíce za ten skončený
  měsíc.
- **Vše v jednom balíčku** — když účetní chce za měsíc kompletní podklad
  (vystavené + přijaté faktury + výpisy + kniha DPH najednou), použij raději
  [Měsíční export (ZIP)](32_Mesicni_export.md) v sekci Daně —
  vyřeší zařazení do období daňově korektně a roztřídí vše do pojmenovaných
  složek.
- **ISDOC i Pohoda** — pokud si nejsi jistý, který formát použít, **ISDOC**
  je univerzální (otevřený standard, fungují různé softwary). Pohoda XML jen
  když víš, že příjemce má Pohodu.
- **Stáhni i PDF ZIP jako backup** — XML formáty obsahují data, ale ne grafiku
  PDF. Pokud archivuješ pro daňové účely, mít originální PDF je nutné.
- **Před prvním exportem do Pohody** → konzultuj s účetní, jaké chce kódy
  střediska / činnosti / předkontace. Bez nich import není čistý.
