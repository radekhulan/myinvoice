# 24. Banka — import výpisů a párování plateb

Místo ručního označování faktur jako zaplacených, naimportuj **GPC výpis**
z banky a systém automaticky spáruje platby s fakturami podle variabilního
symbolu a částky.

GPC (ABO) je standardní český formát pro elektronickou výměnu výpisů. Umí ho
exportovat: **KB**, **Fio Bank**, **ČSOB**, **Raiffeisenbank**, **Česká
spořitelna**, **mBank**, a další.

> [!TIP]
> Místo (nebo vedle) výpisů umí systém zpracovávat i **bankovní e-mailová avíza**
> z IMAP schránky — hodí se, když banka posílá oznámení o příchozí platbě rychleji
> než pravidelný výpis. Konfigurace bankovních účtů, IMAP schránek a parserů má
> vlastní kapitolu [Bankovní účty a e-mailová avíza (IMAP)](37_Bankovni_ucty.md)
> — najdeš ji na dalších záložkách téže stránky **Finance → Bankovní účty**.

## 24.1 Stažení GPC výpisu z banky

Postup je v každé bance trochu jiný:

| Banka | Cesta v internet bankingu |
|---|---|
| **KB** | Účet → Historie pohybů → Export → formát „GPC ABO" |
| **Fio** | Přehled účtu → Stažení dat → formát „GPC" |
| **ČSOB** | Účet → Výpisy → Stáhnout → formát „ABO" |
| **Raiffeisen** | Detail účtu → Pohyby → Export → ABO formát |
| **ČS** | Detail účtu → Výpisy → formát „ABO" |

Stáhni soubor (typicky `.gpc` nebo `.abo`, někdy `.txt`). Velikost ~10–100 KB
na měsíc obvykle.

## 24.2 Upload výpisu do MyInvoice

V hlavním menu **Finance → Bankovní účty**, záložka **Bankovní výpisy** →
tlačítko **Nahrát GPC/ABO nebo PDF**.

![Upload výpisu](img/11_banka_upload.webp)

> 💡 **PDF výpis místo GPC.** Některé banky GPC/ABO export nenabízejí (Banka
> CREDITAS), případně ho konkrétní účet nemá zapnutý. Pro **Creditas, ČSOB, KB a Raiffeisenbank**
> proto stačí nahrát rovnou **PDF výpis** — systém ho deterministicky rozparsuje
> na transakce (bez AI) a ověří, že součet sedí na počáteční a konečný zůstatek
> z hlavičky (na haléř přesně). Dál to funguje úplně stejně jako GPC — párování,
> stavy účtů i originál ke stažení. Jeden soubor může být GPC/ABO nebo PDF,
> rozhoduje přípona; naráz jde vybrat i mix obojího.

Vyber soubor (drag & drop nebo klik). Po nahrání:

1. **Hash kontrola** (SHA-256) — pokud je stejný soubor už importovaný, hláška
   „Tento výpis už byl importovaný" + zrušení.
2. **Validace bankovního účtu** — server zkontroluje, že číslo účtu z hlavičky
   výpisu patří některé z měn aktuálního dodavatele.
3. **Parsing transakcí** — přečte všechny řádky.
4. **Auto-matching** — pro každou kreditní transakci s VS hledá fakturu se
   shodným varsymbolem **a** sumou v rozmezí ± 0,01 (tolerance haléře).
5. **Update faktur** — spárované faktury → status `paid`, `paid_at` =
   `transakce.datum_zaúčtování`.

Hláška o výsledku:

```
Importováno: 12 transakcí, spárováno: 8, k manuálnímu párování: 4.
```

## 24.3 Seznam výpisů

Záložka **Bankovní výpisy** ukáže historii.

| Sloupec | Význam |
|---|---|
| Datum | Datum výpisu |
| Číslo | Číslo výpisu z banky |
| Účet | Číslo účtu / IBAN |
| Měna | CZK / EUR / … |
| Příchozí | Suma kreditních transakcí |
| Odchozí | Suma debetních transakcí |
| Spárováno | `12/14` — 12 z 14 transakcí spárováno na faktury |
| Importováno | Datum + uživatel |

## 24.4 Detail výpisu

Klik na řádek → detail.

Tabulka transakcí:

| Sloupec | Význam |
|---|---|
| Datum | Datum zaúčtování |
| Částka | + (kredit) / − (debet) |
| Měna | |
| Protistrana | Název + číslo účtu (pokud bance zaslala) |
| VS | Variabilní symbol z transakce |
| KS / SS | Konstantní / specifický symbol |
| Popis | Poznámka z banky |
| Stav | `Spárováno` (zelená) / `Bez shody` (šedá) / `Ignorováno` (oranž.) |
| Faktura | Pokud spárováno, číslo faktury (klikatelné) |

Odchozí platby se u přijatých faktur párují podle **platebního variabilního
symbolu**, interního čísla nebo čísla dokladu dodavatele. Při porovnání se
zohledňují také úvodní nuly a oddělovače v číslech dokladů. Očekávaná částka
zahrnuje **zaokrouhlení dokladu** a odečítá již uhrazené zálohy; stejně se
počítá i při hledání podle názvu protistrany nebo částky a data.

### 24.4.1 Částečné platby (více převodů na jednu fakturu)

Příchozí platba se **shodným variabilním symbolem**, ale nižší částkou než
zbývá uhradit, se zaeviduje jako **částečná úhrada** (záznam v boxu Platby
detailu faktury) — faktura zůstává pohledávkou se sníženým zůstatkem a badge
**Částečně uhrazeno**. Další převody se přičítají; jakmile platby pokryjí
částku k úhradě, faktura se označí jako zaplacená (`paid_at` = datum poslední
platby). U **zálohové faktury** se k částečné platbě plátci DPH rovnou
připraví koncept **daňového dokladu k přijaté platbě** (viz § 11.1.2);
doplatek zálohy, ke které už existuje finální doklad, se eviduje na finál.
Stejně fungují platby z **e-mailových avíz** ([37. Bankovní účty](37_Bankovni_ucty.md)).

U cizoměnové faktury placené na **CZK účet** se přepočet kurzem dokladu
použije jen k rozpoznání platby. Pokud se CZK pohyb vejde do devizové tolerance,
zaeviduje se jako úhrada celého zbývajícího obnosu v měně faktury. Skutečná
CZK částka zůstává beze změny na bankovní transakci; nemusí se rovnat
částce faktury násobené kurzem dokladu. Výrazně nižší platba se nadále
eviduje jako částečná úhrada přepočtená kurzem faktury.

### 24.4.2 Manuální párování

Pro transakce, které se nespárovaly automaticky (typicky chybí VS, nebo
částka nesedí kvůli devizovému kurzu či bankovnímu poplatku):

1. Klik **Spárovat** → otevře se modal s vyhledávačem.
2. Najdeš fakturu (číslo / klient / částka).
3. Vyber a potvrď.

Ve stejné měně se zaeviduje platba ve výši transakce. U CZK platby
cizoměnové faktury, která odpovídá celému zbytku v devizové toleranci, se
faktura vyrovná celým zbytkem v její měně; přesný CZK pohyb zůstane na
bankovní transakci. Výrazně nižší částka je částečná úhrada.
Plné pokrytí označí fakturu `paid` (`paid_at` = datum transakce).
Activity log: `bank.matched_manual`.

#### Sloučená úhrada (jedna platba na více faktur)

Když klient zaplatí **víc vystavených faktur jednou platbou** (součet sedí, ale
variabilní symbol odpovídá jen jedné faktuře — nebo žádné), nabídne modal pod
vyhledávačem sekci **Sloučená úhrada**. MyInvoice sám hledá **kombinace faktur
téhož klienta**, jejichž **součet odpovídá částce platby**, ve výchozím okně
**±7 dní** kolem data platby. Klient se jménem podobným protistraně se nabízí
první.

1. Klik **Spárovat** → v sekci **Sloučená úhrada** se zobrazí návrhy kombinací
   (klient, jednotlivé faktury s částkami a datem, celkový součet).
2. U správné kombinace klik **Spárovat (N faktur)**.
3. Každá faktura se uhradí svým **plným zbytkem** a označí jako zaplacená;
   zálohové faktury dostanou koncept finálního dokladu jako u běžné úhrady.

Pomůcky:

- **Hledat v širším okně** — když faktury vystavené dál od sebe (např. ±14 dní),
  rozšiř okno tlačítkem nad návrhy.
- **Vyber fakturu a dohledej zbytek** — pokud víš o jedné faktuře, která do platby
  patří, vyber ji v našeptávači; návrhy se omezí na kombinace, které ji obsahují.

Omezení (záměrná, kvůli správnosti): kombinace jdou jen v rámci **jednoho klienta**
a součet musí **odpovídat částce platby** (sloučená úhrada = uhradit všechny vybrané
faktury celé; není to rozpouštění jedné platby na částečné úhrady). Zrušení
spárování (§ 24.5) smaže **všechny** platby té transakce a vrátí všechny faktury
zpět mezi pohledávky. Activity log: `bank.tx_manual_match_split`.

### 24.4.3 Ignorovat transakci

Pro transakce, které nejsou platby faktur (poplatky, převody mezi vlastními
účty, refundace, …):

1. Klik **Ignorovat**.
2. Status → `Ignorováno`. Pro reporting se nepočítá.

### 24.4.4 Vytvoření přijaté faktury z výpisu (doklad o úhradě)

U **odchozí (záporné) platby**, ke které ještě nemáš v systému přijatou fakturu,
můžeš rovnou založit její koncept přímo z výpisu:

1. Detail výpisu → najdi odchozí transakci → klik **Vytvořit fakturu**.
2. Vyber **existujícího dodavatele** (nebo klik **Nový dodavatel** a založ ho).
   Dodavatel se nezakládá automaticky — musíš ho potvrdit.
3. Potvrď → vznikne **koncept přijaté faktury** v hrubé částce platby
   (1 položka, 0 % DPH) a rovnou se otevře v editoru.
4. V editoru doplň **rozpad DPH**, skutečné **číslo dokladu** a nahraj **PDF**.

Variabilní symbol z platby se předvyplní do pole VS; číslo dokladu dostane
dočasný placeholder `BANK-{id}` (přepiš ho na reálné číslo z faktury). Platba se
zároveň **spáruje** na nově vzniklý koncept (vazba, ne `paid` — to potvrdíš až po
finalizaci faktury).

> 💡 **Tlačítko „Otevřít"** u spárované transakce přeskočí na navázanou fakturu
> (vydanou i přijatou).

## 24.5 Reverse: zrušení spárování

Pokud automatika spárovala chybně:

1. Detail výpisu → najdi transakci → klik **Zrušit párování**.
2. Faktura → status zpět na předchozí (`sent` / `issued`).
3. Activity log: `bank.unmatched`.

## 24.6 Cron — automatický scan

Místo ručního uploadu můžeš nastavit **cron**, který bude pravidelně skenovat
adresář (např. `private/bank-incoming/`) a importovat nové výpisy:

```bash
cmd/cron-bank-scan.sh        # každých 30 minut
```

Setup:

1. Banka pravidelně exportuje výpis e-mailem nebo SFTP do `private/bank-incoming/`
2. Cron každých 30 min spustí `php api/bin/cron-bank-scan.php`
3. Skript projde nové soubory, importuje, přesune do `private/bank-archive/`

## 24.7 Tipy

- **Nahraj výpis **denně/týdně** — čím čerstvější, tím dříve se ti vyfiltrují
  faktury po splatnosti správně.
- **Auto-match funguje jen s VS** — bez VS musíš párovat ručně. Apeluj na
  klienty, aby VS vyplňovali (typicky ho v bance nabízí, když napíšeš číslo
  faktury jako popis).
- **Platby kartou** (bez VS) se zkusí spárovat na přijatou fakturu i podle
  **částky + podobnosti názvu** dodavatele (název protistrany na výpisu nemusí
  přesně sedět s názvem dodavatele). Spáruje se jen při jednoznačné shodě;
  jinak nech na ručním párování / založení dokladu (viz § 24.4.4).
- **Částečné platby** (klient pošle míň, ale VS sedí) se u **vydaných** faktur
  evidují automaticky jako částečná úhrada (viz § 24.4.1). U **přijatých**
  faktur se podplatba jen označí k ruční kontrole. Toleranci přesné shody lze
  ladit v `cfg.php` → `bank.matching.tolerance`; u bankovních e-mailových avíz
  ji nastavíš přímo v mapování účtu.
- **Devizový kurz** — pokud klient pošle EUR a faktura je v CZK, transakce
  nebude spárovaná (jiná měna). Manuálně.
- **Bankovní poplatek** — pokud banka strhla ze 100 EUR poplatek 1.5 EUR
  a klient zaplatil 100, dostáváš 98.5. Manuálně označíš jako částečně
  zaplacené nebo přijmeš tuto „ztrátu" jako bank fee.
