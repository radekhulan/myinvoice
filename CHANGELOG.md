# Changelog

All notable changes to MyInvoice.cz are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Import ISDOC u dokladů se zaokrouhlením — „k úhradě" nově sedí na doklad.** Přijatá faktura z ISDOC se zaokrouhlením „k úhradě" (typicky e-faktury z e-shopů) se dosud naimportovala s částkou k úhradě = přesný součet položek, takže `K úhradě` bylo o haléře vedle skutečné částky na dokladu (a nepárovalo se přesně s platbou v bance). Import nově čte z ISDOC `<LegalMonetaryTotal>/<PayableAmount>` a haléřový rozdíl uloží jako zaokrouhlení — stejně jako už dělá rozpoznávání z PDF přes AI. Příklad: doklad se základem+DPH 999,99 a zaokrouhlením +0,01 se nově naimportuje tak, že `K úhradě` = 1 000,00. Základ a DPH zůstávají nezměněné (správně pro přiznání DPH a kontrolní hlášení). Sémantika `amount_to_pay` se nemění — zaokrouhlení se i nadále vede mimo něj (pole *Zaokrouhlení*) a do „k úhradě" se promítá stejnou cestou jako u AI importu (QR, platební příkaz, PDF, UI).

## [4.43.2] — 2026-06-29

### Fixed

- **OpenAPI — opravené cesty pravidelných fakturací (`/api/v1/recurring`).** Endpointy pravidelných fakturací byly v `openapi.yaml` zdokumentované dvakrát: jednou správně pod veřejnou cestou `/api/v1/recurring*` a jednou jako starší zbytek pod `/api/recurring*` (bez `/v1/` prefixu). Druhá varianta byla pro konzumenty veřejného API nepoužitelná (token na cestu bez `/v1/` odmítne ApiScopeMiddleware) a zároveň rozbíjela strojové parsování specifikace (`duplicated mapping key`). Stará kopie byla odstraněna, zůstává jediná korektní definice pod `/api/v1/`. Bez dopadu na běh aplikace (jen dokumentace API).

### Changed

- **Manuál — instalace nativní: stažení hotového balíčku místo buildu + sekce Aktualizace.** Kapitola *Instalace — Nativní* nově upozorňuje, že místo buildu ze zdrojáků (Composer + Node/pnpm) stačí stáhnout hotový **production bundle** z GitHub Releases (obsahuje `api/vendor/`, `web/dist/` i vyrenderovaný manuál) — přibyla sekce *4.6 Alternativa: hotový balíček (bez buildu)*. Doplněna i sekce *4.7 Aktualizace* (build ze zdrojáků vs. bundle) s odkazy na kapitolu Aktualizace.

## [4.43.1] — 2026-06-28

### Changed

- **Pravidelné fakturace — nový koncept dalšího období se otevře hned po uzávěrce předchozího.** Navazuje na 4.43.0: u režimu *Na začátku období* cron po uzavření a vystavení předchozího období (den po jeho konci, se zpětným datem k poslednímu dni) **rovnou otevře koncept dalšího období**, pokud už začalo. V praxi tak 1. den měsíce proběhne v jednom běhu „uzavři minulé období (k poslednímu dni) → otevři nové" a uživatel má koncept k zápisu víceprací k dispozici **hned od 1. dne období** (dřív až následující den). Idempotentní, jen pro *Na začátku období*, bez DB migrace.

## [4.43.0] — 2026-06-28

### Added

- **Pravidelné fakturace — měnový účet u GPC importu se sdíleným číslem účtu (#167).** Když máš jeden bankovní účet vedený ve více měnách (stejné číslo účtu pro CZK/EUR/USD), GPC/ABO výpis sám o sobě měnu nenese. Při importu proto nově zvolíš měnu účtu (a konkrétní účet), takže se výpis spáruje se správným měnovým účtem; při nejednoznačnosti import vrátí výzvu k upřesnění místo tichého zařazení.

### Changed

- **Pravidelné fakturace — režim „Na začátku období" uzavírá koncept až den po konci období.** U šablon s otevřeným konceptem (*Na začátku období*) cron dosud uzavíral a vystavoval koncept přímo v den `next_run_date` (typicky poslední den měsíce). Pokud běžel ráno, nestihla se do faktury započítat práce zapsaná do výkazu **týž poslední den**. Nově se uzávěrka posune o **1 den** za konec období (koncept zůstává otevřený celý poslední den a vystaví se až následující den) — **datum vystavení i DUZP přitom zůstávají na konci období** (`next_run_date`), faktura tedy nese stejné datum jako dřív, jen fyzicky vznikne o den později. Týká se jen režimu *Na začátku období*; standardní *Až při vystavení* se nemění. Bez DB migrace.

### Added

- **Kniha jízd — výchozí kategorie jízd.** U kategorií cest (Kniha jízd → Kategorie) lze nově jednu označit jako **výchozí** — ta se pak automaticky předvyplní při zakládání nové jízdy. Per dodavatele smí být výchozí vždy jen jedna (jako u aut). V přehledu kategorií ji označuje štítek „výchozí". **Vyžaduje migraci 0121.**
- **Banka — filtr podle roku, měsíce a účtu.** Přehled bankovních výpisů má nově filtr na **rok** a **měsíc** a na **konkrétní účet**, ve stejném designu jako přehledy faktur (sbalitelná lišta filtrů, synchronizace do URL, reset při kliknutí na položku menu). Výchozí je aktuální rok; volby účtů jsou seřazené stejně jako v *Nastavení → bankovní účty*.
- **Banka — přehlednější číslo účtu.** Číslo účtu se zobrazuje bez zbytečných vodicích nul a s kódem banky (např. `123456789 / 0300`) — v přehledu výpisů, ve filtru i v detailu výpisu, kde je navíc zvýrazněné. U účtů vedených jako IBAN se nic neořezává ani nedoplňuje.

### Fixed

- **Banka — úhrada přijaté faktury z e-mailového avíza se správně označí jako spárovaná.** Odchozí platba na přijatou fakturu dorazivší e-mailovým avízem se ve zpracovaných e-mailech chybně hlásila jako **„nespárováno" (match_failed)**, přestože transakce spárovaná byla a faktura zaplacená — kontrola brala jen vydané faktury. Nově se za úspěch považuje i spárování na přijatou fakturu (a e-mail se neoznačí jako selhání). Navíc se **auto-spárování platby zapíše do aktivity dokladu** (vystavené i přijaté faktury), takže je přímo v těle faktury vidět, čím a kdy byla zaplacená.
- **Banka — kód banky se normalizuje napříč zdroji.** GPC/ABO import dříve kód banky neukládal (na rozdíl od e-mailových avíz, která si ho načtou z textu), takže se ve filtru zobrazoval jen u některých účtů a tentýž účet se mohl v nabídce objevit dvakrát. Nově se kód banky bere autoritativně z konfigurovaného účtu, GPC import ho rovnou ukládá a migrace dorovná i starší výpisy. **Vyžaduje migraci 0122.**
- **Platební příkazy — QR tlačítko čitelné v tmavém režimu.** Aktivní (rozkliknuté) tlačítko *QR kód* u přijatých faktur mělo v tmavém režimu bílý text na světlém podkladu a text zanikal.

## [4.41.1] — 2026-06-25

### Fixed

- **Kontrolní hlášení: přijatá zahraniční služba v reverse charge patří do oddílu A.2, ne B.1 (#164).** Přijatá služba z EU nebo ze 3. země v režimu přenesení daňové povinnosti (např. Google Cloud, Microsoft Ireland, Anthropic, GitHub) se v kontrolním hlášení (DPHKH1) chybně exportovala do oddílu **B.1**, který je určen jen pro tuzemský režim přenesení (§ 92a) a kde portál *Moje daně* vyžaduje **české číselné DIČ** — portál proto hlášení odmítl (chybný formát DIČ dodavatele, chybějící datum a daň). Nově míří správně do oddílu **A.2** (přeshraniční samovyměřená plnění podle § 24 a § 25), kde se uvádí kód státu a VAT ID dodavatele. Přiznání k DPH (řádky 5/12 + zrcadlový odpočet 43) bylo přitom správně už dříve — chyba byla jen v exportu kontrolního hlášení. **Vyžaduje migraci 0120** (oprava zařazení kódů `24`/`24e` do oddílu A.2); promítne se automaticky i do již zaúčtovaných dokladů, bez nutnosti cokoli překlasifikovat.
- **Kontrolní hlášení: VAT ID dodavatele z EU si zachová písmena.** Identifikace dodavatele v oddílu A.2 (`vatid_dod`) se dříve ořezávala jen na číslice, takže irské VAT ID `IE3668997OH` skončilo jako `3668997`. Nově se zachová alfanumerická kmenová část bez kódu země (`3668997OH`), jak portál u řady států vyžaduje (Irsko, Rakousko, Nizozemsko aj.). Kód státu u Řecka se navíc správně uvádí jako `EL` (ne ISO `GR`).
- **Kontrolní hlášení: dovoz zboží ze 3. země se do hlášení neuvádí.** Dovoz zboží ze 3. země (kód `25`) se dříve také chybně dostal do oddílu B.1; nově se z kontrolního hlášení správně vynechává (vykazuje se jen v přiznání k DPH na řádcích 7/8 + odpočet 43/44).

## [4.41.0] — 2026-06-25

### Added

- **Bankovní výpis (GPC) přebírá párování z e-mailových avíz.** Když máš zapnutá e-mailová bankovní avíza a později naimportuješ oficiální GPC/ABO výpis se stejnou platbou, MyInvoice nově rozpozná, že jde o **tutéž platbu**, a místo dvojího párování **převezme párování z avíza na oficiální výpis** (GPC je zdroj pravdy). Zachová přitom i **ruční párování** a **sloučenou úhradu na více faktur** — jen se přepne na transakci z výpisu, faktury zůstanou zaplacené a nic se nezapočítá dvakrát (dřív mohla částečná úhrada skončit jako falešný přeplatek). Avízová transakce se rozpáruje. Shoda se hledá podle účtu, částky, variabilního symbolu a data (okno ±5 dní) a jen v rámci jednoho dodavatele; při nejednoznačnosti (víc možných shod) se raději nic neprovede a párování zůstane na tobě. Bez DB migrace.
- **Smazání avízo-výpisu z jeho detailu.** Výpis vzniklý z e-mailových avíz lze nově smazat přímo v jeho detailu — tlačítko se nabídne adminovi, jakmile na výpisu nezbývá žádná spárovaná položka (typicky poté, co párování převzal oficiální GPC výpis). U avízo-výpisu se zároveň skrývá nahrávání PDF (virtuální výpis žádné originální PDF nemá).

## [4.40.1] — 2026-06-24

### Added

- **Sloučená úhrada — jedna platba na více faktur.** Když klient zaplatí víc vystavených faktur **jedním převodem** (součet sedí, ale variabilní symbol odpovídá jen jedné faktuře nebo žádné), nabídne modal ručního párování v **Bance** novou sekci **Sloučená úhrada**: MyInvoice sám hledá **kombinace faktur téhož klienta**, jejichž součet odpovídá částce platby (výchozí okno ±7 dní kolem data platby, rozšiřitelné; klient s názvem podobným protistraně se nabízí první). Po potvrzení se každá faktura uhradí svým plným zbytkem a označí jako zaplacená; zálohové faktury dostanou koncept finálního dokladu jako u běžné úhrady. Volitelně lze vybrat jednu fakturu a nechat dohledat zbytek. Kombinace jdou jen v rámci jednoho klienta a součet musí odpovídat částce platby (uhrazení vybraných faktur celých, ne rozpouštění platby na částečné úhrady). Zrušení spárování smaže všechny platby dané transakce a vrátí faktury zpět mezi pohledávky. **Vyžaduje migraci 0119** (uvolnění unikátního klíče na evidenci plateb — jedna bankovní transakce smí nově založit platbu na každou fakturu zvlášť).

### Fixed

- **Bankovní párování: cizoměnová faktura bez kurzu se nepřevádí kurzem 1:1.** Při párování cizoměnové faktury bez vyplněného směnného kurzu na platbu v CZK se dříve mohla použít chybná hodnota 1 : 1; nově se taková faktura k párování nenabídne a sloučenou úhradu odmítne s jasnou hláškou (chybí kurz).

## [4.40.0] — 2026-06-23

### Added

- **Odebrání ukázkových (sample) dat (#162).** Ukázková data, která si necháš vygenerovat při instalaci, šlo dosud odstranit jen úplným resetem celé databáze. Nově v **Systém → Nastavení** přibyla sekce **Ukázková data** s tlačítkem *Odebrat ukázková data* — smaže přesně vygenerovanou sadu (klienti, dodavatelé, zakázky, vydané i přijaté faktury, dobropisy, pravidelné fakturace, kniha jízd) a tvoje vlastní záznamy nechá být. Sekce se zobrazí jen tehdy, když nějaká ukázková data v systému jsou. Funguje díky tomu, že generátor si nově každou vytvořenou položku eviduje (migrace 0118), takže ji lze později odebrat na milimetr přesně. Endpoint je admin-only.
- **`reset.php --keep-users-supplier`.** Nový přepínač CLI resetu smaže jen byznys data (klienti, doklady, banka, dokumenty, kniha jízd, pravidelné fakturace, importy, daňová podání), ale **ponechá uživatele, dodavatele a jeho konfiguraci** (měny, číslování dokladů, podepisování PDF, e-mail/banka nastavení, číselníky). Hodí se pro „start načisto" bez nutnosti znovu procházet setup, i pro úklid starších ukázkových dat, která vznikla ještě bez evidence.

### Fixed

- **`bin/sample.php` už nejde spustit nad databází s daty.** Generátor ukázkových dat (CLI) dosud neměl ochranu, kterou má průvodce instalací — druhé spuštění proto naduplikovalo klienty a doklady a skončilo chybou na duplicitní SPZ vozidla, přičemž v databázi zůstala polovičatě vložená data. Nově generátor odmítne běh, pokud pro dodavatele už nějací klienti nebo doklady existují, a celé generování běží v jedné transakci — při jakékoli chybě se tedy nezapíše vůbec nic. Bez DB migrace pro samotnou opravu (evidence sample dat přidává migrace 0118).

## [4.39.0] — 2026-06-23

### Added

- **Sekce Dokumenty přijímá i další typy souborů (např. bankovní výpisy `.gpc`/`.abo`).** Úložiště dokumentů dosud povolovalo jen pevný seznam přípon (PDF, ISDOC, obrázky…) a cokoli mimo něj **tiše zahodilo** — při přetažení ZIPu nebo celé složky s `.gpc` výpisy se sice založily podsložky, ale samotné soubory se nikam neuložily a uživatel nedostal žádné upozornění. Nově je logika obrácená: přijme se **vše kromě spustitelných a skriptových souborů** (`.exe`, `.msi`, `.bat`, `.ps1`, `.sh`, `.php`, `.js`, `.jar`…) a nebezpečných MIME typů. Bezpečnost zůstává zachována — dokumenty se vždy servírují jako příloha s `nosniff` a striktním CSP, takže se v prohlížeči nikdy nevykreslí. Bez DB migrace.
- **Dokumenty: upozornění na nenahrané soubory.** Pokud se při nahrávání (přímém, ze ZIPu i z přetažené složky) nějaký soubor nepřijme, zobrazí se nově **varování se seznamem souborů a důvodem** (nepodporovaný/spustitelný soubor, příliš velký, …) místo dosavadního tichého „Nahráno". U úloh na pozadí (velký ZIP/složka) přibyl v dokončovací hlášce souhrn „nahráno X, nenahráno Y".
- **„Skenovat adresář" v Bance se zobrazí jen když je nastavené.** Tlačítko pro dávkový sken adresáře s bankovními výpisy se ukáže jen tehdy, když je v `cfg.php` nastavený existující `bank_import.scan_root`; jinak vedlo jen k chybové hlášce. Bez DB migrace.

### Changed

- **Plánované úlohy (Systém → Plánované úlohy) skryjí nenakonfigurované scany.** Úlohy `cron-bank-scan` a `cron-scan-purchase-inbox` se v přehledu zobrazí, jen když je nastavený jejich zdrojový adresář (`bank_import.scan_root`, resp. `purchase_invoice.inbox_dir`). Bez něj scan jen tiše skipuje, takže nemělo smysl hlásit je jako „nikdy neběžela". Bez DB migrace.
- **PDF úložiště se dělí do podadresářů (interní).** Archiv PDF přijatých faktur (i import z iDokladu/Fakturoidu) i archivní kopie vydaných faktur dosud ukládaly všechny soubory do jednoho adresáře na dodavatele — při delším provozu by tam narostly tisíce souborů. Nově se přijaté faktury ukládají do **hash-shard** podadresářů (`supplier-{id}/{2 znaky}/…`, zachovává deduplikaci obsahu) a archiv vydaných faktur **po měsících** (`_archive/{RRRR-MM}/`). Existující soubory zůstávají na místě a fungují dál (cesta se čte z databáze), nové jdou do podadresářů — bez migrace a beze změny záloh (zálohují se rekurzivně). Bez DB migrace.

## [4.38.0] — 2026-06-23

### Added

- **Bankovní e-mailová avíza: podpora přeposlaných (FW) zpráv (#161).** Avíza se dají do sběrné schránky **přeposílat** (typicky z firemní pošty), aniž by je systém odmítl hláškou „Pro e-mail nebyl nalezen žádný aktivní parser provider". U přímého avíza poznává banku podle odesílatele, jenže přeposláním se odesílatelem stáváš ty — proto u IMAP účtu přibyla volba **Přijímat přeposlaná (FW) avíza** (ve výchozím stavu vypnutá), která banku rozpozná i z těla e-mailu. Volitelné pole **E-mail přeposílatele** omezí, od koho smí přeposlaná avíza chodit (adresa nebo doména). Funguje pro všechny vestavěné banky (Raiffeisenbank, UniCredit, ČSOB, Fio, Banka CREDITAS) i pro Českou spořitelnu a vlastní regex providery. Migrace 0116 a 0117.
  - Bezpečnostní poznámka: přeposláním zaniká původní podpis banky (DKIM), takže případné ověření autenticity se vztahuje na přeposílatele, ne na banku; ochranu drží i nadále struktura avíza a povinné mapování cílového účtu na bankovní účet dodavatele.

### Fixed

- **Bankovní avíza: opakované zpracování téhož avíza už nezaloží duplicitní transakci.** Pokud byl u avíza smazán záznam zpracované zprávy a schránka se znovu naskenovala, vznikla pro tutéž platbu další bankovní transakce. Nově se transakce z avíza zakládá idempotentně (podle identifikátoru zdrojové zprávy), takže opakovaný sken už jen znovu vyhodnotí spárování. Bez DB migrace.

### Changed

- **E-mailová avíza jsou v přehledu výpisů odlišena.** Měsíční souhrn avíz nese v seznamu i v detailu nenápadný štítek **„Avíza"** a místo konkrétního data (avíza se sbírají za celý měsíc) zobrazuje **název měsíce**. V detailu se u avíz navíc skryjí boxy se zůstatky a celkovými obraty — měsíční souhrn avíz na rozdíl od nahraného výpisu průběžný zůstatek účtu nenese. Bez DB migrace.

## [4.37.7] — 2026-06-23

### Fixed

- **Bankovní avíza na cizoměnových účtech: měna se nově bere z účtu, ne natvrdo CZK (#160).** Avíza Fio banky „Příjem/Výdaj na kontě" ve svém těle měnu vůbec neuvádějí, takže se u plateb na **cizoměnový účet** (např. EUR) ukládala s měnou **CZK** místo skutečné měny účtu. Důsledkem byly špatně evidované částky a hlavně **neúspěšné párování** — příchozí cizoměnová platba se porovnávala, jako by byla v korunách, takže se nikdy nespustila kurzová (FX) větev a legitimní shody s fakturou se míjely. Měna účtu je přitom v okamžiku importu už známá (účet je vyřešený na záznam v číselníku měn), nově se proto použije jako autoritativní a parserem doplněná CZK zůstává jen krajní záloha. Bez DB migrace.
  - Pro nápravu už dříve naimportovaných dat slouží jednorázový skript `api/bin/fix-email-notice-foreign-currency.php` (dorovná měnu na výpisech i transakcích a přepočítá klíč měsíčního výpisu). Spouštět nejdřív bez parametru (náhled), pro zápis s `--apply`.

## [4.37.6] — 2026-06-22

### Changed

- **Kniha jízd → „Načíst z faktur": přehlednější seznam.** Modal s fakturami od čerpacích/nabíjecích stanic nově zobrazuje **všechny dosud nespárované faktury, ale u spárovaných jen posledních 10** (dříve se vypisovaly úplně všechny, což u delší historie tankování dělalo zbytečně dlouhý a nepřehledný seznam). Modal má navíc **zavírací křížek vpravo nahoře** a nadbytečné tlačítko „Zavřít" v patičce bylo odebráno. Bez DB migrace.
- **Odstraněn strop 255 dodavatelů — `supplier.id` rozšířen z `TINYINT` na `INT UNSIGNED` (migrace 0115).** Identifikátor dodavatele (`supplier.id`) a všech 35 navázaných sloupců `supplier_id` byly typu `TINYINT UNSIGNED` s tvrdým stropem 255 záznamů; hostovaná multi-tenant verze by na 256. dodavateli narazila na limit a založení dalšího by selhalo. Migrace rozšiřuje klíč na `INT UNSIGNED` (~4,3 mld) a sjednocuje jeho šířku s ostatními entitními klíči v databázi. Součástí je bezpečné přepojení 36 cizích klíčů (drop → změna typu → obnova), celé idempotentně. Samostatných (self-hosted) instalací s jedním dodavatelem se limit nikdy netýkal, přesto je vhodné na nový formát přejít.
  - ⚠️ **Doporučujeme aktualizovat mimo pracovní dobu / v servisním okně.** Změna typu sloupce probíhá v MariaDB kopií tabulky (`ALGORITHM=COPY`) a obnova cizích klíčů revaliduje data — u větších tabulek (vystavené i přijaté faktury, klienti, tankování) proto může migrace na okamžik zamknout zápis. Na malých databázích proběhne během několika sekund.

## [4.37.5] — 2026-06-22

### Fixed

- **Bankovní avíza ČS „Odešla platba": e-mail se znovu rozpozná (#158).** Některá avíza České spořitelny přestala procházet hláškou „Pro e-mail nebyl nalezen žádný aktivní parser provider", přestože stejný text vložený ručně do Testu parseru prošel. Příčinou nebyla diakritika v přenosu, ale **dvojí dekódování quoted-printable**: tělo e-mailu už dorazí dekódované (platné UTF-8), ale v marketingové patičce avíza je sledovací odkaz s neúmyslnými sekvencemi „=XX" (`…&id=0729…&source-id=aauesx…`), které opětovné dekódování rozbilo — tím se znevalidnil celý text, ten se pak chybně překlopil jako windows-1250 a diakritika se zdvojila na „zmršené" znaky (`Směr` → `SmÄ›r`), takže detekční vzor nesedl. V Testu parseru uživatel vkládal jen blok transakce bez patičky, proto tam k chybě nedošlo. Nově se opětovné dekódování provede jen tam, kde nerozbije už platné UTF-8 tělo. Bez DB migrace.

## [4.37.4] — 2026-06-22

### Fixed

- **Bankovní avíza: detekce e-mailu odolná vůči diakritice.** Přeposlaná avíza (forward přes jiný server) chodí občas v jiném kódování nebo s rozbitou/chybějící diakritikou. Parser ale poznával e-mail podle vzorů s diakritikou (`Směr platby`, `Variabilní symbol`…), takže stačilo, aby se cestou rozbilo jediné „ě"/„č", a zpracování spadlo s hláškou „Pro e-mail nebyl nalezen žádný aktivní parser provider" — přitom stejný text vložený ručně do Testu parseru prošel. Nově se detekce i vytěžení polí vyhodnotí **tolerantně k diakritice**: vzor se zkusí nejdřív přesně (u čistého avíza se diakritika v datech zachová) a při neshodě znovu nad textem i vzorem sklopeným na ASCII, takže `Směr platby` i `Smer platby` se vyhodnotí stejně. Bez DB migrace.
- **Matoucí typ parseru „Regex" u vestavěných providerů.** Fio banka se v tabulce parser providerů zobrazovala jako typ „Regex" stejně jako editovatelný regex provider České spořitelny, ačkoli je to vestavěný kódový parser, který se needituje. Nově se Fio zobrazí jako „Fio banka" a jakýkoli další vestavěný parser jako „Vestavěný parser" — typ „Regex" tak mají už jen skutečné uživatelské (editovatelné a duplikovatelné) providery. Bez DB migrace.

### Added

- **Duplikace parser provideru bankovních avíz.** Systémový provider (např. Česká spořitelna) nejde přímo editovat, protože je společný pro všechny. U každého regex provideru je nově tlačítko **Duplikovat**, které vytvoří plně editovatelnou kopii dodavatele — v ní lze doladit vzory (smazat vzor předmětu/těla, zvolnit diakritiku), otestovat přes Test parseru a v mapování účtu na ni přepnout. Bez DB migrace.

## [4.37.3] — 2026-06-21

### Added

- **Import vydaných faktur z uživatelského exportu Pohody (`responsePack`).** Import dokladů (`Nastavení → Import`) uměl dosud jen Pohoda XML v podobě importního balíku (`dataPack`). Nově přijme i **XML vyexportované přímo z Pohody** — uživatelský export vydaných faktur (kořen `responsePack` se seznamem `listInvoice`, faktury v `lst:invoice`), typicky soubor `VydFaktury.xml`. Rozpoznání formátu i kódování Windows-1250 proběhne automaticky; faktury se založí jako vydané (dohledání odběratele podle IČO/ARES, kontrola duplicit podle variabilního symbolu, rekapitulace DPH ze souhrnu dokladu). Bez DB migrace.

## [4.37.2] — 2026-06-20

### Changed

- **Sjednocené akční lišty detailů (vydaná i přijatá faktura, klient, pravidelná fakturace, zakázka).** Přeplácané toolbary (detail vydané faktury jich měl až 12 v jedné řadě) nahradila jedna sdílená **lišta akcí** se třemi úrovněmi: hlavní akce podle stavu dokladu jako plné tlačítko (vystavit → uhradit → upomínka …), podpůrné akce vedle a zbytek v rozbalovacím menu **„Další akce"**. Méně časté, administrátorské a nevratné akce (test odeslání, úprava vystaveného dokladu, storno/dobropis, archivace …) jsou v menu navíc schované pod sbalitelným oddílem **„Pokročilé"**. Dosavadní samostatné **spodní panely akcí byly zrušeny** a jejich tlačítka přesunuta do tohoto menu — žádná akce nezmizela. Na **mobilu** zůstávají inline jen první dvě akce, zbytek je v menu; na desktopu se lišta nezalamuje. Tlačítko menu má vlastní vzhled pro světlý (decentní) i tmavý (výrazný) režim. Bez DB migrace.

## [4.37.1] — 2026-06-20

### Changed

- **Sjednocený vzhled platební oblasti PDF faktury.** QR kód a blok „Bankovní spojení" jsou nově v **jednom podbarveném platebním panelu** (bez samostatného rámečku QR), barevně napojeném na branding dodavatele. Do bankovního spojení přibyl **variabilní symbol** a **název banky** (pokud je znám). U **zaplacené faktury** dostane panel jemný **zelený nádech** a jeho výška se srovná s blokem „Celkem" vpravo. Bloky **Dodavatel/Odběratel** jsou bez rámečků, jen s decentním podbarvením (Odběratel zvýrazněn akcentem palety). Popisek „QR Pay" odstraněn. Bez DB migrace.
- **Výkaz materiálu u plátce DPH ukazuje souhrn „Celkem bez DPH" i „Celkem s DPH".** V PDF dokladu se u výkazu materiálu plátci nově tisknou obě sumy včetně sazby DPH výkazu; neplátce vidí jako dosud jediné „Celkem". Bez DB migrace.

### Fixed

- **Název banky v PDF se u některých vystavených faktur nezobrazoval.** Faktury vystavené dřív, než se název banky doplnil do číselníku měn, měly v bankovním snapshotu prázdný název a ten přebíjel živá data. Nově se prázdný název (a BIC) doplní z aktuálních dat, pokud jde o **stejný účet** (shodný kód banky / IBAN); historické číslo účtu zůstává ze snapshotu. Bez DB migrace.
- **Patička PDF: „Krajského soudu v Plzni" se tisklo jako „v P zni".** Při velikosti písma 7,5 pt přebíjel kerning fontu Montserrat pár „Pl" tak, že písmeno „l" vizuálně zmizelo. Opraveno drobným prostrkáním textu spisové značky. Bez DB migrace.

## [4.37.0] — 2026-06-19

### Added

- **Platební příkazy pro přijaté faktury (ABO/KPC, CSV, PDF)** (#150). Z nezaplacených přijatých faktur lze nově hromadně vygenerovat **příkaz k úhradě**. Pro koruny vzniká **ABO (KPC)** soubor pro import do internetbankingu, ostatní měny dostanou **CSV** (s BOM a ochranou proti CSV injection) nebo **PDF na šířku**. Účet plátce se volí podle měny a kandidáti se zobrazují ve dvou opticky odlišených tabulkách (CZK přes ABO vs. ostatní měny přes CSV/PDF). Účet příjemce lze **ověřit proti registru plátců DPH (CRPDPH)** — zveřejněné účty a nespolehlivost plátce, na vyžádání i automaticky — případně ručně doplnit či upravit, zobrazit QR k platbě nebo inline náhled dokladu. Stav „**Předáno k úhradě**" je odvozený příznak (`payment_ordered_at`), ne stav dokladu: má vlastní filtr i badge v seznamu přijatých faktur a při generování příkazu lze zvolit „jen označit" nebo „rovnou označit jako zaplacené" (jinak úhradu potvrdí až párování bankovního výpisu). Historie příkazů se ukládá se snapshotem a jde je znovu stáhnout (CSV/PDF/ABO). Nová kapitola manuálu **§ 20 „Platební příkazy"**. **Vyžaduje DB migraci** (0113: `payment_orders`, `payment_order_items`, `purchase_invoices.payment_ordered_at` + `payment_constant_symbol`, `supplier.abo_client_number`).
- **Výkaz materiálu vedle Výkazu práce → 2 souhrnné položky na faktuře.** K výkazu faktury lze nově přidat druhý oddíl — **výkaz materiálu** (množství + MJ + cena za MJ místo hodin), který se na fakturu přenese jako druhá souhrnná položka „Materiál" vedle „Práce". Každý výkaz nese **vlastní sazbu DPH** (práce default 21 %, materiál default 12 %) a cena materiálu se zadává v cenové konvenci dokladu (s/bez DPH podle `prices_include_vat`). Materiál se promítá do PDF faktury i do dokladu ke schválení, do schvalovacích e-mailů a do veřejného sledovacího odkazu; „K vyúčtování" = práce + materiál. Editace probíhá v jednom modálním okně se dvěma sbalitelnými sekcemi. Manuál **§ 10.11**. **Vyžaduje DB migraci** (0114: `work_reports` + sazby DPH a souhrn materiálu, nová tabulka `work_report_materials`).

### Fixed

- **Daňové termíny na dashboardu respektují periodicitu DPH** (#156). Sekce „Akce pro tebe" generovala výzvu „DPH + KH za uplynulý měsíc" natvrdo k 25. dni aktuálního měsíce bez ohledu na zdaňovací období dodavatele — čtvrtletní plátci tak uprostřed kvartálu dostávali zavádějící měsíční daňovou akci. Nově se periodicita řeší správně: měsíční plátci beze změny, **čtvrtletní FO** dostanou sloučenou výzvu „DPH + KH za X. čtvrtletí" až po skončení kvartálu, **čtvrtletní PO** mají Kontrolní hlášení měsíčně (§ 101e) odděleně od čtvrtletního přiznání k DPH. Bez DB migrace.

## [4.36.0] — 2026-06-19

### Added

- **Kvartální Kontrolní hlášení pro OSVČ** (#154). Fyzické osoby (OSVČ), které jsou kvartálními plátci DPH, mohou nově podávat **Kontrolní hlášení kvartálně** (§ 101e odst. 2 zákona 235/2004 Sb.) — stejně jako přiznání k DPH. Přepínač Měsíčně / Kvartálně se zobrazí v `Daně → Kontrolní hlášení` tehdy, když daňové nastavení identifikuje typ poplatníka jako FO; právnické osoby musí podávat stále měsíčně (§ 101e odst. 1) a přepínač se jim nezobrazí. Vygenerované XML používá atribut `ctvrt` místo `mesic` ve VetaD. Bez DB migrace.
- **Kvartální Souhrnné hlášení pro poskytovatele služeb** (#154). Plátci, kteří do EU dodávají výhradně **služby** (kód 22, § 9/1), mohou nově podávat Souhrnné hlášení **kvartálně** (§ 102 odst. 3–4 ZDPH). Přepínač je vždy dostupný; aplikace na kvartální podání upozorní, že kód 20 (dodání zboží) vyžaduje měsíční periodicitu bez ohledu na typ plátce. Vygenerované XML používá atribut `ctvrt` ve VetaD. Bez DB migrace.
- **Volba data úhrady přijaté faktury** (#155). Tlačítko „Označit jako uhrazené" v detailu přijaté faktury nyní otevírá modální okno s výběrem **data úhrady** (předvyplněno dneškem). Chování odpovídá vystaveným fakturám — datum se zapíše do `paid_at`. Bez DB migrace.

## [4.35.1] — 2026-06-18

### Fixed

- **Aktualizace z UI nefungovala na Windows s PowerShell 7 / jiným adresářem** (#153). Host watcher, který aplikuje upgrade spuštěný z UI (Systém → Aktualizace), spouštěl vlastní update natvrdo příkazem `powershell` (Windows PowerShell 5.1). Na strojích, kde je jen **PowerShell 7 (`pwsh`)**, tak update „neprošel" a musel se spouštět ručně. Watcher nově spustí update **tímtéž PowerShell hostem**, pod kterým sám běží (`pwsh` i `powershell`), zjištěným za běhu. Cesty řeší z umístění skriptu, takže funguje i z jiného adresáře než `C:\inetpub\myinvoice`. Aktualizován i manuál (Scheduled Task i test režim používají `pwsh` a vlastní instalační cestu). Bez DB migrace.

## [4.35.0] — 2026-06-18

### Added

- **Instalace přes Portainer / Dockge (GUI, bez příkazové řádky).** Protože je image veřejný na GHCR, jde MyInvoice nasadit i čistě z webového správce kontejnerů — bez klonování repa, bez SSH, bez `cfg.docker.php`. Nový **`docker-compose.portainer.yml`** je plně 12-factor (vše přes proměnné prostředí), **`portainer-template.json`** přidává do Portaineru one-click App Template a manuál má novou sekci **§ 3.11** (Portainer App Template + ruční Stack, Dockge, HTTPS, GUI aktualizace). Bez DB migrace.

### Fixed

- **Full-ENV deploy přes plain HTTP: přihlašovací cookie se neuložila.** Session cookie se defaultně jmenuje `__Host-myinvoice_session`, což prohlížeč přijme jen přes HTTPS. Při nasazení čistě přes proměnné prostředí (Portainer/Dockge/PaaS) a přístupu přes `http://host:port` se tak nešlo přihlásit a název cookie se přitom nedal přebít přes ENV (šlo jen `cookie_secure`/`samesite`). Doplněn override **`MYINVOICE_SESSION_COOKIE_NAME`**, takže HTTP deploy může použít ne-`__Host-` jméno. Bez DB migrace.

## [4.34.3] — 2026-06-18

### Fixed

- **Průhledné PNG logo mělo v PDF černé pozadí.** Logo nahrané jako PNG s průhledným pozadím se v PDF dokladech (faktura i výkaz víceprací) vykreslovalo s **černým** pozadím, přestože v náhledu administrace i v e-mailech bylo v pořádku. Příčina je v knihovně mPDF (8.3.1 na PHP 8.5), která u truecolor PNG s alfa kanálem neaplikuje masku průhlednosti a místo ní vykreslí barvu „pod" průhlednými pixely — a editory tam typicky ukládají černou. Nově se logo pro PDF splácne na **bílé pozadí** (doklady mají bílý podklad, takže výsledek je vizuálně shodný s průhledným logem); e-maily dál používají průhledné PNG. Self-healing i pro již nahraná loga, bez DB migrace. **Tip: nahrávejte logo raději ve formátu SVG** — to se v PDF vykresluje vektorově (ostře v libovolné velikosti) a tímto problémem netrpí. (#152)

## [4.34.2] — 2026-06-17

### Fixed

- **Špatný variabilní symbol při importu výpisu GPC/ABO (Fio i ostatní banky).** U plateb, jejichž **variabilní symbol končí nulou**, se při importu bankovního výpisu ve formátu GPC poslední nula (nebo více nul) ztrácela — např. VS `260100010` se uložil jako `26010001`. Příčina: pole VS je v GPC zleva doplněné nulami a parser je ořezával z **obou** stran, takže spolu s vedoucími (výplňovými) nulami zmizely i **významné koncové** nuly. Nově se strhávají pouze vedoucí nuly. Stejná oprava se týká i **konstantního** a **specifického** symbolu. Bez DB migrace. (#150)

## [4.34.1] — 2026-06-17

### Changed

- **Odkazy „Podpořte autora" a „Chcete jinou funkci?" v patičce jsou nově v brand barvě.** Dřív splývaly šedou s ostatním textem patičky — teď jsou v primární barvě a tučnější, takže jsou výraznější a lépe viditelné. Čistě vizuální úprava.

### Fixed

- **Po přeskočení dodavatele v úvodním nastavení nešlo založit klienta.** Když uživatel v onboardingu zaškrtl „Vyplnit dodavatele později" a pak šel ručně založit klienta, formulář i přes vyplnění všech povinných polí skončil na nicneříkající hlášce **„Validace selhala"**. Příčina: bez dodavatele neexistují žádné měny (číselník měn se zakládá per-dodavatel), takže se k formuláři nedostala výchozí měna a celá aplikace je beztak vázaná na existenci dodavatele. Nově aplikace tento stav rozpozná a místo padajícího formuláře **nasměruje na vytvoření prvního dodavatele** — na přehledu se zobrazí výzva s tlačítkem a zakládací formuláře se do té doby zpřístupní až po jeho vytvoření. Backend navíc u API vrací jasnou hlášku „nejdříve vytvořte dodavatele" místo obecné chyby validace. Bez DB migrace. (#151)
- **Párování plateb z e-mailových avíz České spořitelny — šablona „Odešla platba".** Novější avíza ČS uvádějí v bloku transakce řádky **„Z účtu:"** (odesílatel) a **„Na účet:"** (příjemce) místo dřívějších „Číslo účtu:" / „Číslo účtu protistrany:" — kvůli tomu se nenačítal **účet protistrany**. Parser teď tyto řádky rozpozná a podle **směru platby** (odchozí/příchozí) správně přiřadí, která strana je vlastní účet a která protistrana. Testovací nástroj parserů (*Nastavení → Bankovní účty → Parser provideři → test*) navíc u bank, které datum platby v těle neuvádějí (ČS, Fio), simuluje doručený e-mail aktuálním datem — dřív hlásil chybějící povinné pole `posted_at`, i když by avízo v ostrém provozu prošlo. Bez DB migrace. (#147)

## [4.34.0] — 2026-06-17

### Added

- **Automatické doplnění názvu banky podle kódu (číselník ČNB).** Při zadávání bankovního účtu (v počátečním nastavení i ve správě účtů) se po vyplnění **kódu banky** sám doplní její **název** podle oficiálního číselníku platebního styku ČNB (např. `0800` → *Česká spořitelna*). Ručně přepsaný název zůstává — automatika ho přepíše jen když je pole prázdné nebo název sám pochází z číselníku. Bez DB migrace.
- **Tlačítko „Detail klienta" v detailu zakázky.** Z detailu zakázky vede přímý odkaz na kartu klienta; dostupné i v režimu jen pro čtení.

### Fixed

- **Dávkový import přijatých faktur ve formátu `.isdocx` nově ukládá i originální PDF.** Při importu přes *Import → Přijaté faktury* se z balíčku `.isdocx` (ZIP s `.isdoc` + PDF + `manifest.xml`) dosud načetla jen strukturovaná data, ale **přiložené PDF se zahazovalo** — faktura tak zůstala bez náhledového dokladu. Nově se čitelné PDF archivuje k faktuře stejně jako u nahrání přes dropzone/AI a u skenu inboxu (sjednoceno do jednoho sdíleného úložiště). Týká se i přímo nahraného PDF/A-3 s vloženým ISDOC. Bez DB migrace. (#149)

## [4.33.0] — 2026-06-16

### Added

- **Daňový optimalizátor: hlídání limitu sociálního pojištění u vedlejší činnosti.** Při zaškrtnuté **vedlejší činnosti** přibude v teploměru běžícího roku řádek, který hlídá blízkost k **rozhodné částce pro povinnou účast na důchodovém (sociálním) pojištění** (2025 = 111 736 Kč, 2026 = 117 521 Kč dle ČSSZ). Pod ní se z vedlejší SVČ sociální pojištění neplatí. Na rozdíl od ostatních limitů se měří proti **projektovanému zisku** (příjmy − výdaje dle paušálu / skutečných výdajů), ne proti příjmu — ukáže, zda zisk zůstane pod limitem, nebo limit překročíš a v kterém měsíci. Částku lze pro daný rok upravit v *Číselníky → Daňové konstanty*. Bez nové DB migrace. (#134)

### Fixed

- **Kontrolní hlášení a přiznání DPH u pořízení z EU / reverse charge — správné zaokrouhlení a řádky.** U dokladů v cizí měně se samovyměřená daň (pořízení zboží z JČS, přijetí služby, dovoz) nově počítá **ze základu přepočteného na Kč × sazba** (§ 37/1) místo z cizoměnové daně přenásobené kurzem. Dvojí zaokrouhlení dřív rozcházelo daň v **oddílu A.2 kontrolního hlášení** oproti přiznání o haléře (např. základ 100,05 € × kurz 25,00 = 2 501,25 Kč → daň 525,26 Kč, ne 525,25 Kč jako zaokrouhlení v eurech). V **přiznání (DPHDP3)** se navíc opravil řádek **43** (nárok na odpočet ze samovyměřených plnění) — plnil se do atributů řádku 45 (korekce odpočtu dle §75/§77/§79), takže portál EPO mohl hlásit chybu — a doplnil se chybějící součtový řádek **46** (odpočet daně celkem). Ověřeno proti referenčnímu schématu i výstupu EPO MF ČR.

## [4.32.0] — 2026-06-16

### Changed

- **Docker image je nově alpine/nginx — ~3× menší (~92 MB místo ~293 MB) a výrazně úspornější na RAM.** Běží na `php:8.5-fpm-alpine` + nginx + php-fpm místo Debian/Apache. Funkčně je identický (stejné API i chování); `/data` a databázové volume jsou plně kompatibilní, takže **existující instalace se zmigruje sama při příštím `cmd/docker-update`** (pull `:latest`) bez ztráty dat. Idle spotřeba RAM aplikace klesla na ~26 MB. Debian/Apache varianta zůstává v repu jako fallback (`Dockerfile`); pro rollback na GHCR pinni starší tag (`≤ v4.31.0`).
- **MariaDB a PHP-FPM doladěné pro hosting s málo RAM/diskem.** `performance_schema=OFF` ušetří ~100–200 MB RAM, InnoDB redo log zmenšen z 96 na 48 MB (~50 MB méně na disku), php-fpm jede v režimu `ondemand`. Vše laditelné přes `.env`: `DB_INNODB_BUFFER_POOL`, `DB_INNODB_LOG_SIZE`, `PHP_FPM_MAX_CHILDREN`, `OPCACHE_MEMORY`.
- **Všechny PDF výstupy (faktury, přijaté faktury, Kniha DPH, kniha jízd i uživatelský manuál) sjednoceny na fonty Montserrat + JetBrains Mono.** Manuál dříve používal DejaVu — nově je vše brandově konzistentní. DejaVu Sans zůstává jen jako fallback pro symboly (✓ ✗ ⚠). Velikost přibalených mPDF fontů v image klesla z 93 MB na 9 MB.
- **Spolehlivější `cmd/docker-update`, `docker-install`, `docker-ghcr`.** Režim (stažení z GHCR vs lokální build) se nově detekuje z image běžícího kontejneru, ne z přítomných compose souborů — odstraňuje případy, kdy update u GHCR nasazení omylem stavěl image lokálně. `docker-install` preferuje stažení hotového image z GHCR. Přebití přes `MYINVOICE_UPDATE_MODE` / `MYINVOICE_INSTALL_MODE`.

### Added

- **`cmd/docker-prune-images.{sh,ps1}`** — detekce a úklid zastaralých Docker image MyInvoice (chrání běžící i v compose referencované). `docker-update` po sobě navíc uklidí osiřelé (dangling) vrstvy.
- **Kniha jízd: rychlé akce** — přidání jízdy/tankování přímo z topbaru a z položky „+" v hlavním menu.

### Fixed

- **Náhled na výkaz práce** — logo dodavatele v hlavičce náhledu a vynucený světlý režim pro čitelnost; doplněna kapitola manuálu (§14.7) a robustnější tlačítko v e-mailu se schvalováním výkazu.

## [4.31.0] — 2026-06-15

### Added

- **Náhled na výkaz práce — sdílení rozpracovaných výkazů přes odkaz.** V detailu klienta i zakázky je nové tlačítko **„Poslat odkaz na sledování výkazu práce"**. Klient dostane trvalý odkaz, na kterém vidí vždy **aktuálně otevřené (nevyfakturované) výkazy práce** — počet hodin i průběžnou částku k vyúčtování — ještě než z nich vznikne faktura. U odkazu na klienta se zobrazí všechny jeho otevřené výkazy, u odkazu na zakázku jen výkazy té zakázky. Náhled se aktualizuje sám. Při prvním otevření se návštěvník ověří **jednorázovým kódem z e-mailu** (povolené jsou e-maily klienta, u zakázky i fakturační e-maily zakázky); po ověření si ho prohlížeč zapamatuje (180 dní) a kód už nevyžaduje. **Přihlášený uživatel (admin/účetní) vidí náhled rovnou** bez kódu. Odkaz lze kdykoli zneplatnit. Přibyly dvě e-mailové šablony (odkaz + ověřovací kód), editovatelné v *Nastavení → E-maily*. (migrace 0112)

### Changed

- **Všechna generovaná PDF jsou nově ve formátu PDF/A-3b (archivní standard).** Faktury, přijaté faktury, výkazy práce, Kniha DPH i kniha jízd se generují jako konformní **PDF/A-3b** (ISO 19005-3) pro dlouhodobou archivaci — vložené fonty, barevný profil **sRGB** (CMYK obrázky se převedou automaticky), strukturovaná ISDOC příloha zůstává. **Elektronický podpis (PAdES) archivní konformitu zachová.** Ověřeno referenčním ISO validátorem veraPDF. (PR #143)

## [4.30.1] — 2026-06-15

### Changed

- **Web dodavatele v patičce dokladu se zobrazuje jako čistá doména.** Z odkazu zmizí `http(s)://` i koncové lomítko (např. `https://mywebdesign.cz/` → `mywebdesign.cz`) a doména je prokliknutelná na plnou https adresu — na faktuře i výkazu práce. Sjednoceno s patičkou e-mailů.

## [4.30.0] — 2026-06-15

### Changed

- **Nový vzhled písma ve všech PDF dokladech.** Faktury, přijaté faktury, výkazy práce, Kniha DPH i kniha jízd se nově sázejí fontem **Montserrat** (text — výraznější, modernější, brandový) a čísla (částky, variabilní symboly, čísla účtů, datumy) fontem **JetBrains Mono** s tabulkovými číslicemi, takže se hodnoty ve sloupcích pěkně zarovnají. Nahrazuje dosavadní DejaVu Sans. Oba fonty jsou volně licencované (SIL OFL) a vkládají se přímo do PDF — dokument vypadá stejně na každém zařízení i tiskárně.
- **Přepracovaná patička dokladu.** Podpis dodavatele (název firmy · web · e-mail) je nově přehledný blok — název firmy v barvě značky nad jemnou oddělovací linkou a zápis v obchodním rejstříku jako drobný „fine print" pod tím. Sjednoceno mezi fakturou a výkazem práce; respektuje firemní barvu (branding).
- **Patička s autorstvím systému.** Pata dokladů i e-mailů nově uvádí „Používá fakturační systém **MyInvoice.cz od MyWebdesign.cz**" s odkazem na obě stránky.

## [4.29.0] — 2026-06-15

### Added

- **Kniha jízd nově umí elektromobily i plug-in hybridy.** U vozidla zvol druh paliva *Elektro* nebo *Hybrid* — nabíjení se eviduje v **kWh** místo litrů a spotřeba se počítá v **kWh/100 km**. U plug-in hybridu se palivo (litry) a elektřina (kWh) sledují **odděleně** a v ročním souhrnu se zobrazí dvě spotřeby vedle sebe (litry a kWh se nikdy nesčítají do jedné). Ruční záznam tankování má přepínač jednotky l/kWh, který se předvyplní podle vozidla. Beze změny databáze.
- **Rozpoznávání přijatých faktur nově zahrnuje nabíjení.** Příznak dodavatele „Benzínka" se rozšířil na **„Čerpací / nabíjecí stanice"** — faktury od provozovatelů nabíjení (ČEZ, PRE, E.ON, Ionity…) účtované v kWh se vytěží stejným tlačítkem *Načíst z faktur* jako tankování a uloží se jako nabíjení v kWh navázané na vozidlo. Roční souhrn a jeho XLSX/PDF export dostaly sloupce *Nabito (kWh)* a *kWh/100 km*; orientační odhad tachometru u nabíjení počítá s elektrickou spotřebou.

## [4.28.1] — 2026-06-15

### Fixed

- **Po aktualizaci přestalo fungovat menu / přechod na jiné stránky (Docker/Apache).** Prohlížeč si držel v cache starou `index.html`, která odkazovala na JS/CSS chunky se starým hashem — ty po updatu na novou verzi už na serveru neexistovaly, takže lazy-loaded stránky (klienti, zakázky, schvalování, daňová přiznání…) hlásily v konzoli „Failed to fetch dynamically imported module". `index.html` se nově servíruje s `Cache-Control: no-cache` (hashed assety zůstávají `immutable`), takže si prohlížeč po každém nasazení vyzvedne aktuální mapu chunků. IIS (`web.config`) to už řešil; chybělo to jen v `.htaccess`. (issue #140)
- **Health endpoint `/api/health` hlásil natvrdo verzi `0.1.0`.** Nově vrací skutečnou verzi aplikace ze souboru `VERSION`.

### Added

- **Generátor ukázkových dat doplňuje i knihu jízd.** Po čistém setupu (`api/bin/sample.php` / setup wizard) přibude jedno firemní auto, 15 jízd (služebních i soukromých, se spojitě navazujícím tachometrem) a 6 tankování — pro rychlé vyzkoušení modulu Kniha jízd.

## [4.28.0] — 2026-06-15

### Fixed

- **Přijaté služby ze zahraničí v režimu přenesení daňové povinnosti (reverse charge).** Služby od osob neusazených v tuzemsku (zahraniční dodavatelé bez české registrace k DPH) se importovaly chybně — buď jako „dovoz zboží" (ř. 7), nebo „bez nároku na odpočet", čímž úplně vypadly z přiznání i z vykázaného obratu. Nově se správně samovyměří jako přijetí služby podle § 9 odst. 1 ZDPH (ř. 12/13, resp. ř. 5/6 u EU) a uplatní zrcadlový nárok na odpočet (ř. 43). Daňový dopad je nulový (daň na výstupu = odpočet) — opravuje se zařazení na správné řádky výkazu a zahrnutí dokladů.
- **Kniha DPH: „Výsledná DPH" nově sedí s přiznáním.** Samovyměřená daň u reverse charge se ve výsledné bilanci chybně načítala na stranu odpočtu, čímž ji podhodnocovala. Nově se bilance sčítá podle čísla řádku DPHDP3 (samovyměření na výstupu, zrcadlový odpočet na vstupu), takže se reverse charge korektně vyruší.
- **Export do Pohody: ořez textu položky na 90 znaků.** Delší popis položky překračoval limit XSD (`maxLength`) a export neprošel validací.

### Added

- **Rozlišení přijaté služby z EU (ř. 5/6) a ze 3. země / od neusazené osoby (ř. 12/13).** Číselník DPH klasifikací dostal samostatný kód pro přijetí služby z jiného členského státu vedle služby ze 3. země. Importní auto-klasifikace nově u zahraničního dodavatele s nulovou sazbou defaultuje na *službu* (nejčastější případ – digitální předplatná), ne na dovoz zboží. (migrace 0111)
- **Nástroj pro opravu historických dokladů** `api/bin/backfill-foreign-reverse-charge.php` — dohledá zahraniční reverse-charge doklady naimportované se špatným zařazením (špatný řádek, chybějící odpočet, fiktivně vyčíslená česká DPH u dodavatele bez CZ registrace) a opraví je. Idempotentní; výchozí režim je náhled (dry-run), zápis až s `--apply`, rozsah lze omezit obdobím (`--from`/`--to`).

## [4.27.3] — 2026-06-14

### Fixed

- **Tankování z detailních faktur (Axigon) doplní litry.** U staršího „zhuštěného" formátu výpisu nešlo z druhé strany spolehlivě rozdělit množství, takže tankování se načetlo bez litrů. Nově se litry (a jednotková cena) doplní z položek faktury (první strana) — u jedné transakce přesně, u více se úhrn rozdělí poměrně dle částky. Datum tankování se bere z detailu, jinak z DUZP faktury.

### Added

- **Hromadné doplnění litrů u dříve načtených tankování** — tlačítko *Vytěžit historii* nově projede i už zpracované faktury, kterým chybí litry, a doplní je z položek. Každá faktura se zkusí nejvýše jednou (když litry nejsou ani v položkách, příště se přeskočí). *Rozpoznat znovu* doplní litry u konkrétní faktury.
- **Orientační stav tachometru z knihy jízd.** Když tankování nemá vlastní stav tachometru, odhadne se z jízd téhož vozu. Heuristika rozliší tankování na začátku vs. konci jízdy podle času (je-li k dispozici), jinak podle spotřeby. Odhad se zobrazí (placeholder v editaci) i v exportu (s ≈), neukládá se.

### Changed

- **Export tankování na šířku (A4) s bohatšími sloupci** — přibyly *Jednotková cena*, *Cena bez DPH* a *Tachometr* (XLSX i PDF).

## [4.27.2] — 2026-06-14

### Changed

- **Sjednocený toolbar v Knize jízd a Tankování.** Akční tlačítka (Export, Import, Nový záznam, Načíst z faktur) jsou nově uvnitř filtr-boxu zarovnaná doprava — filtry vlevo, akce vpravo v jednom ohraničeném panelu (na mobilu se zalomí pod sebe).

## [4.27.1] — 2026-06-14

### Fixed

- **Kniha jízd: role „jen čtení" viděla zápisová tlačítka.** V záložkách Automobily, Kniha jízd, Tankování a Kategorie cest se uživateli s rolí *readonly* zobrazovala tlačítka „Nový/Nové…", Import, Upravit, Smazat, „Načíst z faktur" i „Vytěžit historii", přestože je server (RBAC) stejně odmítal. Nově je UI skrývá — readonly má jen pohled a exporty (XLSX/PDF zůstávají dostupné).

### Added

- **Našeptávání míst „Odkud" / „Kam"** v novém záznamu jízdy — pole nabízejí dříve zadaná místa (stejně jako už účel cesty).
- **Souhrny: druhý graf „Kumulativní km YTD".** Vedle sloupcového grafu najetých km po měsících přibyl čárový graf s nabíhajícím součtem km od začátku roku, letošní vs. minulý rok (styl jako *Kumulativní zisk YTD* na CRM dashboardu).

### Changed

- **Sjednocení vzhledu filtrů.** Filtry v Knize jízd a Tankování jsou nově v ohraničeném boxu jako v ostatních přehledech (faktury).
- **Manuál: kapitoly Dokumenty a Kniha jízd dostaly v nadpisu pořadové číslo** (25., 26.) — sjednoceno se zbytkem manuálu.

## [4.27.0] — 2026-06-14

### Added

- **Kniha jízd (nový modul pod *Dokumenty*).** Kompletní daňová evidence vozidel, jízd a tankování na pěti záložkách:
  - **Automobily** — číselník vozidel (SPZ, značka/model, typ paliva, počáteční stav tachometru, výchozí vozidlo). Auto s navázanými jízdami nebo tankováním nelze smazat (chrání historii).
  - **Kniha jízd** — evidence jízd (datum, čas, odkud→kam, účel, tachometr od/do, ujeté km, kategorie). Tachometr zahájení se předvyplní posledním známým stavem, ujeté km a koncový stav se dopočítávají obousměrně, účel cesty našeptává dříve zadané hodnoty. **Import z CSV i XLSX** (mapování hlaviček CZ/EN, dopočet vzdálenosti, zakládání chybějících kategorií, dry-run náhled), **export do XLSX a PDF**.
  - **Tankování** — ruční záznam nebo **automatické vytěžení z přijatých faktur** od dodavatelů označených jako „benzínka". Detailní výpisy **Axigon** se rozpoznávají interním parserem (jednotlivá tankování, místo, množství, částka); na ostatní formáty a starší zhuštěné výpisy navazuje **AI fallback** (BYOK Anthropic klíč), s posledním záchytem v podobě souhrnného záznamu. Architektura parserů je rozšiřitelná — další tankovací společnost = nová třída, beze změny zbytku. Faktura se vytěžuje **jen jednou, ale i zpětně** (jednorázové vytěžení historie), idempotentně bez duplicit.
  - **Souhrny** — daňové a účetní přehledy za rok: poměr služebních/soukromých km (krácení), roční stav tachometru **počítaný z jízd**, spotřeba l/100 km, kontrola návaznosti tachometru (s detailem skoků), informativní srovnání s paušálem na dopravu a **graf najetých km po měsících proti minulému roku**. Export do XLSX a PDF.

  Tankování je čistě evidenční vrstva nad přijatou fakturou — náklad i DPH účtuje faktura, kniha jízd je jen rozpadá na jízdy a vozidla, takže nevstupuje do žádných statistik ani daňových výstupů dvakrát. Modul je dostupný i přes veřejné REST API (`/api/v1/logbook/*`). Účetní má plný přístup, role „jen čtení" vidí a exportuje.

## [4.26.2] — 2026-06-14

### Fixed

- **Cizoměnová přijatá faktura v Pohoda XML měla souhrn `homeCurrency` v měně dokladu místo v CZK.** Tuzemský souhrn má být vždy v korunách, ale u přijatých faktur (které nemají předpočítanou CZK rekapitulaci jako vydané) nesl částky v cizí měně označené jako CZK. Nově se přepočtou kurzem na CZK. Cizoměnový blok `foreignCurrency` (měna, kurz, celková částka) byl v pořádku už předtím. **Vydaných faktur se to netýkalo** — ty mají CZK rekapitulaci počítanou kurzem ČNB po jednotlivých sazbách.

## [4.26.1] — 2026-06-14

### Fixed

- **Dokončení opravy exportu přijatých faktur (návaznost na 4.26.0).** Rekapitulace DPH se mezi databází a exportérem klíčovala odlišně (`vat_rate`/`without_vat` u přijatých vs. `rate`/`base` u vydaných), takže souhrn v Pohoda XML (`homeCurrency`) i ISDOC (`TaxTotal` / `LegalMonetaryTotal`) u přijatých faktur vycházel **nulový** a klasifikace DPH spadla na `UNX` / `nonSubsume` (osvobozeno) místo skutečné sazby. Nově se rozpis přemapuje na kanonický tvar — souhrn i rekapitulace nesou správné základy, daň i sazby. **Vydaných faktur se tento problém netýkal** (jejich rozpis byl klíčovaný správně).
- **Členění DPH u přijatých faktur.** Pohoda export už přijaté faktuře nevnucuje výstupní (uskutečněné) členění DPH typu `UDA5` — to je nejen špatný směr (u přijaté faktury jde o vstupní DPH / nárok na odpočet), ale i kód specifický pro konkrétní instalaci Pohody. Posílá se jen typ plnění (`inland` / `nonSubsume`) a správné členění pro agendu *přijatá faktura* doplní Pohoda. U zálohové/proforma faktury se `classificationVAT` neposílá vůbec (schéma ho pro zálohy nepoužívá).
- **Evidenční číslo a variabilní symbol přijaté faktury v Pohodě.** Přijatá faktura už nevnucuje číslo dokladu dodavatele do naší číselné řady (`numberRequested` s `checkDuplicity` → import padal na duplicitě, navíc u nečíselného čísla šlo o špatný typ pole) — interní číslo přidělí Pohoda z agendy přijatých faktur. Variabilní symbol se navíc normalizuje na číselný tvar (max 10 číslic, stejně jako pro banku a QR), aby prošel platebním stykem. Doplněn integrační test exportu přijatých faktur nad reálnými daty (XSD validace + nenulová rekapitulace).

## [4.26.0] — 2026-06-14

### Fixed

- **Export přijatých faktur do Pohoda XML byl nevalidní vůči oficiálnímu schématu Stormware a konektory ho odmítaly.** Přijatá faktura se exportovala jako *vydaná* — `invoiceType` byl `issuedInvoice` místo `receivedInvoice` a v `partnerIdentity` byl uveden příjemce (vaše firma) místo dodavatele. Hromadný export navíc obaloval každou fakturu do vlastního `<dataPack>`, takže uvnitř `<dataPackItem>` vznikal zanořený `<dataPack>`. Nově se přijaté faktury exportují korektně (`receivedInvoice` / `receivedAdvanceInvoice` / `receivedCreditNotice`, partner = dodavatel) a celé období je v jednom plochém `<dataPack>` s jednou položkou na fakturu.
- **Rekapitulace DPH a souhrnné částky v Pohoda i ISDOC exportu přijatých faktur byly nulové.** Adaptér přijaté faktury nepředával rozpis DPH ani součty, takže `invoiceSummary` (Pohoda) i `TaxTotal` / `LegalMonetaryTotal` (ISDOC) vycházely prázdné. Nově nesou skutečné hodnoty.
- **Pohoda export (vydaných i přijatých faktur) neprocházel XSD validací kvůli měnovým blokům souhrnu.** `homeCurrency` obsahoval nepovolený `priceSum` a zaokrouhlení `round` jako prostou hodnotu (schéma vyžaduje strukturu `priceRound`); `foreignCurrency` nesl per-sazbové mezisoučty, které do něj nepatří. Opraveno. Daňový doklad k přijaté platbě se navíc už neexportuje s neexistujícím typem `issuedTaxDocument`, ale jako běžná vydaná faktura (`issuedInvoice`).

### Added

- **Validace exportu proti oficiálním schématům.** Do repozitáře přibyla schémata Pohoda (`api/xsd/pohoda/`) a sada testů, které generované XML pro vydané i přijaté faktury validují proti `invoice.xsd` (Pohoda) a `isdoc-invoice-6.0.2.xsd` (ISDOC).

## [4.25.0] — 2026-06-12

### Security

- **API tokeny (PAT) jsou nově omezené jen na veřejné API.** Osobní přístupový token dosáhne pouze na dokumentovaný veřejný subset `/api/v1/*` (faktury, klienti, přijaté faktury, dokumenty, reporty, číselníky …) — pokus o interní nebo administrátorské endpointy (`/api/admin/*`, správa uživatelů a tokenů, citlivá nastavení podpisů / brandingu / IMAP) vrátí `403 token_endpoint_forbidden`, a to i u tokenu vytvořeného administrátorem. Případně uniklý token tak nedává přístup k celému účtu, ale jen ke čtení/zápisu veřejných dat v rozsahu svého scope. Výchozí scope nově vytvořeného tokenu je navíc `read` (dříve `read_write`) — princip nejmenšího oprávnění.
- **Zpřísnění rolí (RBAC) na čtení.** Dosud procházel přes middleware každý požadavek GET pro všechny role a ochranu administrátorských endpointů zajišťovala pouze kontrola uvnitř konkrétní akce. Nově middleware povoluje GET jen na vyjmenované datové a exportní skupiny a administrátorské endpointy i citlivá nastavení blokuje už na vstupu (obrana do hloubky proti případné budoucí chybě v jednotlivé akci).
- **Veřejné schvalování výkazu je odolné proti souběhu a zahlcení.** Rozhodnutí (schválit / zamítnout) je nově atomické — dva souběžné požadavky se stejným odkazem už nemohou fakturu vystavit a odeslat dvakrát. Veřejné schvalovací endpointy mají navíc limit počtu požadavků na IP adresu.
- **Drobná zpevnění (defense-in-depth):** stahování příloh a archivních PDF posílá `X-Content-Type-Options: nosniff` a restriktivní CSP; diagnostika v `/api/health` (včetně upozornění na klíč šifrování) je nově jen pro přihlášené; redakce tajemství v nastavení dodavatele pokrývá i případné budoucí sloupce; ošetření neznámé velikosti při nahrávání bankovních výpisů; escapování zástupných znaků (`%`, `_`) ve fulltextovém hledání dokumentů; `LIBXML_NONET` při parsování odpovědi z registru plátců DPH; ověření vlastnictví cílové entity při propojování dokumentu.

### Changed

- **Účetní (role „účetní") může nově plně spravovat přijaté faktury** přes API (vytváření, úpravy, položky, PDF, přechody stavu, párování záloh) — dosud kvůli chybějícímu pravidlu v RBAC tyto operace propadaly do administrátorského omezení.

## [4.24.0] — 2026-06-12

### Added

- **Systémový parser e-mailových avíz Fio banky (#58).** Avíza *„Fio banka - prijem/vydaj na konte"* od `automat@fio.cz` se nově zpracovávají automaticky jako u ostatních podporovaných bank — bez jakékoli konfigurace, stačí v *Nastavení → Bankovní avíza* namapovat účet. Parser řeší specifika Fio avíz: tělo neobsahuje datum platby (bere se z hlavičky e-mailu) ani měnu (výchozí CZK; uvedený kód měny se respektuje), směr platby určuje text *„Příjem/Výdaj na kontě"* (výdaje se evidují se záporným znaménkem, takže se nepárují proti pohledávkám) a číslo účtu bez kódu banky se doplní o `/2010`. Vytěžuje se variabilní symbol, protiúčet, konstantní symbol i zpráva příjemci; odesílatel se ověřuje proti doméně `fio.cz` (ochrana proti spoofingu subdoménou).

## [4.23.0] — 2026-06-12

### Added

- **Částečné úhrady faktur a evidence plateb (#89).** Každá vydaná i zálohová faktura může mít **více evidovaných plateb** (splátky, více převodů, e-mailová avíza). V detailu je nový box **Platby** (datum, částka, zdroj, reference, mazání) a v liště akcí tlačítko **Částečná úhrada**; stávající **Označit zaplacené** zůstává jako zkratka „platba na celý zbytek" (plná zpětná kompatibilita, vč. API `mark-paid`). Stav úhrady ukazují nové badge **Částečně uhrazeno** a **Přeplaceno**; částečně uhrazená faktura zůstává pohledávkou se sníženým zůstatkem — přehledy (po splatnosti, aging, cashflow, CRM), upomínky, e-maily, **QR platba i PDF** (nový řádek *Uhrazeno / Zbývá uhradit*) počítají vždy jen se zbývající částkou. Bankovní párování (výpisy i e-mailová avíza) nově eviduje **N:1** — částečná platba se shodným variabilním symbolem se zaeviduje automaticky a další převody se přičítají; doplatek zálohy, ke které už existuje finální doklad, se správně připíše finálu. Veřejné REST API: `GET/POST /api/v1/invoices/{id}/payments`, `DELETE …/payments/{id}`.
- **Daňový doklad k přijaté platbě u zálohových faktur (§ 28 odst. 2 ZDPH).** K (částečné) platbě zálohové faktury plátce DPH vystaví **daňový doklad k přijaté platbě** s DUZP = den přijetí úplaty — automaticky jako koncept při bankovním spárování, nebo na klik (modal Částečné úhrady / box Platby). DPH se počítá **shora koeficientem (§ 37)** a platba se rozdělí mezi sazby DPH zálohy poměrně; doklad se čísluje v řadě faktur a do **DPH přiznání, kontrolního hlášení i Knihy DPH** vstupuje v měsíci platby. Finální vyúčtování pak ke zdaněným platbám generuje **záporné odpočtové řádky (§ 37a)** — daní se jen zbytek, nikdy nic dvakrát (hlídáno oboustrannými pojistkami: daňový doklad nelze vystavit k záloze s existujícím finálem a ruční párování zálohy s vystavenými doklady k platbě je blokované). Vyúčtovat lze i částečně uhrazenou zálohu. U neplátce DPH a přenesené daňové povinnosti se doklad nevystavuje (u RC se záloha nedaní). Export: ISDOC `DocumentType 5` (daňový zálohový list), Pohoda `issuedTaxDocument`. Daňová správnost je pokrytá novým integračním testem (DPH/KH/Kniha napříč obdobími: součet daňového dokladu a finálu = přesně původní základy a daně).

### Fixed

- **CRM přehledy pohledávek (aging, týdenní cashflow) nadhodnocovaly dluh** — sčítaly celkovou částku dokladu místo zbývajícího dluhu a počítaly i finální doklady plně kryté zálohou (částka k úhradě 0). Nově sčítají skutečný zůstatek.
- **Finální doklad vytvořený bankovním spárováním zálohy má DUZP = datum platby z výpisu** (dřív datum vytvoření konceptu) a výše odpočtu zálohy při ručním párování vychází ze **skutečně přijatých plateb**, ne z celkové částky zálohy.
- **Popup editoru výkazu práce je použitelný na mobilu** — položky se na malých displejích zobrazují jako karty (jako položky faktury v detailu) s číselnou klávesnicí pro hodiny/sazbu; tlačítka „Přidat řádek" sjednocena se stylem editoru faktury.

## [4.22.0] — 2026-06-11

### Added

- **Podpora formátu ISDOCX (ISDOC Package) ve všech importech (#136).** ISDOCX je ZIP balíček, do kterého řada účetních systémů zabaluje strukturovaný **ISDOC** i **čitelné PDF** faktury najednou. Dosud ho importy neuměly rozbalit a tiše spadly na AI extrakci nebo přeskočení. Nově ho přijmou **všechny** cesty: hromadný import (*Importy → Přijaté i Vystavené*), AI extrakce (*Externí integrace → AI*), nahrání faktury přímo v editoru přijaté faktury (drag & drop) i automatický **sken inbox** adresáře. Z balíčku se ISDOC vytáhne **deterministicky (zdarma, bez AI)** a vytvoří draft faktury, čitelné PDF se uloží pro náhled. Hlavní ISDOC se v balíčku určí podle `manifest.xml` (s fallbackem na `.isdoc` v kořeni archivu). Funguje i `.isdocx` **jako příloha uvnitř PDF/A-3**. Importy nově akceptují příponu `.isdocx` (uživatelé s vlastním `purchase_invoice.allowed_exts` v cfg.php si ji do seznamu doplní).

### Fixed

- **AI špatně rozpoznávala datumy na přijatých fakturách (zaměňovala datum vystavení, DUZP a splatnost).** AI extrakce neměla u datových polí v promptu žádné vodítko, takže role datumů odhadovala podle pozice na dokladu místo podle popisku — na produkci dala DUZP na datum splatnosti, jindy prohodila vystavení a splatnost. **DUZP (datum uskutečnění zdanitelného plnění) je přitom daňově zásadní** — rozhoduje o zařazení do období DPH. Nově prompt mapuje konkrétní české i slovenské popisky („Datum vystavení", „Datum uskut. zdaň. plnění" / „Datum zdanitelného plnění", „Datum splatnosti" …) na správná pole a uplatní logickou kontrolu (splatnost nikdy nepředchází vystavení). Navíc obranný mechanismus na straně serveru automaticky opraví prohozené datum vystavení ↔ splatnost.
- **Po administrátorské opravě vystavené faktury (force-edit) zůstávalo přegenerované PDF se starými údaji stran (#135).** Force-edit uložil nová data faktury, ale JSON snapshoty stran (odběratel / dodavatel / banka) ponechal beze změny — a protože se u vystavených faktur PDF vykresluje právě z těchto snapshotů, oprava (např. adresy nebo IČO odběratele) se do nově vygenerovaného PDF nepromítla (ač to UI uživateli slibovalo). Nově se snapshoty při force-editu přepíšou z aktuálních dat; původní PDF zůstává v archivu. Historie faktury navíc u opravy uvádí, která konkrétní pole se změnila.

## [4.21.1] — 2026-06-10

### Fixed

- **Variabilní symbol s pomlčkou (z čísla dokladu jako `2026-00001`) neprošel přes banku a kazil QR i párování plateb (#58).** Když dokladová řada obsahovala nečíselný znak (pomlčku/lomítko, např. řada `{YYYY}-{CCCCC}`), ukládal se takový variabilní symbol i do QR platby (SPAYD) — tu banka odmítá, protože VS musí být jen číslice — a automatické párování příchozích plateb (z výpisů i e-mailových avíz) ho nikdy nespárovalo, protože banka přenese jen číslice (`202600001`). Nově se VS pro platbu i QR vždy normalizuje na čistě číselný (max 10 znaků) a párování porovnává variabilní symbol **číselně** (ignoruje pomlčky, lomítka i vodicí nuly) na straně vydaných i přijatých faktur, takže se spárují i doklady s pomlčkou v čísle. V tištěné faktuře se v řádku *Var. symbol* zobrazuje platný číselný VS (velký titulek dokladu zůstává s pomlčkou).

## [4.21.0] — 2026-06-10

### Added

- **„Zaplatit pomocí QR" u přijatých faktur.** V detailu nezaplacené přijaté faktury je nové tlačítko **Zaplatit pomocí QR**, které zobrazí QR platbu dodavateli pro naskenování v mobilním bankovnictví — CZK doklady ve formátu **QR Platba (SPAYD)**, cizoměnové jako **SEPA (EPC)**. Platební účet dodavatele se získává v pořadí: z **ISDOC** přílohy PDF → jednorázové **AI rozpoznání** z faktury (krátký dotaz na Anthropic Claude jen na účet/IBAN/variabilní symbol, spustí se automaticky při otevření okna a proběhne nejvýše jednou) → **ruční** zadání → záložní **obrázek QR vytažený z PDF** (čtvercový černobílý obrázek se zobrazí k naskenování i bez rozpoznání účtu). Známý účet se zobrazí i v **detailu** faktury (box vedle měny) a je editovatelný v **editoru** faktury (box *Platební účet dodavatele*) i přímo v okně QR. AI extrakce přijatých faktur nově platební účet rovnou ukládá.

### Fixed

- **Zahraniční DIČ s písmenem (např. nizozemské `NL123456789B01`) nešlo ověřit přes VIES.** Validace povolovala po prefixu země jen číslice (`/^[A-Z]{2}\d{4,12}$/`), takže DIČ s písmenem padalo na „DIČ musí mít prefix země a 4-12 číslic". Týkalo se i Rakouska (`ATU…`), Španělska, Francie a Irska. Nově se po prefixu země povolí 2-12 alfanumerických znaků, takže projdou všechny formáty DIČ ze systému VIES.

## [4.20.1] — 2026-06-09

### Fixed

- **Skrytí akcí „Spáruj platby z banky" / „Zkontroluj koncepty přijatých faktur" / „Souhrnné hlášení" hlásilo `Invalid item_type`.** Nové typy akcí (z 4.20.0) chyběly v allowlistu i ve snapshotu pro režim „skrýt pro historická data" — doplněny, skrývání teď funguje pro všechny akce.
- **Počítadlo (badge) u akce „Pošli upomínky" nesedělo s cílovým seznamem.** Počítalo jen ostré faktury, zatímco seznam `/invoices?overdue=1` od 4.20.0 zobrazuje i nezaplacené nespárované proformy. Dotaz akce nyní zrcadlí seznam (vč. proforem a vyřazení finálních dokladů k zaplacené proformě), takže číslo v odznaku odpovídá počtu v seznamu.

## [4.20.0] — 2026-06-09

### Added

- **„Akce pro tebe" jsou nově na Přehledu (Dashboard) — jako první sekce.** Denní TODO seznam se přesunul z CRM dashboardu na úvodní Přehled, kde ho uvidíš hned po přihlášení. Logika (skrytí na den/týden/navždy/pro historická data i obnovení) zůstává beze změny; widget je vytažen do samostatné komponenty `ActionItemsWidget`.
- **Nová sekce „Výkazy práce" na Přehledu.** Pokud máš rozpracované (koncept) vydané faktury, zobrazí se jako karty vedle sebe s **firmou** a **zakázkou**. Každá karta má tlačítko **Upravit** (otevře editor faktury) a **Výkaz** (otevře přímo popup výkazu práce — stejný jako v seznamu vydaných faktur), takže rozdělanou práci doplníš na jedno kliknutí.
- **Tři nové akce v „Akce pro tebe":**
  - **Spáruj platby z banky** → nespárované příchozí platby z bankovních výpisů (za posledních 90 dní) čekající na přiřazení k faktuře.
  - **Zkontroluj koncepty přijatých faktur** → naimportované přijaté faktury (API / AI / PDF) zůstávají ve stavu koncept; připomene jejich revizi a zaúčtování.
  - **Souhrnné hlášení za uplynulý měsíc** → upozornění na termín podání SH (25. dne), ale jen když za uplynulý měsíc skutečně existují EU plnění (jinak se SH nepodává a akce se nezobrazí).

### Fixed

- **Filtr „Nezaplacené" (`/invoices?unpaid=1`) nezobrazoval nezaplacené zálohové (proforma) faktury.** Filtr je vylučoval úplně; nově ukazuje i nezaplacené **nespárované** proformy (zálohovky bez navázaného finálního dokladu) — stejná pohledávková logika jako na dashboardu. Sjednoceno i s filtrem „Po splatnosti".
- **Akce „Pošli upomínky" vedla na nefunkční odkaz.** Mířila na `/invoices?status=overdue` (neplatná hodnota stavu → prázdný seznam); nově správně na `/invoices?overdue=1`.
- **Akce „Kontaktuj neaktivní klienty" nikam nevedla.** Místo obecného `/crm` teď skočí přímo na sekci „Riziko odchodu klientů" (kotva `#churn-risk`).

## [4.19.7] — 2026-06-08

### Fixed

- **Děkovný e-mail za úhradu se neodesílal při automatickém spárování z banky ([#127](https://github.com/radekhulan/myinvoice/issues/127), díky @jssystemcz).** Při zapnutém „Posílat poděkování za úhradu → Automaticky při spárování platby z banky" se po zpracování e-mailového bankovního avíza faktura sice správně označila jako zaplacená, ale děkovný e-mail se neodeslal (a v e-mail logu po něm nebyla stopa). Poděkování posílala jen ruční cesta (označení jako uhrazené) a ruční spárování v UI; **automatické** cesty (e-mailové avízo, import GPC výpisu, cron) jdou přes `StatementMatcher`, který fakturu označoval jako paid napřímo a mailer nevolal. Nově se poděkování odešle ze společného místa všech automatických cest (trigger `bank_match`) — respektuje per-dodavatelský přepínač i ochranu proti dvojímu odeslání a případné selhání e-mailu nerozbije spárování.
- **Oprava driftnutého číselníku DPH klasifikací (migrace 0106).** Na instalacích, kde globální systémový číselník (`vat_classifications`) mezitím odešel od stavu daňových migrací (kopie starší DB, re-seed), zůstaly chybné hodnoty: osvobozený tuzemský prodej (kód 3) korumpoval ř. 3 přiznání (pořízení zboží z JČS), přijaté plnění bez nároku na odpočet (kód 42) padalo do KH B.2/B.3 a chyběl kód `25s` (tuzemský režim přenesení daňové povinnosti – dodavatel → ř. 25). Idempotentní opravná migrace re-asertuje kanonický stav RC příznaků, samovyměření (ř. 43) i zařazení do řádků pro systémové kódy. Sahá výhradně na systémové řádky (uživatelské per-dodavatelské klasifikace zůstávají netknuté); na aktuální DB je bez efektu.

### Internal

- Úklid testů pro PHP 8.5: odstraněna no-op volání `curl_close()` a `ReflectionProperty::setAccessible()`; mocky používané jen jako stub přepsány na `createStub()`. Testová sada je bez deprecations a PHPUnit notices.

## [4.19.6] — 2026-06-08

### Added

- **Export vydaných faktur do Stereo XML ([#126](https://github.com/radekhulan/myinvoice/pull/126), díky @blondak).** Administrace exportů (Daně → Export vydaných faktur) nově nabízí formát **Stereo XML** — DocumentPack XML pro import vydaných faktur do účetního systému **Kastner Stereo** (přes „Import faktury (XML)"). Funguje za měsíc i celé čtvrtletí, stejně jako ostatní formáty. Mapování DPH klasifikace na Stereo `TypeOfVAT` řeší `StereoVatTypeResolver` (zdroj pravdy = klasifikace řádků dokladu). Součástí je i sdílený `InvoiceExportDataResolver` pro dohledání dodavatele/klienta/banky (snapshot vyhrává nad live daty) — refaktor sjednocuje logiku dříve duplikovanou v ISDOC a Pohoda exportérech, beze změny jejich chování.
- **SMTP log analýza: klikací souhrnné karty.** Karty **Doručeno / Odloženo / Odmítnuto** nad tabulkou fungují jako rychlý přepínač filtru stavu dole (klik zapne/vypne, aktivní karta se zvýrazní). Karta „Odmítnuto" sčítá `rejected` i `error`, proto filtruje složeně přes obě hodnoty (volba „Odmítnuté + chyby" je i v rozbalovacím filtru).

### Changed

- **Stránka „Export vydaných faktur" je širší.** Po přidání čtvrtého formátu (Stereo) má výběr formátů (PDF / ISDOC / Pohoda / Stereo) v řadě víc místa.

## [4.19.5] — 2026-06-08

### Fixed

- **Oprava diakritiky ve jméně autora** v modálu „Chcete jinou funkci?" — „Radek Hulan" → „Radek Hulán" (cs i en).

## [4.19.4] — 2026-06-08

### Added

- **Patička: „Podpořte autora" a „Chcete jinou funkci?".** Odkazy v patičce aplikace nově otevírají přehledná modální okna. **Podpora autora** zobrazí bankovní spojení pro dar (účet u Partners Banky, IBAN, BIC/SWIFT) a **QR kód** k platbě (roztažený na plnou šířku se zachovaným poměrem stran, jemně zesvětlený). **Chcete jinou funkci?** představí, kdo MyInvoice vyvíjí (MyWebdesign.cz s.r.o. — 20 let na trhu, seniorní vývoj akcelerovaný AI, reference jako Prazdroj, ZOOT či Syntex) a nabídne poptávku na vývoj vlastních funkcí, reportů či napojení s tlačítkem vedoucím na kontaktní formulář. Oba dialogy jsou plně lokalizované (cs/en).

## [4.19.3] — 2026-06-08

### Added

- **Hromadný export umí celé čtvrtletí.** „Hromadný export" (dříve „Měsíční export", Daně → Hromadný export) má nově přepínač **Měsíc / Čtvrtletí** — kromě jednoho měsíce lze do jednoho ZIPu sbalit doklady za celý kvartál (`Q1`–`Q4`). Zařazení dokladů do období zůstává daňově korektní a shodné s výkazy DPH (vystavené dle DUZP, přijaté dle pozdějšího z DUZP/vystavení, výpisy dle data výpisu). **Kniha DPH** se u čtvrtletí přiloží jako **tři měsíční PDF** (jeden za každý měsíc kvartálu). Stejný kvartální režim už dříve nabídl i export vydaných a přijatých faktur.

### Changed

- **„Měsíční export" přejmenován na „Hromadný export".** Název v menu, na stránce i v ZIP balíčku (README) lépe vystihuje, že jde o kompletní balíček dokladů za zvolené období — měsíc i čtvrtletí. Manuál § 32.
- **Export vydaných faktur má stejný vzhled jako Export přijatých.** Stránka „Export vydaných faktur" byla sjednocena s „Exportem přijatých faktur" — širší layout, výběr formátu v přehledné trojici karet s barevnými ikonami a konzistentní rozložení polí období / filtru / typu.
- **Exporty defaultně nabízejí předchozí měsíc.** Všechny exporty (vydané, přijaté i hromadný) se otevírají s předvyplněným **minulým měsícem** místo rozpracovaného aktuálního — odpovídá tomu, že se export typicky dělá po uzávěrce právě skončeného měsíce.

## [4.19.2] — 2026-06-08

### Fixed

- **CRM dashboard počítá tržby, náklady a zisk bez DPH (pro plátce).** Dosud CRM (KPI karty, srovnání období, grafy a tabulky zisku, top klienti/dodavatelé, rozpady kategorií) sčítal částky **včetně DPH**, takže se ziskovost a meziroční srovnání zkreslovaly o vracenou daň. Nově se — shodně se stránkami Tržby a Náklady — u plátce DPH počítá **bez DPH** (u neplátce včetně). Peněžní toky („Co přiteče/odteče", pohledávky a závazky po splatnosti) zůstávají správně **včetně DPH**. Stejná oprava i na Dashboardu (dlaždice „Náklady {rok}" a trend nákladů za 12 měsíců nově bez DPH).
- **CRM čísla nově sedí s Tržbami/Náklady i pro starší období.** CRM přestal číst 13měsíční pre-agregovanou cache `crm_monthly_summary` (kvůli které byl „Loňský rok" a meziroční srovnání podhodnocené) a počítá **živě z faktur** stejnou metodikou jako stránky Tržby/Náklady — vč. zařazení podle DUZP (fallback datum vystavení) a správného vyřazení spárovaných/zaplacených záloh z nákladů. „Loňský rok" v CRM tak odpovídá „Obratu" ve Tržbách.

## [4.19.1] — 2026-06-08

### Added

- **CRM dashboard — kompletní přehled zisku.** Headline karty (Tržby / Náklady / Zisk) nově kromě hodnoty za tento měsíc ukazují i **posledních 12 měsíců** a **YTD**, vždy s **meziroční změnou v %** (12 měsíců vs. předchozích 12, YTD vs. stejné období loni; u nákladů je růst červený, u tržeb a zisku zelený). Karta Zisk navíc zobrazuje marži YTD. Přibyla **srovnávací tabulka** pěti období (tento měsíc / minulý měsíc / 12 měsíců / YTD / loňský rok) s tržbami, náklady, ziskem a marží, dvojice grafů **Zisk za posledních 12 měsíců** a **Kumulativní zisk YTD vs. loni** (stejné jako v Tržbách/Nákladech, jen pro zisk — povolují ztrátu pod nulu) a **výsledovkové tabulky Zisk po rocích / po měsících** (tržby, zisk, marže). Tyto přehledy jsou nezávislé na přepínači analytického období. Manuál § 20.
- **Proklik z tabulek CRM do faktur.** Řádky tabulek vedou na příslušný seznam s filtrem v URL: Náklady po rocích/měsících → přijaté faktury (rok, resp. rok+měsíc), Zisk po rocích/měsících → vydané faktury, Srovnání období → tržba na vydané, náklad na přijaté faktury za dané období.

## [4.19.0] — 2026-06-08

### Added

- **Poznámky nad/pod položkami u pravidelné fakturace ([#123](https://github.com/radekhulan/myinvoice/issues/123), díky @jssystemcz za podnět).** Šablona pravidelné fakturace teď nabízí stejná dvě pole jako běžná faktura — **Poznámka nad položkami** a **Poznámka pod položkami** (sekce „Poznámky" v editoru šablony, stejná editace jako u vydané faktury). Text se přenáší na každou vygenerovanou fakturu a tiskne se nad, resp. pod tabulkou položek — ideální na opakované informace typu období poskytované služby, podmínky pronájmu nebo doplňující sdělení pro zákazníka. Obě pole podporují **placeholdery období** (`{YYYY}`, `{MM}`, `{DATE±…}`, `{BOM}`/`{EOM}` …) — vyhodnotí se při každém generování vůči DUZP (u proformy vůči datu vystavení), takže např. „Vyúčtování za období {BOM} – {EOM}" se na faktuře propíše jako konkrétní rozsah měsíce. Manuál § 12.2.2a.

## [4.18.6] — 2026-06-07

### Changed

- **Manuál přeorganizovaný podle menu aplikace.** Pořadí kapitol teď kopíruje levé menu (Instalace a start · Prodej · Nákup · Finance · Dokumenty · Daně · Systém · Reference). Rozsáhlejší témata se rozpadla na samostatné kapitoly, aby se v nich dalo lépe orientovat: **CRM** → CRM dashboard / Tržby / Náklady; **Výkazy DPH** → Výkazy DPH (DPHDP3 + KH) / Kniha DPH / Souhrnné hlášení / Měsíční export; **Instalace** → Quickstart / Docker / Nativní / Po instalaci a CLI (Quickstart nově nabízí i instalační příkazy pro Git a Docker — winget / Homebrew); **Přijaté faktury** → odštěpen Export přijatých faktur a AI extrakce; z kapitoly **Banka** se vyčlenily **Bankovní účty a e-mailová avíza (IMAP)** do sekce Systém. Daňový průvodce (Fakturujeme) se přesunul na začátek sekce Daně. Doplněny chybějící popisy **Tržeb** a **Nákladů** (KPI, grafy, predikce).
- **Sidebar manuálu.** Nahoře přibyla položka **Homepage** (rozcestník), názvy kapitol jsou kratší a přehlednější a barvy skupin nově odpovídají barvám sekcí v menu aplikace (Prodej = fialová, Nákup = jantarová, Finance = zelená, Daně = červená…); Systém a Reference dostaly vlastní odstíny (indigo / kámen).
- **Callouty v manuálu.** Poznámkové bloky `> [!TIP] / [!NOTE] / [!IMPORTANT] / [!WARNING] / [!CAUTION]` se vykreslují jako barevné boxy s ikonou místo holého textu `[!TIP]`.

## [4.18.5] — 2026-06-07

### Changed

- **Nový vzhled HTML manuálu.** Manuál na `/manual` přebírá design language aplikace: stejné barevné tokeny (indigo brand, teplé neutrály), topbar s přepínačem **Světlý / Tmavý / Podle systému** (volba sdílená s aplikací — manuál se otevře ve stejném režimu), postranní menu s barevnými pilulkami skupin jako menu aplikace, čísla kapitol, úvodní rozcestník s odkazy (MyInvoice.cz · GitHub · GHCR Docker · MyWebdesign.cz) a kartami kapitol, stránkování **Předchozí / Další** na konci kapitol, vyhledávání a mobilní drawer ve stylu aplikace. Vzhled je v samostatném `manual/manual.css` — sdílí ho i prezentační web myinvoice.cz (kopíruje `rebuild-manual.ps1`). Světlé screenshoty se v tmavém režimu automaticky převádí do tmavé podoby (detekce jasu + inverze s přesným namapováním pozadí na barvu formulářů aplikace); tmavých screenshotů se úprava nedotkne.
- **Manuál popisuje jen aktuální stav.** Z kapitol zmizely historické poznámky „nové ve verzi X" a zmínky o integraci forku — historie patří do changelogu. Audit proti changelogu doplnil chybějící popisy: děkovný e-mail za úhradu (§ 18.5.5), kompletní seznam e-mailových šablon (§ 19.4), aktuální podoba rychlého vytváření v horní liště (§ 5.6) a ukotvení relativních cest v `cfg.php` (§ 2.4.1).

### Fixed

- **Daňové konstanty pro rok mimo tabulku.** Konstanty (paušály, slevy, sazby pro daňový optimalizátor, DPFO a další výpočty) padaly pro neznámý rok na natvrdo zadrátovaný rok 2026. Nově neznámý rok spadne na **nejbližší předchozí známý** — budoucí roky tak dostanou poslední ověřené hodnoty i poté, co do tabulky přibudou novější ročníky; rok před začátkem tabulky dostane nejstarší známý. Stejné chování má i DB vrstva s per-rok přepisy.
- **Sjednocená tlačítka vytvoření** — „Nový účet" (Systém → Bankovní účty, sekce měnových účtů) a „Nový podpisový profil" (Elektronické podpisy) měly outline styl; nově plné primární tlačítko s „+" jako ostatní akce vytvoření.

## [4.18.4] — 2026-06-06

### Added

- **Volitelné šifrování ZIP záloh heslem.** Nový klíč `cron.backup.password` v `cfg.php`: pokud je nastavený, všechny tři typy záloh (`cron-backup` = DB dump, `cron-backup-pdf` = PDF dokladů, `cron-backup-documents` = sekce Dokumenty) se šifrují **AES-256** — chrání zálohy at-rest i při kopírování na vzdálené úložiště. Prázdné heslo (default) = beze změny chování. Rozbalení šifrovaného archivu vyžaduje 7-Zip / WinRAR / `unzip -P` (vestavěný Průzkumník Windows AES-256 neumí); šifruje se obsah souborů, názvy uvnitř archivu zůstávají čitelné. Pokud je heslo nastavené a PHP ext-zip AES nepodporuje (libzip < 1.2), záloha se **záměrně nevytvoří** a úloha skončí chybou viditelnou v Plánovaných úlohách — tichá nešifrovaná záloha by byla horší než chybějící. Stav šifrování je v JSON reportu běhu (`cron_runs`). Manuál § 2.4.1 a § 27.

## [4.18.3] — 2026-06-06

### Fixed

- **Relativní cesty v cfg.php se ukotvují k rootu aplikace.** `cfg.sample.php` měl u `cron.backup.output_dir` relativní hodnotu `'storage/backup'`, kterou backup crony braly doslovně — cesta se pak resolvovala proti pracovnímu adresáři procesu, který je pod Task Schedulerem/cronem jinde než root aplikace, takže zálohy končily v cizím adresáři (typicky pod adresářem cron runneru). Nově `Config::load()` každou relativní cestu ve známých path klíčích (`storage.*`, `cron.backup.output_dir`, `logging.path`, `purchase_invoice.archive_storage`/`inbox_dir`, `invoice.import_archive_storage`, `smtp.dkim.*`) ukotví k rootu aplikace; absolutní cesty (vč. Windows `C:\` a UNC `\\server`) i `MYINVOICE_DATA_DIR` override zůstávají beze změny. Sample cfg má nově `__DIR__ . '/storage/backup'` konzistentně s ostatními cestami.

## [4.18.2] — 2026-06-06

### Added

- **Národní daňové číslo klienta — SK DIČ / IČ DPH, Steuernummer, NIP, Adószám ([#120](https://github.com/radekhulan/myinvoice/issues/120), díky @mikolashodan za podnět).** Slovenské subjekty mají tři čísla — IČO, **DIČ** (bez prefixu, má ho i neplátce DPH) a **IČ DPH** (`SK` + číslo, jen registrovaní k DPH) — a tamní praxe je vyžaduje na faktuře všechna. V kartě klienta se po výběru státu Slovensko pole DIČ přejmenuje na **IČ DPH** a přibude samostatné pole **DIČ** (tlačítko VIES ho předvyplní automaticky oseknutím prefixu). Faktura pak tiskne `IČO → DIČ → IČ DPH`; u neplátce jen IČO + DIČ. Řešeno obecně pro země s národním daňovým číslem vedle VAT ID: DE/AT **Steuernummer**, PL **NIP**, HU **Adószám** — pole se zobrazí s nativním labelem a stejně se tiskne (vystavené i přijaté doklady). Starší faktury SK klientů doplní DIČ automaticky odvozením z IČ DPH. Pro fakturu do jiné země EU je legislativně povinné jen VAT ID (čl. 226 směrnice 2006/112/ES) — národní čísla jsou lokální konvence. Migrace 0105 (`clients.tax_number`), API `/api/v1/clients` přijímá a vrací `tax_number`. Manuál § 7.2.1a a § 12.2.

### Fixed

- **Detaily plátce DPH u zahraničního klienta ověřují přes VIES ([#120](https://github.com/radekhulan/myinvoice/issues/120)).** Tlačítko **Detaily plátce DPH** posílalo každé DIČ do českého registru plátců (CRPDPH/MFČR), takže u slovenského či jiného zahraničního DIČ vždy nepravdivě hlásilo „Subjekt není evidován v registru plátců DPH". DIČ s jiným prefixem než CZ se nově ověřuje přes evropský **VIES** — panel zobrazí stav registrace k DPH, ověřené VAT ID, název a adresu subjektu. Česká DIČ jdou dál do CRPDPH (zveřejněné účty + nespolehlivý plátce).

- **Chyby ARES/VIES lookupu se zobrazují u příslušného pole ([#120](https://github.com/radekhulan/myinvoice/issues/120)).** Hlášky „Subjekt nebyl v ARES nalezen" a „VIES lookup selhal" ve formuláři klienta padaly do obecného chybového boxu až dole u tlačítka Uložit — u delšího formuláře mimo viditelnou část obrazovky, takže uživatel nevěděl, proč se nic nenačetlo. Nově se chyba ukazuje červeně přímo pod polem IČO resp. DIČ, vedle stávajících upozornění na duplicitu.

## [4.18.1] — 2026-06-06

### Fixed

- **API import přebírá platební stav z Fakturoidu a iDokladu ([#121](https://github.com/radekhulan/myinvoice/issues/121)).** Import z Fakturoidu (a stejně i z iDokladu) dosud ignoroval, že zdrojový systém doklad eviduje jako zaplacený — všechny importované faktury skončily jako nezaplacené a upomínkový cron na ně posílal klientům upomínky. Nově: doklad ve zdroji **zaplacený** (Fakturoid `status=paid`, iDoklad `PaymentStatus` Uhrazeno/Přeplaceno) se importuje rovnou jako **Zaplacená** s datem úhrady ze zdroje (`paid_on` / `DateOfPayment`, fallback DUZP → datum vystavení) a se snapshoty jako při vystavení; Fakturoid **stornovaný** doklad jako **Stornovaná**. Platí pro vydané faktury, dobropisy i přijaté faktury (expenses). Ostatní doklady (nezaplacené, částečně uhrazené) zůstávají záměrně Koncept k ruční kontrole — auto-vystavení by na reálně nezaplacené historické doklady spustilo hromadné upomínky. Manuál § 17.8.5 a § 17.9.4.

## [4.18.0] — 2026-06-05

### Added

- **Kategorie tržby na šabloně opakované fakturace (#119).** Šablona může mít pevně zvolenou kategorii tržby, která přebíjí dosavadní dynamický fallback (výchozí kategorie zakázky → zákazníka) — hodí se pro stabilní zařazení domén, hostingu, licencí a paušálů, kde změna defaultu zakázky/zákazníka nemá tiše měnit kategorii budoucích faktur. Bez výběru (default, všechny existující šablony) zůstává chování beze změny. Kategorie se při generování ukládá na fakturu jako snapshot — pozdější změna šablony už vygenerované doklady nemění. Volba je v editoru šablony v sekci Faktura a na detailu šablony; API `/api/recurring` přijímá a vrací `revenue_category_id`. Manuál § 15.2.2.

- **DPH konstanty v číselníku daňových konstant — už ne natvrdo v kódu.** Limit kontrolního hlášení 10 000 Kč (A.4/B.2 vs sumace A.5/B.3), základní a snížená sazba DPH jsou nově roční konstanty v `Nastavení → Číselníky → Daňové konstanty` — ve zvýrazněné skupině **„DPH a výkazy"** na začátku, protože na rozdíl od ostatních (DPFO/OSVČ) platí pro všechny plátce. Z nich se odvozuje: limit KH (generátor KH i sloupec KH v Knize DPH), práh rozřazení sazeb do sloupců výkazů (dřív 8× natvrdo `20.5` — DPH přiznání, KH, Kniha DPH, Pohoda export), sazba samovyměření u RC importů (AI extraktor) a auto-klasifikace přijatých dokladů. Výkazy berou konstanty **roku vykazovaného období**, takže budoucí změna limitu/sazby nerozbije zpětně generované výkazy; starší uložené overridy se automaticky doplní o nové klíče z defaultů. Manuál § 26.

- **Kniha DPH: sloupec KH ukazuje efektivní sekci kontrolního hlášení.** A.4/A.5 a B.2/B.3 nejsou vlastnost klasifikačního kódu, ale dokladu — rozhoduje celková hodnota dokladu vč. DPH (limit 10 000 Kč) a DIČ protistrany. Kniha dosud tiskla statickou hodnotu z číselníku (kód 40 → „B.2" u všech přijatých), nově počítá sekci per doklad stejnou logikou jako generátor KH: drobný doklad pod limit ukáže B.3 (sumace), doklad bez DIČ protistrany jde do sumace i nad limit — shodně se sestavou POHODA.

### Fixed

- **Registry parserů bankovních avíz už není v cfg.sample.php.** Výčet parser tříd si uživatelé kopírovali do cfg.php, kde by zatuhlé class names po případném budoucím přejmenování shodily aplikaci. Registry žije v kódu (baseline defaults) a nové parsery se objeví s update aplikace bez zásahu do cfg; v sample zůstal jen zakomentovaný příklad override (vypnutí slotu / vlastní parser). Existující cfg.php s plným výčtem fungují beze změny.

- **Sample data jsou daňově smysluplná.** Generátor testovacích dat dosud vyráběl daňové nesmysly: US dodavatelům (Anthropic, GitHub) účtoval českou 21% DPH bez reverse charge, tuzemským klientům nasazoval RC a EU klientovi s DIČ českou DPH; doklady neměly žádnou DPH klasifikaci. Nově: US dodavatelé služeb = reverse charge + kód 24 (dovoz služby — ř.12 + zrcadlový odpočet ř.43, v Knize DPH pár „43 ř.012/43 ř.043"), tuzemští dodavatelé kód 40/41 s mixem sazeb 21/12 % (v KH vznikají B.2 i B.3), EU klienti (SK/DE) reverse charge + kód 22 (služby do JČS, ř.21 + SHV), tuzemští klienti běžná DPH (A.4/A.5). Dobropisy dědí klasifikaci originálu. Sample tak pokrývá všechny hlavní scénáře DPH výkazů.

### Changed

- **Kniha DPH řadí jako POHODA.** Sekce vzestupně dle čísla členění (15 přijatá → 36 uskutečněná → 43 RC/dovozové páry → 47 majetek; dříve vystavené napřed) a doklady uvnitř sekce dle interního čísla dokladu (natural sort; dříve dle data plnění) — výstup tak jde porovnat se sestavou účetní řádek po řádku. RC/dovozový pár (samovyměření + mirror odpočet ř. 43) je nově celý pod členěním 43 — primary řádek (např. dovoz služby ř. 12) se už neukazuje mezi přijatými 15.xxx na začátku, ale hned před svým zrcadlovým odpočtem za sekcí 36, přesně jako POHODA („43 ř.012" + „43 ř.043").

## [4.17.0] — 2026-06-05

Hlavní novinka: **identifikovaná osoba** ([#94](https://github.com/radekhulan/myinvoice/issues/94), díky @mikolashodan za podnět) — plný režim § 6g–6l ZDPH pro neplátce s přeshraničními povinnostmi. Dále per-supplier kopie odchozích e-mailů (CC/BCC), refaktoring parserů avíz ([#118](https://github.com/radekhulan/myinvoice/pull/118), díky [@blondak](https://github.com/blondak)) a zpřístupnění měsíčního exportu rolím accountant/readonly.

### Added

- **Identifikovaná osoba — § 6g–6l ZDPH ([#94](https://github.com/radekhulan/myinvoice/issues/94), díky @mikolashodan za podnět).** Nový přepínač **Identifikovaná osoba** v nastavení dodavatele (jen pro neplátce; migrace 0103) pokrývá freelancery fakturující služby do EU bez tuzemského plátcovství — žádné přepínání plátce/neplátce: tuzemsko zůstává beze změny (bez DPH), navíc: **(1)** u **EU** klienta s DIČ se v editoru automaticky zapne reverse charge a předvyplní klasifikace 22 (EU služby) — s vysvětlujícím hintem, proč na dokladu není sazba DPH (samovyměří odběratel sazbou své země); klient ze 3. země RC nemá (mimo předmět DPH); sloupec částek se u RC dokladu neplátce/IO jmenuje **„Celkem bez DPH"** (editor i PDF) — částky jsou základ daně; PDF je daňový doklad s DIČ a klauzulí dle **čl. 196 směrnice 2006/112/ES** (tuzemský RC dál cituje § 92a — klauzule je nově country-aware pro všechny); **(2)** **pravidelná fakturace**: šablona má nově RC checkbox (dřív se flag jen přenášel „z faktury") se stejnou auto-logikou pro IO; generované faktury RC nesou a položky se auto-klasifikují kódem 22; **(3)** DPH přiznání se generuje s **`typ_platce='I'`** — jen řádky samovyměření z přeshraničních přijatých plnění (ř. 3–6, 12–13), **bez zrcadlového odpočtu ř. 43** (IO nemá nárok na odpočet), vždy měsíčně, nečekané řádky se vynechají s upozorněním; **(4)** kontrolní hlášení zobrazí upozornění, že IO KH nepodává; souhrnné hlášení funguje (podporovalo IO už dříve). Manuál § 6.1.1 a § 24.

- **Kopie odchozích e-mailů dodavateli — per dodavatel, s volbou CC/BCC.** Dosud globální cfg flagy (`cc_supplier_on_send`, `cc_supplier_on_reminder`, `cc_supplier_on_approval[_reminder]`) lze nově přenastavit v nastavení dodavatele zvlášť pro **odeslání dokladu**, **upomínky** a **schvalování výkazů** (žádost + schvalovací upomínka sdílí jednu volbu — stejné členění účelů jako kontakty klienta z #86): *Dle konfigurace* (default — cfg zůstává živý fallback, efektivní hodnota je ve volbě vidět) / *Neposílat* / *Kopie (CC)* / *Skrytá kopie (BCC)*. Kopie jde přes jednotný `RecipientResolver` — v modalu odeslání je vidět jako chip „kopie dodavateli" a lze ji pro konkrétní e-mail smazat; dedup ji nepřidá, pokud je e-mail dodavatele už mezi příjemci. Manuál § 18.5.4.

### Changed

- **Kopie dodavateli při ručním odeslání už není „tichá".** Dříve se CC dodavateli přidávalo serverem až po odeslání z modalu (uživatel ho neviděl a nemohl odebrat); nově je předvyplněné přímo v poli CC/BCC modalu a co uživatel v modalu vidí, to se odešle — beze změn na pozadí.
- **Refaktoring parserů bankovních e-mailových avíz ([#118](https://github.com/radekhulan/myinvoice/pull/118), díky [@blondak](https://github.com/blondak)).** Sdílené helpery čtyř parserů (normalizace, regexy, částky, data, účty, symboly, měny) přesunuté do společného `AbstractBankEmailNoticeParser`; bank-specifická detekce a extrakce polí zůstávají per parser (−430/+253 řádků, chování beze změny — pokryto stávajícími testy). Sjednocené helpery převzaly nejrobustnější z původních variant: parseAmount nově u všech bank zvládá oba oddělovače tisíců i znaménko, „N/A" hodnoty se nulují.

### Fixed

- **Měsíční export pro role accountant a readonly.** Stránka Daně → Měsíční export byla viditelná všem rolím, ale spuštění exportu vracelo „Pro tuto akci nemáš oprávnění" — workflow background jobu (start/zrušení/smazání) jede přes POST/DELETE, které RBAC middleware propouštěl jen adminovi, přestože export je věcně čtení (readonly = čtení + export). Endpointy měsíčního exportu jsou nově explicitně povolené všem rolím; akce si dál drží vlastní guard.
- **Změna plátce/neplátce DPH se projeví hned, bez hard refreshe ([#94](https://github.com/radekhulan/myinvoice/issues/94)).** Editor faktur čte plátcovství ze supplier store plněného při startu z `/me` — po uložení nastavení dodavatele se store nově aktualizuje, takže DPH sloupce/sazby v editoru odpovídají okamžitě (dříve až po F5).

## [4.16.0] — 2026-06-05

Daňové opravy reverse charge z EU (díky Pavlovi za podrobné hlášení s reálným dokladem): AI import pořízení zboží z JČS ([#116](https://github.com/radekhulan/myinvoice/issues/116)) a zařazení samovyměření do správného DPH období ([#117](https://github.com/radekhulan/myinvoice/issues/117)).

### Added

- **AI import rozpozná povahu plnění (zboží vs. služba) u reverse charge ([#116](https://github.com/radekhulan/myinvoice/issues/116)).** U zahraničního dokladu bez DPH extraktor nově klasifikuje, zda jde o zboží (VIN/vozidlo/hardware) nebo službu (SaaS/licence), a podle toho nastaví položkám **tuzemskou sazbu 21 %** a klasifikační kód — **23** „Pořízení zboží z JČS" (ř. 3 + ř. 43, KH A.2), **24** „Přijetí služby", **25** „Dovoz zboží ze 3. země". Dříve řádky přebíraly 0 % z cizího dokladu a kód služby → samovyměřená daň vyšla nulová a doklad minul KH A.2. Částka k úhradě se nemění (daň na RC dokladu zůstává 0, samovyměření dopočítají výkazy); do dokladu se zapíše informační varování s rekapitulací automatiky.
- **DUZP pořízení zboží z JČS dle § 25 ZDPH při AI importu.** Zahraniční doklad nese jen datum dodání — zákonné DUZP (15. den měsíce následujícího po dodání, příp. dřívější datum vystavení) se dopočítá automaticky a k němu se naváže i kurz ČNB (§ 4 odst. 8). Editor přijaté faktury u reverse charge zobrazuje nápovědu k DUZP.

### Fixed

- **Pořízení zboží z JČS s pozdě vystavenou fakturou patří do období DUZP ([#117](https://github.com/radekhulan/myinvoice/issues/117)).** Přijaté zahraniční reverse charge doklady (pořízení zboží z EU, služby z EU/3. země, dovoz) se v DPH přiznání, kontrolním hlášení, knize DPH i měsíčním exportu nově zařazují podle **DUZP**, ne podle pozdějšího z dat DUZP/vystavení — povinnost přiznat daň (ř. 3) vzniká k DUZP bez ohledu na držení dokladu (§ 25 odst. 1) a pozdní doklad neblokuje ani zrcadlový odpočet ř. 43 (§ 73 odst. 1 písm. b, potvrzeno SDEU C-895/19). Zboží převzaté v dubnu s fakturou vystavenou v červnu tak správně spadne do května. Tuzemská plnění (vč. tuzemského RC) zůstávají na pozdějším z dat (§ 73 odst. 1 písm. a).
- **Samovyměření reverse charge u řádků se sazbou 0 %.** Pojistka ve výkazech: má-li RC řádek sazbu 0 % (typicky doklady importované před touto verzí), použije se pro samovyměření i rate bucket KH sazba klasifikačního kódu (21 %) — dosud takové doklady tiše vykazovaly základ s nulovou daní. Oprava se projeví i na už zaevidovaných dokladech (net dopad 0 — daň i odpočet se zvednou stejně).
- **GPC výpis Fio EUR účtu se importoval jako CZK a platby se nepárovaly ([#109](https://github.com/radekhulan/myinvoice/issues/109) follow-up).** Fio dle své specifikace plní pole měny v GPC transakcích konstantně `0203` (CZK) i u cizoměnového účtu — detekce měny výpisu dávala per-transakčnímu kódu přednost, takže EUR výpis dostal CZK, zobrazil se v Kč a currency guard při párování zahodil všechny EUR faktury (oprava 4.14.0 přes IBAN se ke slovu vůbec nedostala). Měna **registrovaného bankovního účtu** (GPC výpis je vždy z jednoho účtu = jedna měna) je nově autoritativní pro výpis i transakce; per-transakční kód zůstává jen fallback pro neregistrované účty (Creditas/KB ho plní reálně — ověřeno na vzorcích obou bank). Po updatu stačí výpis smazat a naimportovat znovu.

## [4.15.0] — 2026-06-04

Velká novinka: **e-mailové kontakty odběratele podle účelu** ([#86](https://github.com/radekhulan/myinvoice/issues/86), díky [@blondak](https://github.com/blondak) za výborný návrh) — komu chodí doklady, upomínky a schvalování výkazů, vč. rolí CC/BCC a účelů u e-mailů zakázky.

### Added

- **E-mailové kontakty odběratele podle účelu ([#86](https://github.com/radekhulan/myinvoice/issues/86)).** U klienta lze evidovat až 10 e-mailových kontaktů s účely **Doklady** (faktury, dobropisy, poděkování za platbu), **Upomínky**, **Schvalování** (výkazy víceprací) a **Komunikace**, každý s rolí **to/cc/bcc** (role může být per účel) a stavem aktivní/neaktivní. Jakmile má účel přiřazený aktivní kontakt, hlavní e-mail se pro daný typ zprávy přestane automaticky přidávat (zůstává záchranný fallback — tlačítko „Převzít hlavní e-mail" ho přidá explicitně). Upomínky bez vlastního kontaktu spadnou na kontakty Doklady. Kontakty se zobrazují v detailu klienta a spravují ve formuláři klienta i přes API (`email_contacts`, replace-all).
- **Účely u fakturačních e-mailů zakázky.** Každý ze 3 e-mailů zakázky lze omezit na typy zpráv (Doklady/Upomínky/Schvalování); nic nevybráno = všechny typy (dosavadní chování). Účely jsou vidět v detailu zakázky.
- **Režim kombinace e-mailů zakázky s kontakty klienta** — `Výchozí` (dosavadní per-typ chování: doklady/upomínky přidat, schvalování nahradit), `Vždy přidat`, `Vždy nahradit`.
- **Jednotný `RecipientResolver`** nahrazuje šest dříve duplicitních výpočtů příjemců (odeslání, auto-odeslání po schválení, upomínky, žádost o schválení, cron připomínek schválení, poděkování za platbu). Dedup napříč to/cc/bcc (case-insensitive, priorita to > cc > bcc), stabilní pořadí, validace; finální příjemci vč. provenance v activity logu. Pokryto 62 testy vč. 24-řádkové kombinační matice — **bez nastavených kontaktů je chování bit-perfect stejné jako dřív** (žádná datová migrace, žádná změna pro existující instalace).
- **Modal odeslání a upomínky zobrazuje původ příjemců** (kontakt: popisek/účel · zakázka · hlavní e-mail) — příjemce vyřeší backend (`GET /api/v1/invoices/{id}/recipients`), CC/BCC jsou v modalu viditelné a editovatelné. Seznam jde dál libovolně ručně upravit.

### Fixed

- **Kontakt jen s rolí kopie (cc/bcc) nezablokuje odeslání** — typicky „kopie účtárně, hlavní příjemce zůstává jednatel": prázdné TO se doplní hlavním e-mailem klienta.

## [4.14.0] — 2026-06-04

Novinka: **placeholdery období v pravidelné fakturaci** ([#108](https://github.com/radekhulan/myinvoice/issues/108), díky [@blondak](https://github.com/blondak) za návrh) + oprava párování GPC výpisů cizoměnových účtů evidovaných IBANem ([#109](https://github.com/radekhulan/myinvoice/issues/109)).

### Added

- **Placeholdery období v popisech položek a poznámkách šablon pravidelné fakturace ([#108](https://github.com/radekhulan/myinvoice/issues/108), část 1).** Tokeny se při každém vygenerování faktury nahradí podle DUZP (u proformy podle data vystavení): `{YYYY}`/`{YY}` (rok, posun po letech `{YYYY+1}`), `{M}`/`{MM}` (měsíc, posun po měsících vč. přetečení roku), `{MMMM}` (název měsíce dle jazyka dokladu), `{Q}` (čtvrtletí), `{D}`/`{DD}` (den), `{DATE}` s datovou aritmetikou `±N` `D`/`M`/`Y` (`{DATE+1Y-1D}`) a `{BOM}`/`{EOM}` (začátek/konec měsíce, posun po měsících). Typický use case: `Prodloužení domény example.cz na období {DATE} - {DATE+1Y-1D}`. Posun po měsících/letech je clampovaný na poslední den cílového měsíce (31. 1. `{DATE+1M}` → 28. 2., jako MySQL `DATE_ADD`). Nerozpoznané tokeny zůstávají netknuté — stávající šablony fungují beze změny; dosavadní synchronizace `M/YYYY` s DUZP zůstává samostatnou volbou. Rozbalovací nápověda přímo v editoru šablony, dokumentace v manuálu § 15.2.3. (Část 2 — ceníkové položky — zůstává otevřená v issue.)

### Fixed

- **Párování GPC výpisů cizoměnových účtů evidovaných IBANem ([#109](https://github.com/radekhulan/myinvoice/issues/109)).** GPC výpis nese domácí číslo účtu, ale cizoměnové účty (typicky EUR) se v Bankovních účtech evidují často jen IBANem — výpis se pak nepřiřadil k účtu: zůstal bez měny (UI ho zobrazilo v Kč) a transakce se nepárovaly (`unknown_supplier_for_account`). Import výpisu i matcher nově porovnávají i domácí část českého IBANu (funguje i IBAN omylem vepsaný do pole „Číslo účtu"); kód banky se umí vzít z IBANu. Po updatu stačí výpis smazat a naimportovat znovu.

## [4.13.2] — 2026-06-04

Opravy z hlášení komunity: ztráta PDF přijatých faktur při Docker updatu ([#115](https://github.com/radekhulan/myinvoice/issues/115)), špatné číslo dokladu dodavatele u importu z Fakturoidu ([#113](https://github.com/radekhulan/myinvoice/issues/113)) a robustnější parser avíz České spořitelny ([#110](https://github.com/radekhulan/myinvoice/issues/110)).

### Fixed

- **PDF přijatých faktur přežijí Docker image update ([#115](https://github.com/radekhulan/myinvoice/issues/115)).** Dokumentovaný produkční deploy (bind-mount `cfg.docker.php` z `cfg.sample.php`) směroval archiv originálních PDF přijatých faktur do vrstvy kontejneru místo do `/data` volume — při každém `docker compose pull && up` soubory zmizely (vydané faktury a Dokumenty byly v pořádku). Cesty `purchase_invoice.archive_storage` a `invoice.import_archive_storage` se nově při nastaveném `MYINVOICE_DATA_DIR` vždy přepíší pod data volume, stejně jako ostatní `storage.*` cesty. Soubory nahrané od posledního recreate kontejneru lze před updatem zachránit: `docker compose cp app:/var/www/html/storage/purchase-invoices ./pi-backup` a po updatu nakopírovat do `/data/storage/purchase-invoices` (metadata v DB zůstala, doklady budou zase stažitelné).
- **Import nákladů z Fakturoidu ukládá skutečné číslo dokladu dodavatele ([#113](https://github.com/radekhulan/myinvoice/issues/113)).** Pole „číslo dokladu dodavatele" se plnilo interním číslem přiděleným Fakturoidem (`number`) místo čísla původního dokladu (`original_number`). Priorita prohozena; interní číslo se použije jen jako fallback, když originál chybí. Už naimportované doklady se zpětně nemění (číslo lze upravit ručně v detailu).
- **E-mailová avíza: záchranný fallback pro vlastní účet ([#110](https://github.com/radekhulan/myinvoice/issues/110)).** Avízo České spořitelny „Odešla platba" nemusí obsahovat řádek „Číslo účtu:" a zpracování pak končilo chybou `parse_failed`. Když konfigurovaný vzor pole `recipient_account` nic nenajde, vytáhne se vlastní účet z úvodní věty avíza („z účtu … odešla platba" / „na účet … dorazila platba"). Přesné doladění vzorů odchozí šablony čeká na anonymizovaný vzorek (issue zůstává otevřené).

## [4.13.1] — 2026-06-03

Nové systémové parsery bankovních e-mailových avíz **UniCredit Bank** a **ČSOB** (díky [@blondak](https://github.com/blondak), [#106](https://github.com/radekhulan/myinvoice/pull/106), navazuje na [#58](https://github.com/radekhulan/myinvoice/issues/58)) + zpevnění celé parser registry.

### Added

- **Systémové parsery UniCredit Bank („Informace o pohybu na účtu") a ČSOB („Moje info - Avízo") ([#106](https://github.com/radekhulan/myinvoice/pull/106)).** Vedle Raiffeisenbank a České spořitelny tak avíza fungují out-of-the-box pro čtyři banky. Registr parserů je nově **typovaný a rozšiřitelný přes `cfg.php`** (`bank_email.notice_parsers` — slot lze vypnout `null`/`false`), systémové parsery dodávají svůj provider z kódu bez DB řádku a v UI se vybírají přes jednotnou referenci (`system:<kód>` / `db:<id>`). Unit testy parserů, migrace `parser_type` ENUM → VARCHAR.

### Changed

- **Test parseru umí explicitně otestovat i vypnutý provider** (ladění konfigurace před zapnutím); automatický výběr i scan používají dál jen zapnuté.
- **Výběr parseru v mapování účtů nabízí jen zapnuté providery**; aktuálně vybraný vypnutý zůstává viditelný se suffixem „vypnutý".

### Fixed

- **Přísnější ověření odesílatele u systémových parserů.** Doména se kontroluje na konci adresy (vč. subdomén) místo pouhého výskytu v textu — `attacker@csob.cz.evil.com` už neprojde.
- **Validace `system:` referencí v mapování** proti registru parserů — neznámý kód degraduje na automatický výběr místo slepého uložení.

## [4.13.0] — 2026-06-02

Velká novinka: **automatické párování plateb z bankovních e-mailových avíz přes IMAP** ([#104](https://github.com/radekhulan/myinvoice/issues/104)). K tomu sjednocení správy měn a bankovních účtů do jedné stránky, nová sekce **E-maily** v menu a řada oprav.

### Added

- **Bankovní e-mailová avíza přes IMAP ([#104](https://github.com/radekhulan/myinvoice/issues/104)).** Příchozí platby se umí spárovat na faktury z bankovních e-mailových avíz. Read-only IMAP polling (zprávy se neoznačují jako přečtené), **registr parserů** (předkonfigurovaný Raiffeisenbank „Pohyb na účtě" + univerzální **regex parser** s vlastními poli), mapování **bankovní účet → IMAP účet → parser** s tolerancí částky, deduplikace zpráv a log zpracování. Více IMAP schránek (každá banka vlastní), akce po zpracování (flag / přesun / označit přečtené). Konfigurace na nové stránce **Systém → Bankovní účty**, cron `cron-bank-email-notices` (každých 30 min). Hesla schránek šifrovaná (AES‑256‑GCM).
- **Ověření autenticity avíz (DKIM/DMARC).** Volitelně per IMAP účet: zpracují se jen e-maily, které přijímací server označil v hlavičce `Authentication-Results` jako `dkim`/`dmarc=pass` se správnou doménou odesílatele — ostatní se zamítnou (`security_rejected`). Volitelné připnutí důvěryhodného `authserv-id` proti podvržení hlavičky. Brání podvržení falešného avíza vedoucímu k automatickému označení faktury jako zaplacené.
- **Sekce „E-maily" v menu (Systém).** Záložky **Odeslané e-maily**, **E-mail šablony** a **Elektronické podpisy** sloučené pod jednu položku (vzor Číselníků).

### Changed

- **Sjednocení správy měn a bankovních účtů.** Měny i bankovní účty se nově spravují výhradně na stránce **Bankovní účty** (přesun z Nastavení a z Číselníku — tab „Měny" v Číselníku odebrán). Editor účtu je plnohodnotný (kód, symbol, desetinná místa) včetně načtení účtu z registru plátců DPH (zobrazí se jen když má dodavatel vyplněné DIČ).
- **Reorganizace menu Systém.** „E-maily" za „Uživatelé", „Externí integrace" přesunuta za „Log".
- **Sjednocení „e-mail" v celém UI** (dříve místy „email").
- **`reset.php` maže databázi dynamicky.** Místo zastaralého napevno psaného seznamu maže všechny tabulky kromě keep-listu (globální číselníky + schéma) — nezaostává za schématem a vyčistí i nové tabulky včetně citlivých dat (IMAP hesla, podpisové certifikáty). Globální seedy (klasifikace DPH, výchozí parser) zůstávají.

### Fixed

- **Správné počítání použití měny.** Smazání měny se nově blokuje, pokud je použita na **kterémkoli** dokladu (vydané i přijaté faktury, zakázky, pravidelné fakturace) — dřív se počítaly jen vydané faktury a smazání pak selhalo až na úrovni databáze. Friendly hláška místo holé chyby.
- **Chybové hlášky u operací s měnami/avízy.** Operace, které dřív při chybě selhaly tiše (uživatel nic neviděl), teď zobrazí konkrétní hlášku z backendu.
- **Mobilní zobrazení Bankovních účtů.** Tabulka účtů má mobilní karty; hlavičky sekcí se zalomí a tlačítka nepřetékají.
- **Admin-only přístup ke čtecím endpointům bankovních avíz** (dříve jen přes frontend guard).
- **S/MIME test na Windows.** Testovací fixtura si dohledá `openssl.cnf`, takže neselhává mimo CI.

## [4.12.2] — 2026-06-02

Číslování interních čísel přijatých faktur je nově **konfigurovatelné per dodavatel** a dotažené ošetření kolizí (obdoba vydaných faktur). Plus oprava ověření DIČ u českých OSVČ. Navázáno na [#103](https://github.com/radekhulan/myinvoice/issues/103).

### Added

- **Vlastní šablona interního čísla přijaté faktury ([#103](https://github.com/radekhulan/myinvoice/issues/103)).** V **Nastavení → Číslování faktur** přibylo pole **„Šablona pro přijatou fakturu"** (stávající „Šablona pro fakturu" se přejmenovala na **„Šablona pro vydanou fakturu"**). Placeholdery: `{PP}` daňový prefix (PF/PN plný nárok, KU/KN krácený, NU/NN bez nároku), `{YYYY}`/`{YY}`/`{MM}` datum, `{C+}` čítač. Výchozí (a beze změny pro existující instalace) zůstává `{PP}{YY}{MM}{CCC}` → `PF2605001`; lze zadat i legacy `PF-{YYYY}{MM}-{CCCC}` → `PF-202605-0001`. Scope čítače plyne ze šablony (s `{MM}` měsíční řada, jinak roční). Živý náhled příštího čísla přímo u pole.

### Fixed

- **Ošetření kolize ručního interního čísla přijaté faktury ([#103](https://github.com/radekhulan/myinvoice/issues/103)).** Doteď ruční zadání už obsazeného čísla končilo holou chybou 500 a auto-generátor nepřeskakoval obsazená čísla (ručně zadané číslo „dopředu" mohlo shodit přechod na stav Přijatá). Nově je generátor **samoopravný** (přeskočí obsazená, skočí za nejvyšší použité číslo řady) a kolize ručního čísla vrátí srozumitelnou hlášku **409** místo 500 — stejně jako u vydaných faktur. Unikátní index zůstává definitivní pojistka proti duplicitám.
- **Ověření DIČ u českých OSVČ (rodné číslo) ve VIES.** Tuzemská DIČ ve tvaru `CZ` + rodné číslo (9–10 číslic, typicky OSVČ) se chybně hlásila jako „neplatné / neexistuje", protože se číselná část posílala do ARES jako IČO (8 číslic). Nově se taková DIČ ověří přímo přes autoritativní VIES (např. `CZ8901311870` → platné).

### Changed

- **Upřesnění interního číslování přijatých faktur v manuálu a UI.** Zastaralý formát `PF-YYYYMM-NNNN` nahrazen aktuálním `PF2605001` (popisky pole, placeholder, manuál); doplněn popis prefixů dle daňového typu a chování čítače.

## [4.12.1] — 2026-06-02

Oprava AI extrakce přijatých dokladů: u faktur s více položkami se už **nezahazuje itemizace**. Návazné na [#99](https://github.com/radekhulan/myinvoice/issues/99).

### Fixed

- **AI extrakce zachová položky u dokladů s cenami včetně DPH.** Víceřádkový doklad, kde jsou jednotkové ceny ve skutečnosti brutto (e-shopy se sloupcem „Cena celkem s DPH"), se už neslučuje na jediný základový řádek. Rozpozná se podle konzistentní jednosazbové rekapitulace, kde součet řádků odpovídá celkové částce s DPH; faktura se vede v režimu „ceny s DPH" a DPH se dopočte shora koeficientem (§ 37 ZDPH), přesná rekapitulace dokladu se připne přes ruční override (§ 73). Všechny položky zůstanou zachované a celek sedí na haléř.
- **AI extrakce respektuje řádkovou částku z dokladu (autoservisy).** Nové pole `line_total_without_vat` (sloupec „Částka" / „Celkem bez DPH" / „Základ"): když součin množství × jednotková cena neodpovídá řádkové částce na dokladu (typicky autoservisy, kde „Cena" není jednotková cena k násobení množstvím — např. „AW 8,29 × 1 980" má řádkovou částku 1 980), vezme se řádková částka jako pravda. Doklad si tak zachová všechny položky místo sloučení na jediný řádek.

## [4.12.0] — 2026-06-02

Velká novinka: **elektronické podpisy**. Vydané faktury a výkazy práce lze podepisovat certifikátem (**PAdES**) a odchozí e-maily přes **S/MIME** — vše přes nové podpisové profily s konfigurací per výstup. K tomu oprava daňově korektní AI extrakce přijatých dokladů a několik UX vylepšení.

### Added

- **Elektronický podpis PDF certifikátem (PAdES) ([#44](https://github.com/radekhulan/myinvoice/issues/44)).** Vydané faktury a samostatné výkazy práce lze podepsat certifikátem přes nové **podpisové profily** (firemní profil dodavatele i osobní profily uživatelů). Per-výstup **Konfigurace podpisů** (zda a odkud se bere profil), per-doklad výběr na detailu faktury, **PAdES-B** / **PAdES-T** s časovým razítkem (RFC 3161 TSA), politika hesla k certifikátu (šifrované uložení / passphrase file), volba chování při chybě (`fallback_unsigned` / `fail_closed` / `skip_when_unconfigured`) a kompletní audit. Vlastní admin stránka **Systém → Elektronické podpisy**; RBAC pro admina, účetního i readonly. Měkký fallback: když podpis selže nebo není nakonfigurovaný, doklad se vydá nepodepsaný (pokud není nastaveno tvrdé selhání). Detailní postup v [manuálu, kapitola 28](manual/28_Elektronicke_podpisy.md).
- **S/MIME podepisování odchozích e-mailů ([#45](https://github.com/radekhulan/myinvoice/issues/45)).** Odesílané faktury, upomínky, schvalovací e-maily i poděkování za úhradu lze podepsat S/MIME přes tytéž podpisové profily (jednotný certifikát profilu pro PDF i e-mail). Opt-in a fail-open — selhání podpisu nikdy nezablokuje doručení e-mailu (mimo explicitní `fail_closed`).
- **AI extrakce — plocha „Extrahovat z PDF" nad konfigurací ([#97](https://github.com/radekhulan/myinvoice/issues/97)).** Když je AI už nakonfigurované, je opakovaná akce (nahrání dokladu) primární a jde nahoru; konfigurace (API klíč + model) se sbalí do sekce „Nastavení AI".
- **Faktura PDF — tlačítko „Stáhnout PDF" + indikace podpisu ([#92](https://github.com/radekhulan/myinvoice/issues/92)).** Přejmenované tlačítko (sjednoceno s manuálem) a pravdivý badge **„Podepsáno"**, který se ukáže jen když se daný doklad skutečně podepíše (zapnutý výstup + profil s certifikátem), plus tooltip že se PDF po úpravě automaticky přegeneruje a podepíše.

### Fixed

- **Daňově korektní AI extrakce přijatých dokladů ([#99](https://github.com/radekhulan/myinvoice/issues/99)).** Účtenky za PHM, kde je „cena/litr" ve skutečnosti brutto, se už nepřepočítávají na vlastní (mírně odlišný) základ s **uměle dopočítaným zaokrouhlením**. Když doklad obsahuje vnitřně konzistentní rekapitulaci DPH, eviduje se **verbatim přesně dle dokladu** (§ 73 odst. 6 / § 30 / § 100 ZDPH); jinak se dopočítá shora z celkové částky. Přijatý doklad je záznam, ne výsledek kalkulačky.
- **Vlastní e-mailová šablona renderuje proměnné v předmětu ([#98](https://github.com/radekhulan/myinvoice/issues/98)).** Předmět vlastní DB šablony se nyní renderuje stejným sandboxovaným Twigem jako tělo e-mailu — místo literálu `{{ invoice.varsymbol }}` se doplní skutečné hodnoty.

## [4.11.1] — 2026-06-01

Oprava: pravidelná fakturace u **neplátce DPH** nově nevyplňuje DPH — chová se stejně jako jednorázové vystavení faktury.

### Fixed

- **Pravidelná fakturace u neplátce DPH nevyplňuje DPH ([#95](https://github.com/radekhulan/myinvoice/issues/95)).** Šablona pravidelné fakturace dříve vždy nasazovala výchozí (nenulovou) sazbu DPH, takže neplátci generovala faktury s DPH — na rozdíl od jednorázového editoru, který pro neplátce volí 0 % „Osvobozeno". Nově se formulář šablony řídí příznakem plátce u dodavatele stejně jako editor faktury (skrytý výběr DPH i přepínač „ceny s DPH", nulová sazba). Navíc to **autoritativně hlídá i generátor**: při vystavení faktury ze šablony u neplátce sjednotí sazby položek na 0 % — takže se opraví i šablony uložené dříve s nominální sazbou (vč. cron generování, otevřených konceptů i REST API). DPH na faktuře vždy určuje výhradně dodavatel, ne plátcovství odběratele.

### Build

- **Docker build kopíruje `pnpm-workspace.yaml`.** Multi-arch image build padal na `ERR_PNPM_MINIMUM_RELEASE_AGE_VIOLATION` (vite@8.0.16), protože Dockerfile kopíroval jen `package.json` + `pnpm-lock.yaml`, ale ne supply-chain whitelist z `pnpm-workspace.yaml`. Novější `pnpm@latest` začalo defaultně vynucovat minimální stáří balíků; bez whitelistu odmítlo záměrně povýšenou (čerstvou) verzi vite. Workspace config se nově kopíruje před `pnpm install`.

## [4.11.0] — 2026-06-01

Přehled odeslaných e-mailů ([#88](https://github.com/radekhulan/myinvoice/issues/88)) nově ukazuje i **neúspěšná odeslání** — hned je vidět, co se nepodařilo doručit. Upomínky jsou konfigurovatelné ([#91](https://github.com/radekhulan/myinvoice/issues/91)): vypnutí u konkrétní faktury a nastavitelný práh „po kolika dnech po splatnosti". Plus drobná vylepšení použitelnosti a opravy pohledávkových přehledů.

### Added

- **Přehled odeslaných e-mailů ([#88](https://github.com/radekhulan/myinvoice/issues/88)).** Nová admin stránka **Systém → Odeslané e-maily** — všechny e-maily rozeslané aplikací (odeslání faktur, upomínky, schvalovací upomínky, poděkování za úhradu, připomínky konceptů, testovací odeslání) v jednom filtrovatelném pohledu s odkazem na fakturu a příjemci. Automatická (cron) odeslání jsou připsána „Systému". Čte se z existujícího auditního logu, žádná změna schématu.
- **Viditelnost neúspěšných odeslání.** Přehled ukazuje i e-maily, které se **nepodařilo odeslat** (nedostupný SMTP, odmítnutý příjemce, chyba PDF) — červený stav **Neodesláno** s textem chyby, filtr stavu (Vše / Odesláno / Neodesláno) a zkratka „Neodesláno: N". Selhání se nově loguje napříč všemi cestami odeslání (ruční i hromadná upomínka, cron upomínek i schvalovacích upomínek, odeslání faktury, auto-odeslání po schválení, poděkování za úhradu, připomínka konceptu, testovací odeslání).
- **Konfigurovatelné upomínky ([#91](https://github.com/radekhulan/myinvoice/issues/91)).** Per-faktura přepínač **Posílat automatické upomínky** v editoru (výchozí zapnuto) — vypnutím cron tu jednu fakturu přeskočí, i když má dodavatel a klient upomínky zapnuté; ruční i hromadné odeslání funguje dál. Navíc nastavitelný **práh dní po splatnosti** pro první upomínku per dodavatel (předvolby 3 dny / týden / měsíc / vlastní); CLI `--days` ho při potřebě přebije.
- **Měsíční export — výchozí minulý měsíc.** Stránka měsíčního exportu nově předvyplní **předchozí** měsíc místo aktuálního (export se typicky dělá po uzávěrce skončeného měsíce).

### Changed

- **Přepínač upomínek v editoru faktury** se přesunul do pravého boxu *Datumy*, pod pole *Splatnost* — logicky vedle data, od kterého se upomínky odvíjejí.

### Fixed

- **Klon faktury bere splatnost stejně jako nová faktura ([#90](https://github.com/radekhulan/myinvoice/issues/90)).** Klon vydané faktury bez zakázky dříve dostal splatnost = datum vystavení (0 dní); nově se počítá stejnou prioritou zakázka → klient → dodavatel → 7 dní. Klon navíc zdědí i přepínač automatických upomínek ze zdrojové faktury.
- **Doklad ze zaplacené zálohy už nestraší jako nezaplacený.** Finální daňový doklad vystavený z plně uhrazené proformy (`amount_to_pay = 0`) se přestal objevovat v přehledech „Po splatnosti", aging, cash-flow i v upomínkách. Pohledávkové dotazy nově vylučují plně uhrazené doklady a takový doklad se při vystavení rovnou označí jako zaplacený (kvůli kasovým reportům).
- **Přehled odeslaných e-mailů padal na 500 (MariaDB).** Předchozí verze používala MySQL-only operátor `->>`, který MariaDB neumí; nahrazeno za `JSON_UNQUOTE(JSON_EXTRACT(...))`.

## [4.10.0] — 2026-06-01

Odolné a samoopravné číslování faktur ([#85](https://github.com/radekhulan/myinvoice/issues/85)) — automatické vyhnutí se kolizím čísel, dorovnání číselných řad po importu a srozumitelné hlášky místo chyby 500. Plus oprava jednotkové ceny s DPH v ISDOC u tuzemského reverse charge.

### Added

- **Samoopravné číslování faktur ([#85](https://github.com/radekhulan/myinvoice/issues/85)).** Když je interní počítadlo pozadu za již použitými čísly (po importu historických dokladů, ruční úpravě v DB nebo ručním číslování), generátor nově obsazené číslo nevezme: skočí za nejvyšší skutečně použité číslo dané řady (typ + období) a najde první volné. Místo žádné ruční administrace tak číslování „dožene" samo. Vše se opírá o unikátní index `(supplier_id, varsymbol)` jako definitivní pojistku.
- **Dorovnání číselných řad po importu.** Po importu vydaných faktur (ISDOC/Pohoda) se počítadlo automaticky posune za nejvyšší importované číslo odpovídající aktuálnímu formátu, takže další vystavená faktura na něj plynule naváže.
- **Upozornění u ručního čísla.** Když v editoru zadáš vlastní číslo faktury, objeví se hláška, že obchází automatickou řadu a za jeho jedinečnost a návaznost ručíš sám.

### Fixed

- **Kolize čísla dokladu už nekončí chybou 500.** Zadání čísla, které už u dodavatele existuje (ruční číslo při založení, úpravě i vystavení), nově vrací srozumitelnou hlášku „číslo už existuje" místo neošetřené databázové chyby. Generátor se duplicitám aktivně vyhýbá; tahle pojistka řeší i souběžné vystavení (race condition).
- **ISDOC, tuzemský reverse charge — jednotková cena s DPH.** U faktur v režimu přenesení daňové povinnosti se `UnitPriceTaxInclusive` dopočítávala nominální sazbou (např. 121 000 z 100 000), ačkoli daň se přenáší na odběratele (= 0). Řádek si tak protiřečil s `LineExtensionAmountTaxInclusive`. Nově se jednotková cena s DPH odvozuje z řádkového součtu s DPH, takže u reverse charge správně odpovídá základu (daň 0). Rekapitulace DPH s příznakem přenesení i celkové částky byly korektní už dříve.

## [4.9.4] — 2026-06-01

Oprava vystavování faktur v režimu přenesení daňové povinnosti (reverse charge), zachování poznámky pod položkami při vzniku daňového dokladu ze zálohy a odolnost ukládání faktur vůči neproběhlé migraci.

### Changed

- **Reverse charge je volbou na faktuře, ne jen vlastností odběratele.** Checkbox „přenesení daňové povinnosti (DPH 0 %)" se v editoru vydané faktury nově nabízí vždy, když je dodavatel plátce DPH — dosud se zobrazil jen u klienta, který měl příznak `reverse_charge` ve svém profilu. Příznak v profilu klienta nadále funguje jako výchozí předvyplnění při výběru klienta, ale uživatel ho může na konkrétním dokladu přepnout (typicky tuzemský PDP u stavebních prací § 92e ZDPH). RC checkbox zůstává skrytý jen u neplátce DPH, který RC vystavit nemůže.

### Fixed

- **Daňový doklad ze zaplacené zálohy nepřenášel poznámku pod položkami.** Při vzniku finální faktury ze zaplacené zálohové faktury se kopírovala jen poznámka nad položkami (nahrazená textem „Daňový doklad k zálohové faktuře …"); spodní poznámka uživatele se ztrácela. Nově se `note_below_items` ze zálohy zachová napříč všemi cestami vzniku (ruční vystavení, bankovní auto-match).
- **Ukládání faktury selhalo na instalaci pozadu s migracemi.** Po nasazení kódu se sloupci `income_tax_exempt` (migrace 0087), ale bez spuštění migrace, končilo každé uložení vydané faktury chybou „Unknown column 'income_tax_exempt'". Repozitář nyní existenci sloupce detekuje a fakturu uloží (jen bez příznaku osvobození), dokud migrace neproběhne.

## [4.9.3] — 2026-06-01

Per-faktura příznak „Osvobozeno od daně z příjmů" pro doklady mimo základ daně z příjmů (§ 4 ZDP / přefakturace) a sada vylepšení navigace — rychlé vytváření dokladů z horní lišty i bočního menu, předvyplnění zálohové faktury z odkazu a zpřehlednění dashboardu.

### Added

- **Příznak „Osvobozeno od daně z příjmů" na vydané faktuře ([#77](https://github.com/radekhulan/myinvoice/issues/77)).** Pro doklady, které nejsou základem daně z příjmů, ale pro DPH zůstávají běžným zdanitelným plněním — typicky prodej movité věci osvobozený dle § 4 odst. 1 písm. c) ZDP (vozidlo > 1 rok od nabytí) nebo přefakturace / průběžné položky (§ 23 odst. 4 ZDP). Příznak vyloučí částku ze základu daně z příjmů (výkaz DPFO/DPPO i daňový optimalizátor) a u OSVČ tím i z vyměřovacího základu SP/ZP (odvozen z dílčího základu § 7); ve výkazu se ukáže řádek „z toho osvobozeno". **DPH, kontrolní hlášení ani tržby/obrat nejsou dotčeny** (osvobození od daně z příjmů ≠ od DPH). Checkbox se v editoru nabízí jen u OSVČ — u s.r.o. § 4 neplatí a prodej majetku je vždy zdanitelný výnos.
- **Rychlé vytváření z navigace.** V horní liště přibylo decentní tlačítko „+ Vytvořit" s menu (vydaná i zálohová faktura, pravidelná fakturace, klient, dodavatel, přijatá faktura) a v bočním menu nenápadné „+" u příslušných položek (objeví se po najetí myší). Dostupné jen pro uživatele s právem zápisu.
- **Předvyplnění zálohové faktury z odkazu.** `/invoices/new?type=proforma` otevře editor rovnou jako zálohovou fakturu (lze kombinovat s `&client_id=`).

### Changed

- **Zpřehlednění dashboardu.** Odebrána redundantní akční tlačítka (přesunuta do „+ Vytvořit" v liště) i uvítací text a nadpis, aby stránka začínala rovnou daty.

### Fixed

- **ISDOC export — odběratel bez IČO.** Když klient nemá vyplněné IČO (typicky B2C / fyzická osoba), posílal se fiktivní `<ID>0</ID>`. Nově se vyzařuje prázdný `<ID></ID>` (XSD validní), takže účetní software nedostává neexistující identifikátor.
- **Přepínání role Klient ⇄ Dodavatel při zakládání.** Přechod mezi „Nový klient" a „Nový dodavatel" (stejná stránka, jen jiný parametr) nepřeklopil roli formuláře, takže záznam mohl vzniknout se špatnou rolí. Role se nyní správně mění i bez znovunačtení stránky.

## [4.9.2] — 2026-05-31

Rekapitulace DPH se nově automaticky seedne z importovaného dokladu napříč všemi zdroji a oprava ISDOC exportu/importu dle oficiálního standardu 6.0.2 (typy dokladů a nedaňové doklady).

### Added

- **Automatická rekapitulace DPH z importovaného dokladu (§ 73 ZDPH).** Při importu přijaté faktury se rozpad DPH po sazbách nově převezme přímo z dokladu dodavatele a zapeče do uložené rekapitulace — sjednoceně ze všech zdrojů: ISDOC (`TaxTotal`), Pohoda (`invoiceSummary`), iDoklad (řádkové `Prices`) i AI extrakce z PDF. Nárok na odpočet tak sedí na částku daně uvedenou na dokladu. Drobné rozdíly se zapečou dle dokladu, větší se jen ohlásí jako varování (Fakturoid rozpad neposkytuje, proto se neseeduje).

### Fixed

- **ISDOC export — špatné typy dokladů.** `DocumentType` neodpovídal číselníku ISDOC 6.0.2: zálohová faktura se exportovala jako `2` (správně `4` — nedaňový zálohový list) a dobropis jako `5` (správně `2` — opravný daňový doklad). Účetní software tím dostával chybně zařazené doklady. Import čte typy reverzně shodně.
- **ISDOC export — nedaňový doklad měl daňové řádky (pravidlo 4.1.5).** Zálohová faktura je nedaňový doklad (`VATApplicable=false`); nově se `VATApplicable=false` propisuje i do každé řádkové položky (`ClassifiedTaxCategory`), jak vyžaduje standard.
- **ISDOC import — DPH z nedaňového dokladu.** Doklad či položka označené `VATApplicable=false` (neplátce DPH, nedaňový zálohový list) se nově importují s nulovou sazbou a prázdnou rekapitulací, takže se z nedaňového dokladu neeviduje DPH k odpočtu.

## [4.9.1] — 2026-05-31

Kompletní oprava importu z iDokladu po auditu celého mapování proti oficiálnímu iDoklad v3 API (Solitea SDK) — částky, přílohy, měny, země, kurzy i čísla dokladů. Řeší [#80](https://github.com/radekhulan/myinvoice/issues/80).

### Fixed

- **Importované faktury měly nulové částky (#80).** iDoklad v3 nevrací jednotkovou cenu položky v poli `UnitPrice`, ale vnořeně v `Prices` (autoritativní netto `Prices.TotalWithoutVat`); navíc cena může být včetně DPH dle `PriceType`. Import četl neexistující pole, takže **všechny** vydané i přijaté faktury (i dobropisy) skončily s částkou 0 Kč. Nově se čte správné netto a převádí dle režimu ceny.
- **U přijatých faktur chyběly PDF přílohy.** Používal se neexistující endpoint (`/ReceivedInvoices/{id}/Attachments`) vracející 404. Opraveno na `/v3/Attachments/{id}/ReceivedInvoice/…`, který vrací bajty přílohy přímo v odpovědi.
- **Měna se ignorovala — vše se importovalo v CZK.** Seznamové endpointy vrací jen číselné `CurrencyId`, ne kód měny. Doplněn převod přes číselník měn iDokladu, takže se zachová reálná měna dokladu (EUR, USD, …).
- **Země kontaktu se ignorovala — vše CZ.** Stejná příčina (`CountryId` místo kódu); to navíc rozbíjelo automatickou detekci přenesené daňové povinnosti (reverse charge) u zahraničních dodavatelů. Doplněn převod přes číselník zemí.
- **Kurz cizí měny mohl být 100× špatně.** iDoklad drží kurz na `ExchangeRateAmount` jednotek (u měn jako HUF/JPY = 100); nově se přepočítává na jednu jednotku.
- **Číslo přijaté faktury a jméno kontaktu.** U přijatých faktur se nově bere číslo dodavatele (`ReceivedDocumentNumber`) místo interního čísla iDokladu; opraveno i čtení křestního jména kontaktu (`Firstname`).

## [4.9.0] — 2026-05-31

Přijaté faktury: nahrání originálního dokladu už při zakládání i z detailu, ruční rekapitulace DPH přesně dle dokladu dodavatele (§ 73 ZDPH) a sjednocené, matematicky správné zaokrouhlení DPH. Řeší [#82](https://github.com/radekhulan/myinvoice/issues/82).

### Added

- **Nahrání dokladu dodavatele už u nové faktury i z detailu.** Drag&drop zóna pro PDF/fotku se nově ukáže hned při zakládání nové přijaté faktury (soubor se nahraje po prvním uložení) a také v detailu faktury, která zatím doklad nemá — dosud šlo přiložit jen v editaci. Po přetažení se u nové faktury zobrazí zelená kartička „soubor připraven, nahraje se po uložení" s možností odebrání.
- **Ruční rekapitulace DPH dle dokladu (§ 73 ZDPH).** U přijaté faktury lze v boxu **Rekapitulace DPH** přepsat základ i daň **per sazba** přesně tak, jak je uvedeno na dokladu dodavatele (nárok na odpočet je svázaný s částkou daně na dokladu — § 73 odst. 6). Override se zapeče do uložených řádkových součtů, takže se konzistentně promítne do DPH přiznání, kontrolního hlášení, knihy DPH i do daně z příjmů a daňového optimalizátoru. Reverse-charge a režim „ceny s DPH" zůstávají beze změny.
- **AI import předvyplní rekapitulaci DPH dle dokladu.** Při AI extrakci se nově čte i rekapitulace DPH po sazbách; pokud sedí v toleranci na vypočtené hodnoty, předvyplní se override tak, aby základ a daň seděly přesně na doklad — pro jednu i více sazeb.

### Fixed

- **Nekonzistentní zaokrouhlení DPH (#82).** Editor u přijaté faktury ukazoval jinou cenu s DPH, než nakonec uložil backend (151,50 × 21 % → 31,82 vs 31,81). Příčinou bylo pořadí operací u koeficientu sazby; sjednoceno **všude** (frontend i backend, vydané i přijaté faktury) na matematicky správné zaokrouhlení (`základ × sazba / 100`). Uložená historická data se nemění.
- **Neviditelná chyba při uložení faktury.** Když validace selhala (např. prázdný popis položky) a uživatel byl odscrollovaný dole u tlačítka Uložit, nezobrazilo se žádné upozornění (jen tiché 422). Nově se ukáže **toast** a stránka odscrolluje k chybové hlášce — ve všech editorech (vydané, přijaté i pravidelné faktury); u přijatých faktur navíc inline chyba u popisu položky.

## [4.8.0] — 2026-05-31

Zpětné a **obousměrné** párování záloh u vydaných i přijatých faktur, otevírání řádků seznamů v novém panelu a čitelnější ohraničení v tmavém režimu.

### Added

- **Zpětné propojení zálohy ⇄ daňového dokladu (vydané faktury)** — pokud už máš oba doklady samostatně (typicky po importu), spáruješ je zpětně z **kterékoli** strany: v detailu daňového dokladu tlačítkem **Spárovat se zálohou**, v detailu proformy tlačítkem **Spárovat s daňovým dokladem**. Vazba se ukládá na daňový doklad (`parent_invoice_id`); doplní se odečet zálohy (`advance_paid_amount`), pokud byl nulový, nejvýše do výše částky dokladu (aby „K úhradě" nešlo do mínusu). Zaplacení se nemění, propojená proforma vypadne z pohledávek. Tlačítka se zobrazí jen když u odběratele existuje vhodný nespárovaný protějšek.
- **Obousměrné párování zálohy ⇄ vyúčtovací faktury (přijaté faktury)** — dosud šlo propojit jen z vyúčtovací faktury; nově i z detailu **zálohy** tlačítkem **Spárovat s fakturou**. Odpojení z obou stran. Tlačítka opět gated dle existence protějšku.
- **Otevření řádku seznamu v novém panelu** — Ctrl/⌘+klik a kliknutí **prostředním tlačítkem** myši nyní otevřou detail v novém panelu (vydané faktury, přijaté faktury, klienti/dodavatelé, pravidelné fakturace). Běžný klik funguje beze změny, akční tlačítka v řádku zůstávají funkční.

### Fixed

- **Tmavý režim — nezřetelné ohraničení položek.** Políčka položek (vydané, přijaté i pravidelné faktury) používala slabší ohraničení než běžná pole formuláře (neutral-200 vs neutral-300) — sjednoceno na úroveň běžných inputů. Řádkové oddělovače položkových tabulek (včetně editoru a popupu **výkazu práce**) zvýšeny z prakticky neviditelné `neutral-100` na `neutral-200`.

## [4.7.5] — 2026-05-31

Oprava importu z iDokladu — naimportovaly se vždy jen 3 záznamy od každé entity.

### Fixed

- **iDoklad import našel jen 3 doklady od všeho (#80)** — iDoklad v3 API balí stránkované seznamy do envelope `{ "Data": { "Items": [...], "TotalItems": N, "TotalPages": M } }`, kde `Data` je objekt `Page` s **přesně třemi** klíči. Klient `Data` envelope nerozbaloval a omylem za seznam položek bral celý `Page` wrapper, takže import iteroval jeho 3 klíče (`Items`/`TotalItems`/`TotalPages`) — žádný nemá `Id`, takže se vše přeskočilo. Výsledkem bylo uniformní **„z 3, vytvořeno 0"** u kontaktů, vydaných i přijatých faktur a import se ani nestránkoval. Nyní se envelope správně rozbalí a stáhnou se všechny stránky. *(Oprava ruší dřívější domněnku z 4.7.2 o „špatné/demo agendě" — šlo o tuto chybu v parsování odpovědi.)*

## [4.7.4] — 2026-05-31

Sjednocení akčních tlačítek v detailech a čitelnější výkaz práce.

### Changed

- **Jednotná akční lišta v detailech** (vydané i přijaté faktury, klient, zakázka, pravidelná fakturace) — tlačítko **„Upravit"** je vždy **první** a jednotně **zeleně (outline)**; hlavní akce (Vystavit, Vystavit konečnou, Odeslat, Označit jako přijaté/zaúčtováno/zaplaceno, Nová faktura) jsou plné fialové (primary). Méně významné akce (Klonovat, PDF, Exporty, Výkaz) následují až za hlavními, Smazat zůstává poslední. Zelená je v tmavém režimu doladěná pro čitelnost.
- **Výkaz práce (PDF)** — sloupce **Hodiny / Sazba / Celkem** skryjí nadbytečná desetinná „,00", ale jen pokud jsou **všechny hodnoty v daném sloupci celé** (jediný necelý řádek ponechá u celého sloupce 2 desetinná místa). Platí pro samostatný výkaz i výkaz vložený ve faktuře.

## [4.7.3] — 2026-05-31

Daňový audit režimu cen „s DPH": opraveny případy, kdy kopírované doklady nedědily režim a totály se nafoukly o DPH.

### Fixed

- **Daňový doklad k záloze, dobropis a kopie faktury** nedědily příznak **„ceny s DPH"** z původního dokladu. U dokladu vytvořeného v tomto režimu (kde řádková cena nese brutto) se pak zkopírovaná brutto cena přepočítala jako cena **bez DPH** a celková částka se **nafoukla o DPH** (např. 1 210 → ~1 464). Opraveno: `FinalFromProformaCreator` (daňový doklad k proformě), `CancelInvoiceAction` (dobropis) i `BulkReissueAction` (kopie/přefakturace) nyní režim přebírají.
- **Souhrn v seznamu pravidelných fakturací** počítal u šablon v režimu „ceny s DPH" daň zdola (jako by ceny byly bez DPH), takže zobrazený součet byl nafouknutý. Nově respektuje, že brutto už DPH obsahuje.

### Poznámka k daním

Do přiznání DPH, kontrolního hlášení ani knihy DPH `unit_price_without_vat` nevstupuje — daňové výkazy sčítají uložené řádkové základy a DPH (`VatLedgerService`), které byly po celou dobu počítané správně koeficientem. Výše uvedené chyby se týkaly pouze kopírovacích cest, kde se přepočítával celý doklad. Přidána rozsáhlá testová matice (výpočet zhora/zdola, reverse charge v ČR i do zahraničí, plátce/neplátce, dobropis, kopie, generování z pravidelné fakturace).

## [4.7.2] — 2026-05-31

Oprava importu dobropisů z iDokladu a čitelnější PDF přijaté faktury v režimu cen s DPH.

### Fixed

- **iDoklad import dobropisů padal na HTTP 404 (#80)** — volal se neexistující endpoint `IssuedInvoiceCorrections`, takže celý import vydaných dokladů spadl. Dle oficiálního iDoklad SDK je správný endpoint **`/v3/CreditNotes`** a odkaz na původní fakturu je **`CreditedInvoiceId`** (ne `ParentDocumentId`). Opraveno volání i mapování vazby na původní fakturu. *(Pozn.: pokud import nachází jen pár dokladů, jsou API credentials pravděpodobně vytvořené pod jinou/demo agendou iDokladu — ověř ve firmě, ke které patří.)*

### Changed

- **PDF přijaté faktury v režimu cen s DPH** ukazuje na řádku „Celkem s DPH" (brutto) místo „Celkem bez DPH". Jednotková cena (Cena/j) zůstává **bez DPH** (netto). Řádek je tak standardní a bez redundance dvou stejných netto čísel: *cena/j bez DPH + sazba + celkem s DPH*. Spodní rekapitulace (bez DPH / DPH / k úhradě) i běžný režim a PDF vydané faktury beze změny.

## [4.7.1] — 2026-05-31

Doladění režimu cen „s DPH": jednotková cena se všude zobrazuje jako skutečné netto a editor už nepřepíná režim faktury za zády uživatele.

### Changed

- **Zadání ceny „Celkem s DPH" už nepřepíná celou fakturu do režimu „ceny s DPH"** — v editoru vydaných i přijatých faktur se po vyplnění částky do sloupce „Celkem s DPH" respektuje **aktuální režim dokladu**: v běžném režimu se z brutto dopočítá jednotková cena **bez DPH** (odečtením DPH shora), v režimu „ceny s DPH" se uloží brutto jako dosud. Dřív se tím režim faktury automaticky zapínal, což bylo matoucí.

### Fixed

- **Jednotková cena „bez DPH" se v režimu cen s DPH zobrazovala jako brutto** — v tomto režimu nese pole `unit_price_without_vat` z technických důvodů cenu **s DPH** (aby DPH koeficientem seděla na haléř), takže se pod hlavičkou „Cena/MJ bez DPH" ukazovala částka s DPH. Nově se **všude** dopočítává a zobrazuje skutečné **netto** (z uloženého řádkového základu): detail vydané i přijaté faktury (desktop i mobil), **PDF** vydané i přijaté faktury, exporty **ISDOC** (`UnitPrice`/`UnitPriceTaxInclusive`) a **Pohoda XML** (`unitPrice`) i souhrn na detailu **pravidelné fakturace**. Daňové částky (základ, DPH, celkem) byly po celou dobu správné — šlo čistě o zobrazení jednotkové ceny; do přiznání DPH / kontrolního hlášení `unit_price_without_vat` nevstupuje (daň jede z uložených řádkových totálů).
- **Souhrn na detailu pravidelné fakturace v režimu cen s DPH** počítal základ a DPH zdola (jako by ceny byly bez DPH), takže přepočítával celkovou částku. Nově respektuje koeficient (shora), stejně jako generovaná faktura.

## [4.7.0] — 2026-05-31

Import faktur a účtenek z fotek, režim cen „s DPH" (brutto) napříč doklady a daňově korektní zacházení s dodavateli neplátci DPH.

### Added

- **Import faktur/účtenek z fotky (#75)** — do importu (drag&drop i nahrání) lze nově dát **obrázek** dokladu, ne jen PDF. Podporované formáty **JPG, PNG, WEBP a HEIC/HEIF** (fotky z mobilu) se na vstupu automaticky převedou na PDF (`ImageToPdfConverter`) a dál projdou stejnou AI extrakcí jako PDF. HEIC se zpracuje, pokud má prostředí Imagick; jinak appka srozumitelně poradí převést na JPG/PNG. Vše ostatní (rozpoznání dodavatele, položek, DPH) zůstává beze změny.
- **Režim cen „s DPH" (brutto) na dokladech** — u **vystavených i přijatých faktur** a u **šablon pravidelné fakturace** lze přepnout, že ceny položek jsou uvedené **včetně DPH** (účtenky, paragony, B2C). DPH se pak počítá „shora" koeficientovou metodou (§37 ZDP) a **celková částka sedí na haléř** (33 Kč s DPH @ 21 % → základ 27,27 / DPH 5,73, ne 32,9967). U více řádků stejné sazby se haléřové reziduum dorovná tak, aby součet daně přesně odpovídal dani z celkového brutto (KH i přiznání ukážou stejné číslo jako detail faktury). Přepínač lze **předvyplnit per dodavatel** (výchozí *Ceny s DPH* v nastavení) a v editoru se **automaticky zapne**, jakmile zadáš cenu do sloupce „Celkem s DPH". Výchozí stav i všechny existující doklady zůstávají v dosavadním režimu „zdola" (beze změny). AI import účtenek nově ukládá ceny tak, jak jsou na účtence (s DPH), a nastaví režim sám.
- **Dodavatel neplátce DPH → bez nároku na odpočet** — u dodavatelů se sleduje **plátcovství DPH** (autoritativně z ARES dle IČO, u zahraničních EU subjektů z VIES dle DIČ; online při výběru/editaci dodavatele, cache 24 h) a zobrazuje se i ve **výpisu klientů** (badge *Plátce DPH*) a v **editoru přijaté faktury** (volba pod *Reverse charge*). U **neplátce** se automaticky vynutí `vat_deduction='none'`, vynulují sazby a zobrazí varování — do přiznání DPH (ř. 40) ani kontrolního hlášení (sekce B) se tak nedostane neoprávněný odpočet z dokladu, na kterém žádná DPH není. Příznak lze v editoru vědomě přepsat.

### Fixed

- **AI import od neplátce nesprávně nárokoval odpočet DPH** — doklad od dodavatele neplátce (např. „DIČ: Neplátce DPH") se importoval s `vat_deduction='full'` a dostával se do ř. 40 přiznání. Nově se plátcovství ověří a u neplátce se odpočet automaticky zakáže.

### Upgrade

- **Zpětný backfill plátcovství dodavatelů** — po nasazení doporučeno jednorázově spustit `php api/bin/backfill-vendor-vat-payer.php`. Skript projde stávající dodavatele, podle ARES/VIES doplní `clients.is_vat_payer` a u **neplátců** opraví už zaevidované přijaté faktury (nastaví `vat_deduction='none'`, sazby na 0 %, základ = zaúčtovaná částka, **celková částka beze změny**) + přečísluje variabilní symboly. **Výchozí běh je dry-run** (jen náhled, nic nezapisuje) — zápis provede až s přepínačem `--apply`. Migrace `0083` a `0084` se aplikují přes `php api/bin/migrate.php`.

## [4.6.4] — 2026-05-30

Další automatické načítání údajů z veřejných registrů (ARES + registr plátců DPH), děkovný e-mail za úhradu faktury a drobné opravy.

### Added

- **Auto-nastavení typu poplatníka z ARES** — při načtení dodavatele z ARES (setup wizard i *Číselníky → Nový dodavatel*) se z právní formy automaticky odvodí **Typ poplatníka**: OSVČ (fyzická osoba) → **FO/DPFO**, firma (s.r.o./a.s./…) → **PO/DPPO**. Lze ručně přepsat v Nastavení.
- **Auto-doplnění EPO údajů z registrů při vytvoření dodavatele** — při založení dodavatele (setup i *Nový dodavatel*) se „na pozadí" (bez polí ve formuláři) doplní: z **ARES** číslo popisné/orientační, spisová značka a typ poplatníka; z **registru plátců DPH** kód finančního úřadu (autoritativní `cisloFu`, ne kód územního pracoviště). **CZ-NACE** jen pokud je jednoznačná (subjekt má jediný kód) — jinak prázdné, aby se do přiznání nedostala špatná převažující činnost. Doplní jen prázdná pole (nepřepisuje ruční vstup); výpadek registru vytvoření nezablokuje. ID datové schránky z ARES nelze (je v samostatném registru ISDS).
- **Děkovný e-mail za úhradu faktury (#57)** — po označení faktury jako uhrazené lze zákazníkovi poslat krátké poděkování. **Volitelné a ve výchozím stavu vypnuté** (per dodavatel: zapnutí, automatické odeslání při bankovním párování, předzaškrtnutí v ručním označení, volitelná příloha PDF). Funguje při **ručním** označení (checkbox v modalu), **hromadném** označení (volba + souhrn odesláno/selhalo) i **automaticky při spárování platby z banky**. Vlastní e-mailová šablona `invoice_payment_thanks` (CS/EN, editovatelná v *Admin → E-mailové šablony*) s variantou pro zálohu (proforma). Idempotentní (auto odeslání jen jednou), neposílá pro storno ani bez příjemce; vše v activity logu (`invoice.payment_thanks_sent/skipped/failed`). Selhání e-mailu nikdy nerozbije označení/párování.
- **Sample data — pravidelné fakturace** — generátor ukázkových dat (setup wizard i `bin/sample.php`) nově vytvoří i **2 pravidelné fakturace** (měsíční CZK hosting/údržba + čtvrtletní EUR reverse-charge retainer).

### Fixed

- **Dark theme — stav „Odesláno" zářil** — badge používal nepřemapovaný světlý odstín; nově má vlastní tlumenou tyrkysovou paletu (laděnou k zelené ikoně e-mailu, ale odlišitelnou od „Zaplaceno"). Sladěn i badge „Proforma".
- **„Načíst z ARES" u OSVČ hlásilo chybu (#76)** — chybějící spisová značka u fyzické osoby (OSVČ není v OR) se hlásila jako červená chyba. Nově se podle `taxpayer_type` u OSVČ zobrazí neutrální info, červená chyba zůstává jen tam, kde zápis v OR opravdu chybět nemá.

## [4.6.3] — 2026-05-30

Automatické načítání bankovního účtu a zápisu v obchodním rejstříku z veřejných registrů + drobná vylepšení a opravy.

### Added

- **Bankovní účet z DIČ (registr plátců DPH / CRPDPH)** — kdekoli zadáváš dodavatele lze účet načíst z oficiálního registru plátců DPH (MFČR) podle DIČ: v **setup wizardu**, v **Nastavení** (editor měny/účtu) i v **Číselníky → Nový dodavatel** tlačítkem „Načíst účet z registru DPH". Vrací zveřejněné účty (vč. IBAN) a zároveň hlídá příznak **nespolehlivého plátce**. Funguje jen pro zveřejněné účty plátců DPH (orientační předvyplnění). Výsledky se cachují 24 h.
- **Spisová značka (zápis v OR) z ARES** — u právnických osob se při načtení z ARES (podle IČ) automaticky doplní pole „Zápis v obchodním rejstříku" (např. „Spisová značka C 45039 vedená u Krajského soudu v Plzni") — v setup wizardu, Nastavení i u nového dodavatele. Tiskne se v patičce faktury.
- **Detaily plátce DPH u klienta/dodavatele** — v detailu klienta (pokud má DIČ) tlačítko „Detaily plátce DPH" na vyžádání zobrazí spolehlivost plátce a jeho zveřejněné bankovní účty (užitečné při ověření protistrany před platbou — ručení za DPH). Pouze informativní, nic se neukládá.
- **Přidání nového roku v Daňových konstantách** — *Číselníky → Daňové konstanty* mají tlačítko „Přidat rok": předvyplní hodnotami nejnovějšího roku, po úpravě a uložení vznikne override.

### Fixed

- **Dark theme — neviditelný text v přepínači roku** v Daňových konstantách (select neměl tmavé pozadí jako ostatní; doplněno `bg-surface`).
- **Docker — varování při startu** `docker-compose.production.yml` hlásil „variable is not set" pro `MYINVOICE_SMTP_*`; doplněny prázdné defaulty (`${VAR:-}`), chování beze změny.

### Docs

- Manuál — přečíslování kapitol: *Daňový optimalizátor* 25a → **26**, *Dokumenty* 26 → **27**.

## [4.6.2] — 2026-05-30

Daňový optimalizátor pro OSVČ (#68) a oprava ukládání nastavení podpisu PDF.

### Added

- **Daňový optimalizátor (OSVČ)** — nová stránka *Daně → Daňový optimalizátor* (jen pro OSVČ) pomáhá rozhodnout, který daňový režim se vyplatí (#68, #71). **Retrospektiva** uzavřeného roku porovná paušální daň vs standardní režim na reálném vyfakturovaném příjmu, s rozpadem *příjem → výdaje → základ → daň → pojistné → čistý příjem + efektivní sazba* a meziročním (YoY) srovnáním. **Predikce** běžícího roku projektuje příjem z tempa a hlídá limity (strop pásma, 2 M paušál/DPH, 2,54 M okamžitý plátce DPH) s radou „odlož fakturu do ledna". Výdaje lze zadat **paušálem (40/60/80 %)** nebo jako **skutečné** (daňová evidence); zohledněny slevy (poplatník, manžel/ka, děti vč. daňového bonusu) i sociální (55 %) a zdravotní (50 %) pojistné s ročními minimy a rozlišením hlavní/vedlejší činnosti. Na dashboardu má OSVČ widget **„čistý příjem"**, podklady jdou exportovat do **CSV**. Roční daňové konstanty jsou ověřené (Finanční správa / ČSSZ / VZP, k 5/2026) a admin je může upravit v *Číselníky → Daňové konstanty* bez nového nasazení. Engine je pokrytý unit testy. Jde o orientační pomůcku, ne daňové přiznání (manuál kap. 25a).
- **Typ poplatníka u Daně z příjmů** (`Daně → Daň z příjmů`) se nově odvozuje z dodavatele (OSVČ → DPFO, s.r.o. → DPPO) místo ručního přepínače; přidán CSV export podkladů.

### Fixed

- **Uložení nastavení podpisu PDF vracelo 500** — `PUT /api/settings/supplier` s vypnutým podpisem (`pdf_signing_enabled=false`) selhal na strict-mode MariaDB (`''` místo `0` na `tinyint` sloupci). `pdf_signing_enabled` doplněn do bool→int castu (#72, regrese 4.6.1).

## [4.6.1] — 2026-05-30

Elektronický podpis PDF faktur certifikátem (PAdES) a drobná vylepšení UX.

### Added

- **Podpis PDF faktur certifikátem (PAdES)** — volitelný elektronický podpis PDF vydaných faktur a výkazů víceprací certifikátem, zapínatelný **per dodavatel** (#44). Úroveň **PAdES-B**, volitelně **PAdES-T** s důvěryhodným časovým razítkem (RFC 3161 TSA, vč. HTTP Basic auth k TSA serveru). Implementováno čistě v PHP (`openssl_cms_sign` / CMS RFC 5652, PDF incremental update) bez nové composer závislosti — funguje i na Windows/IIS. V *Nastavení → Podpis PDF* se certifikát **P12/PFX** (vč. řetězce CA) nahrává dvoukrokově (vybrat soubor → heslo → nahrát; přepínač „Podepisovat PDF" je zamčený s upozorněním, dokud certifikát chybí); heslo se uloží šifrovaně přes `SecretEncryption`, soubor leží mimo web root (0600), volitelně TSA URL + přihlášení a důvod podpisu; zobrazí se metadata certu (CN, vydavatel, platnost, SHA-256 fingerprint). Podpis se aplikuje při generování PDF (download, e-mail, vystavení, ZIP export), ověřeno v Adobe Acrobat (platný, důvěra z EU Trusted Lists, vložené časové razítko). **Měkký fallback** — selhání podpisu (chybějící/expirovaný cert, výpadek TSA) fakturu nezablokuje, vygeneruje se nepodepsané PDF a událost se zaloguje. Cesta k certifikátu se ukládá nezávisle na umístění data-dir (přesun / Docker volume podpis nevypne) a audit (`signing.pdf_signed`) loguje skutečně dosaženou úroveň (PAdES-B/T). Veškerá správa i použití certifikátu se auditují do `activity_log` (`signing.cert_uploaded/removed`, `signing.pdf_signed`, `signing.failed`) bez úniku hesla/klíče.

### Changed

- **Tlačítko „Výkaz"** v přehledu i detailu faktury — zjednodušená podmínka zobrazení: nově se ukáže u **každého konceptu**, pokud má uživatel právo editace (`auth.canWrite`). Dříve bylo vázáno na workflow projekt / existující výkaz / pravidelnou šablonu; readonly role tlačítko nevidí.

## [4.6.0] — 2026-05-29

Kategorie tržeb (symetrie ke kategoriím nákladů) s rozpadem v CRM/Tržbách, přepočet všech měn na CZK v CRM dashboardu a sjednocené propojení souvisejících dokladů.

### Added

- **Kategorie tržeb** — nový číselník (Nastavení → Číselníky → Kategorie tržeb) symetrický ke kategoriím nákladů. Vydaná faktura má volbu kategorie tržby, výchozí kategorii lze přednastavit na **zákazníkovi** i na **zakázce** (zakázka má přednost před zákazníkem). Při nastavení/změně výchozí kategorie se doplní do všech existujících faktur daného zákazníka/zakázky, které kategorii nemají vyplněnou (backfill). Výchozí kategorie se aplikuje **konzistentně napříč všemi cestami vzniku faktury** — ruční zadání, importy (iDoklad, Fakturoid, ISDOC/ZIP), pravidelná fakturace i vyúčtování zálohy/proformy (tam se kategorie dědí ze zdrojového dokladu).
- **Rozpad tržeb po kategoriích** — tabulka v CRM dashboardu a koláčový graf na stránce Tržby (rolling 12 měsíců, přepočet na CZK).
- **CRM dashboard — volba „Vše (CZK)"** v přepínači měn: boxy Přehled (tento měsíc / od začátku roku) i měsíční graf sečtou všechny měny přepočtené na CZK. „Vše" je výchozí volbou, pokud má firma víc měn.
- **Propojení souvisejících dokladů — banner v detailu faktury.** U proformy odkaz na vystavený daňový doklad a u daňového dokladu zpět na zálohovou fakturu; sjednocený vzhled (fialový banner) i u přijatých faktur (zálohová ↔ vyúčtovací faktura).
- **Vestavěný cron v Docker image** — app kontejner volitelně spouští plánované úlohy sám (přepínač `MYINVOICE_ENABLE_CRON`, default zapnuto), takže základní Docker nasazení nevyžaduje externí scheduler. Crontab se generuje z `CronCatalog` (stejné úlohy i frekvence jako UI „Plánované úlohy", takže nechybí žádná úloha), úlohy běží jako `www-data` s logy v `${MYINVOICE_DATA_DIR}/log/cron`. Při více replikách app je nutné nastavit `MYINVOICE_ENABLE_CRON=0`, aby úlohy neběžely vícenásobně. (#64)
- **Tenký scrollbar laděný do palety** v postranním menu (reusable utilita `.scrollbar-slim`, light/dark aware). (#69)

### Fixed

- **CRM dashboard — nesmyslné částky u cizí měny.** Při výběru měny (např. USD), která za dané období neměla žádný doklad, dlaždice „Přehled" ukazovaly částku jiné měny (typicky CZK) pod cizím labelem (např. „579 481,93 USD"). Nově se u chybějících dat zobrazí 0 ve zvolené měně. Stejný mislabel opraven u rozpadu nákladů (je vždy v CZK).
- **Importy přijatých faktur nenastavovaly výchozí kategorii nákladů dodavatele** (AI extrakce, ISDOC, iDoklad, Fakturoid, bankovní párování) — doplňovalo se jen ručně v UI. Nově se výchozí kategorie nákladu aplikuje centrálně při zakládání přijaté faktury, takže ji dostanou všechny importní cesty.
- **Popisky u zálohových přijatých faktur** — jeden nadpis „Zálohová faktura" se používal pro oba směry vazby. Vyúčtovací faktura má nově odlišný nadpis „Vyúčtování zálohy".

### Changed

- **Pole „Kategorie tržby" na vydané faktuře** je nově výběr z číselníku (dříve volný text). Stávající textové hodnoty se při migraci převedly na kategorie.

## [4.5.4] — 2026-05-29

Oprava režimu přenesení daňové povinnosti (reverse charge) na vystavených fakturách.

### Fixed

- **Reverse charge – sazba na faktuře** ukazovala „DPH 0 %" místo nominální sazby. Nově se na RC faktuře zobrazí **nominální sazba (21 %) s daní 0 Kč** a automatická poznámka „Daň odvede zákazník". RC je nově jen hlavičkový příznak (položka drží svou sazbu, daň vynuluje příznak); volba „Reverse charge" zmizela z výběru sazby na řádku (dělá se zaškrtnutím RC).
- **Reverse charge – zařazení do DPH přiznání.** Tuzemský RC prodej se vykazoval na DPHDP3 ř.20 (dodání zboží do JČS) místo ř.25 (tuzemský režim přenesení §92). Klasifikace je nově **podle země odběratele**: tuzemský → ř.25 + KH A.1, zahraniční z EU → ř.20. (migrace `0072`)

## [4.5.3] — 2026-05-29

Server-side našeptávač klienta/dodavatele, oprava přepnutí typu nevystavené faktury a čitelný kalendář v tmavém režimu.

### Fixed

- **Přepnutí typu nevystavené faktury** (faktura ↔ proforma ↔ dobropis) se v editaci neuložilo — update vždy zachoval původní typ a `updateDraft` sloupec `invoice_type` neměnil. Nově lze typ u draftu změnit; vystavená faktura zůstává neměnná (číslo + auditní stopa).
- **Tmavý režim** — nativní kalendář u výběru data (a další nativní prvky) byl černý na tmavém pozadí; přidán `color-scheme: dark`.

### Changed

- **Výběr klienta / dodavatele ve fakturách** — našeptávač nově hledá **server-side přímo v databázi** (název / IČO / DIČ) místo filtrování jen prvních 50 načtených. Týká se nové i editované vydané faktury, přijaté faktury (dodavatelé) a pravidelné fakturace. Řeší případ, kdy klient za první stránkou nešel ve faktuře vybrat, a škáluje nad 200 klientů.
## [4.5.2] — 2026-05-29

Opravy u přijatých faktur uhrazených zálohou a přenačtení detailu faktury při prokliku.

### Fixed

- **„K úhradě" u přijaté faktury uhrazené zálohou** ukazovalo celou částku místo 0 (nula v JS propadala přes `||` na celkovou částku). Hodnota v datech (`amount_to_pay`, generated column) byla správná, chyba byla jen v zobrazení detailu.
- **Proklik mezi doklady nepřenačítal detail** (přijatá i vydaná faktura) — navigace `/…/:id → :id` recyklovala komponentu a `onMounted` se znovu nespustil; doplněn `watch` na změnu id.

### Changed

- **Seznam přijatých faktur** — sloupec „K úhradě" přejmenován na **„Celkem s DPH"** a řádky nově ukazují celkovou částku dokladu (dřív 0 u faktur uhrazených zálohou); řádky teď odpovídají měsíčnímu součtu.
- **Editor přijaté faktury** — přidáno editovatelné pole **„Uhrazená záloha"** s dopočtem „K úhradě".

## [4.5.1] — 2026-05-29

Přílohy přímo v editoru faktury, robustní predikce ročního obratu, vyšší kontrast tmavého režimu a vylepšení statistik.

### Added

- **Přílohy v editoru faktury** — přílohy lze přidat už při tvorbě **nové** faktury (drží se v prohlížeči a nahrají se hned po vytvoření) i přidávat/mazat u **existující** faktury přímo v editoru, nejen v detailu. Sekce je pod Výkazem víceprací. Limity 10 MiB/soubor, 20 MiB celkem.
- **Robustní predikce ročního obratu** — místo growth-adjusted seasonality nově **medián tří nezávislých projekcí** (run-rate, sezonalita × krátkodobý růst, sezonalita × dlouhodobý CAGR trend) + rozpětí min–max. Odolnější vůči zkreslení z krátkého YTD okna na začátku roku, kdy starý model přestřeloval. (#66)

### Changed

- **Tmavý režim** — vyšší kontrast tlumeného textu (popisky, placeholdery): `neutral-500` a `-400` zesvětleny, muted text z ~4.3 na ~5.5:1 (WCAG AA). Hlavní text beze změny (záměrně mírně odbílá, aby nezářil).
- **Statistiky** — dlaždice „Top klienti" a „Top zakázky" se při chybějících loňských datech zobrazí **vedle sebe** (jinak pod sebou); „Forecast" přejmenován na **„Predikce"**; bez loňského roku se místo růstu YoY ukáže run-rate poznámka (žádné „NaN").

## [4.5.0] — 2026-05-29

Tmavý režim (dark mode) s přepínačem **Systém / Světlý / Tmavý** a úpravy dashboardu.

### Added

- **Tmavý režim (dark mode)** — přepínač **Systém / Světlý / Tmavý** v horní liště, na mobilu v rozbalovacím menu vedle přepínače jazyka. Výchozí *Systém* sleduje nastavení operačního systému (`prefers-color-scheme`), ruční volba se ukládá do prohlížeče (per zařízení). Řešeno token-driven — třída `.dark` přepisuje hodnoty CSS proměnných, takže se přepne celá aplikace včetně grafů; při načtení nebliká. Světlý režim zůstává beze změny. (#65)

### Changed

- **Dashboard** — homepage zobrazuje jen **aktivní měny** (neaktivní měny se v přehledu tržeb už neukazují). Při jediné aktivní měně je graf tržeb vyšší (vlevo) a KPI boxy jsou v matici 2×2 vpravo; při více měnách beze změny. V sekci nákladů je box „CRM" nahrazen **mini grafem nákladů** za posledních 12 měsíců.

## [4.4.0] — 2026-05-29

Nová sekce **Dokumenty** (souborové úložiště), presety výchozí splatnosti a výchozí kategorie nákladu.

### Added

- **Sekce Dokumenty** — souborové úložiště s hybridní organizací: strom složek + vazby na entity (klient, vydaná/přijatá faktura, zakázka) + tagy + fulltextové hledání. Automatické rozbalení datových zpráv **ZFO** (PKCS#7, kompletní metadata ISDS) a **ZIP** (dvojí režim: rozbalit a kategorizovat / nahrát jako jeden archiv). Nahrávání jednotlivých souborů, celých složek (drag&drop i přes dialog) i velkých souborů po částech (obchází PHP `post_max_size`) — vše na pozadí přes joby s průběhem. Náhledy (první strana PDF / obrázky), inline PDF preview, koš (soft-delete + vysypání). Oboustranné vazby v detailu klienta, faktury i zakázky. (migrace `0067`–`0069`)
- **Hromadné akce nad Dokumenty** — výběr **souborů i složek současně** s hromadným exportem do ZIP (se zachováním stromové struktury), přesunem přes stromový picker a smazáním. Velikost složek přímo v dlaždici. Na mobilu se akce složky odkryjí dvojím ťuknutím (ochrana proti nechtěnému smazání).
- **Presety výchozí splatnosti** — v nastavení dodavatele, u klienta i u zakázky lze místo prostého počtu dnů zvolit `7 dnů / 14 dnů / Měsíc / Vlastní`. **Měsíc** je skutečný kalendářní měsíc (1. 2. → 1. 3., 31. 1. → 28. 2.), ne fixních 30 dnů. Klient i zakázka mohou dědit z dodavatele; v editoru faktury platí priorita zakázka → klient → dodavatel, každá úroveň s vlastní jednotkou. (#61, migrace `0070`, `0071`)
- **Výchozí kategorie nákladu** na dodavateli (firmě) s propagací do přijatých faktur, zobrazení kategorie v detailu přijaté faktury a filtr dodavatelů podle výchozí kategorie.
- **Plně ENV-konfigurovatelné SMTP** v Docker Compose + guard proti přepisu prázdnými ENV hodnotami. (#60)

### Fixed

- **Klasifikace plnění na řádcích vydané faktury** — `GET /api/invoices/{id}` nevracel `vat_classification_code` na položkách (jen na hlavičce), takže `GET → úprava → PUT` tiše zahodil ručně nastavenou klasifikaci řádku. (#62)
- **Zaplacená nespárovaná přijatá záloha** se nezapočítávala do nákladů (cash sémantika).

## [4.3.12] — 2026-05-28

Globální vyhledávání v postranním panelu.

### Added

- **Vyhledávací pole v sidebaru** (nad „Přehled") — našeptává **položky menu** (klientsky, skočí přímo na danou stránku) a od dvou znaků hledá v **klientech/dodavatelích** (název + e-mail) a ve **vydaných i přijatých fakturách** (číslo dokladu). Výsledky jsou seskupené (Menu / Klienti / Vydané / Přijaté), ovladatelné klávesnicí (↑/↓, Enter, Esc) a kliknutím otevřou detail. Hledání je scoped na aktuálního dodavatele (multi-tenant). (endpoint `GET /api/search`)

## [4.3.11] — 2026-05-28

Propojení přijatých záloh s vyúčtovací fakturou (proti dvojímu započtení nákladu) a dotažení daňového auditu výkazů DPH.

### Added

- **Propojení přijaté zálohy s finální fakturou** — v detailu přijaté faktury lze zálohovou fakturu (zálohu / proformu) spárovat s vyúčtovací fakturou od stejného dodavatele (*Zálohová faktura → Spárovat se zálohou*). Nabídka kandidátů řadí napřed zálohy ve stejné měně a s nejbližší částkou (porovnává hrubou částku před odečtem zálohy, ne částku k úhradě). Spárovaná — nebo už zaplacená — záloha přestane vstupovat do Nákladů, CRM statistik i daně z příjmů, takže se stejný náklad nepočítá dvakrát. (migrace `0064`, `0065`)
- **AI návrh propojení zálohy** — když AI extrakce přijaté faktury najde odkaz na zaplacenou zálohu („zaplaceno zálohou č. X"), dohledá odpovídající zálohu a v detailu ji nabídne k potvrzení (návrh, nic se nepáruje automaticky).

### Fixed

- **Přijatá zálohová faktura (proforma) ve výkazech DPH** — záloha (`advance`) není daňový doklad, přesto vstupovala do Knihy DPH, DPH přiznání, kontrolního i souhrnného hlášení. Nově je z DPH evidence vyloučená (symetricky k vystavené proformě), NULL-safe (legacy doklady bez vyplněného druhu zůstávají).
- **Samovyměření daně u dovozu služby/zboží** — kódy `24` (přijetí služby z EU / dovoz služby) a `25` (dovoz zboží ze 3. země) neměly příznak reverse-charge, takže se daň samovyměřila jen při ručním zaškrtnutí RC na dokladu. Nově se samovyměří z klasifikačního kódu (jako kódy 5/23), včetně zrcadlového odpočtu na ř. 43. (migrace `0063`)
- **„Bez nároku na odpočet" (kód 42) nárokoval odpočet** — kód byl chybně mapován na ř. 42 DPHDP3 (odpočet při dovozu přes celní úřad). Plnění bez nároku správně nevstupuje do žádného odpočtového řádku ani do KH / Knihy DPH.
- **Osvobozené tuzemské plnění (kód 3) korumpovalo ř. 3** — bylo mapováno na ř. 3 DPHDP3 (pořízení zboží z JČS, vstup), takže osvobozené vystavené plnění nadhodnocovalo pořízení z EU. Nově se do výkazu nezahrnuje (osvobozená plnění / koeficient § 76 se řeší ručně).
- **Daň z příjmů — zálohy v nákladech** — přijatá záloha (`advance`) se napevno vylučuje z uznatelných nákladů DPFO/DPPO (není daňový doklad; nákladem je až vyúčtovací faktura).

### Changed

- **DPH přiznání (DPHDP3) — rekapitulace (Veta6)** — generuje se i souhrnný oddíl ř. 62–66 (daň na výstupu, odpočet, vlastní daň / nadměrný odpočet), sčítaný ze zaokrouhlených řádků kvůli konzistenci s detailem (EPO).
- **Náklady / CRM — vyloučení spárovaných/zaplacených záloh** — nákladové souhrny, top dodavatelé a měsíční/roční přehledy nepočítají zálohy, které jsou zaplacené nebo spárované s finální fakturou; cashflow a závazky je ponechávají (nezaplacená záloha je reálný závazek).

## [4.3.10] — 2026-05-28

Zadávání částek s DPH na řádku faktury a oprava pádu Přehledu při neúplné odpovědi.

### Added

- **Zadání částky s DPH na řádku** (vydaná i přijatá faktura) — řádkové „Celkem s DPH" je nově editovatelné pole. Po zadání částky včetně DPH se zpětně dopočítá jednotková cena bez DPH (`částka / (1 + sazba) / množství`). Funguje i s výrazy (`1000*1.21`, desetinná čárka). U neplátce / reverse-charge je sazba 0.

### Fixed

- **Přehled (Dashboard) padal při neúplné odpovědi summary** (`TypeError … issued_count_ytd`) — přístup k `summary.kpi` je nově null-safe a stránka při chybové/neúplné odpovědi ukáže uvítací stav místo bílé obrazovky. Backendový souhrn navíc nespadne, pokud ještě neproběhla migrace `0062` (sloupec `flat_tax_band`).

## [4.3.9] — 2026-05-28

Sjednocení hlavičky PDF výkazu víceprací s fakturou.

### Changed

- **PDF výkazu víceprací — hlavička jako faktura:** logo už není přerostlé (omezeno na 72 × 20 mm jako u faktury) a respektuje **3 varianty** podle brandingu — bez brandingu textový název firmy / jen logo / logo + název (přepínač *Zobrazit i název firmy vedle loga*). Do výkazu se nově propisuje i **akcentová barva** brandingu.

### Added

- **Číslo projektu a smlouvy v PDF výkazu** — pokud jsou u zakázky vyplněny, zobrazí se v záhlaví výkazu (Vaše číslo / Vaše smlouva).

## [4.3.8] — 2026-05-28

Per-client číselné řady faktur, sledování paušální daně, konzistentní `MYINVOICE_DATA_DIR` a opravy importu z Fakturoidu.

### Added

- **Vlastní číselná řada faktury per klient** (*Klienti → detail klienta → Vlastní číslování faktur*) — volitelný formát čísla (vydaná / proforma / dobropis) a období counteru pro jednotlivé klienty; prázdné pole dědí nastavení dodavatele. Vhodné po migraci z Fakturoidu / iDokladu, kde měl každý klient vlastní řadu. Editor faktury ukazuje náhled čísla podle zvoleného klienta. Hlídá kolize formátů mezi klienty. (migrace `0061`, PR #55 — @Hermanik)
- **Sledování paušální daně (§ 7a)** — v *Nastavení* lze u neplátce DPH zvolit pásmo (1./2./3., limit příjmů 1 / 1,5 / 2 mil. Kč). Na stránce *Tržby* se pak zobrazí dlaždice s využitím ročního limitu příjmů zvoleného pásma (zaplacené příjmy v kalendářním roce), barevně dle blízkosti stropu. (migrace `0062`)
- **Oprava stavů importovaných faktur z Fakturoidu** — CLI nástroj `api/bin/fix-fakturoid-imported-statuses.php` dorovná u importovaných faktur stav (odesláno / zaplaceno / stornováno) podle Fakturoidu; idempotentní, neptřepisuje ručně změněné. (PR #54 — @Hermanik)

### Fixed

- **Import z Fakturoidu stahoval jen prvních 40 dokladů** — Fakturoid API v3 neposílá `Link` hlavičku pro stránkování (oproti dokumentaci); import nově projde všechny stránky. (PR #52 — @Hermanik)
- **`MYINVOICE_DATA_DIR` nebyl konzistentně respektován** (#53) — PDF (cache i archiv), přílohy faktur, loga dodavatelů, importovaná PDF, zálohy, reset i cronové logy se nově ukládají pod zvolený data-dir (centrální resolver `RuntimePaths`). Dříve část souborů končila v adresáři aplikace i při nastaveném `MYINVOICE_DATA_DIR` (problém pro Docker single-volume / read-only root). Relativní cesty v DB zůstávají kompatibilní.
- **Nastavení: zapnutí „Plátce DPH" vynuluje pásmo paušální daně** — paušál je neslučitelný s plátcovstvím DPH (§ 7a), uložení kombinace dříve skončilo chybou.
- **CRM: nadpis „Přehled" zasahoval do KPI karet** — opraveno odsazení.

## [4.3.7] — 2026-05-28

Daňový audit (opravy DPH/KH/SHV výkazů a daně z příjmů), zpřehlednění CRM, samočinné odblokování aktualizace a řádkové „Celkem s DPH" u vydaných faktur.

### Fixed

- **Souhrnné hlášení — kód plnění:** poskytnutí služby do EU (`§ 9/1`) se hlásilo s chybným kódem plnění `2` (třístranný obchod) místo správného **`3`**. Opraveno dle DPHSHV XSD (`0` zboží, `2` třístranný, `3` služba).
- **Daň z příjmů — vypadávání nákladů:** přijaté faktury **bez vyplněného DUZP** (`tax_date` NULL) vypadávaly z nákladů DPFO/DPPO (`GREATEST(NULL, …) = NULL`). Opraveno na NULL-safe variantu.
- **Kontrolní hlášení — poměrný odpočet (§ 75):** u dokladů s poměrným odpočtem nad 10 000 Kč se v sekci B.2 nově nastavuje atribut `pomer='A'` (dříve napevno `'A'`/`'N'` nekonzistentně).
- **Náklady — DPH na vstupu = nárok na odpočet:** graf „Rozpad DPH na vstupu" nově vyřazuje faktury bez nároku a krátí poměrný odpočet, takže odpovídá Knize DPH / DPHDP3 (ř. 40/41). Přejmenováno na „Nárok na odpočet DPH podle sazby".
- **Aktualizace se zasekávala na „Upgrade probíhá…":** stav se nyní sám odblokuje, pokud je cílová verze už nasazená (např. update přes terminál) nebo příznak vypršel (watcher neběží). Přidáno tlačítko **„Zrušit / odblokovat"**.

### Added

- **Třístranný obchod (§ 17):** nové klasifikační kódy `30` (pořízení prostřední osobou → DPHDP3 ř. 30) a `31` (dodání prostřední osobou → ř. 31 + souhrnné hlášení kód 2). DPHDP3 nově generuje oddíl C (Veta3). (migrace `0060`)
- **Registrace k DPH dle novely 2025:** indikátor obratu na stránce *Tržby* nově testuje **kalendářní rok** se dvěma prahy — `2 000 000 Kč` (plátcem od 1. 1. následujícího roku) a `2 536 500 Kč` (plátcem ze zákona ihned). Dříve klamavě „plovoucích 12 měsíců".
- **Náklady — sjednocení období:** náklady se řadí dle pozdějšího z DUZP / vystavení (shodně se základem daně z příjmů).

### Changed

- **CRM dashboard — přehlednější filtr období:** přepínač období přesunut z horní lišty k analytické části, kterou skutečně řídí; KPI karty nahoře jsou označené jako „tento měsíc / od začátku roku" (na období nereagují) a sekce řízené obdobím nesou štítek `(N m)`.
- **Vydané faktury — řádkové „Celkem s DPH":** u plátce DPH řádkové „Celkem" nově zobrazuje částku včetně DPH (mění se podle zvolené sazby), aby byl efekt sazby DPH viditelný; rozpad bez DPH / DPH zůstává v souhrnu.

## [4.3.6] — 2026-05-28

Nová sekce **Náklady** ve Financích (statistiky a analýzy přijatých faktur, obdoba Tržeb) a rozšíření CRM o závazkové metriky.

### Added

- **Náklady** (*Finance → Náklady*) — přehled a analýzy nad přijatými fakturami, zrcadlí sekci *Tržby* pro stranu nákladů: plovoucí 12měsíční náklady, náklady letos/loni s meziročním srovnáním, odhad nákladů za aktuální rok (sezonalita + meziroční změna), počet přijatých faktur, aktivní dodavatelé, Ø doba úhrady dodavatelům, náklady za posledních 30 dní a nezaplacené závazky. Dále měsíční náklady (graf + loňská řada), kumulativní platby dodavatelům, top dodavatelé (koláč i tabulka za 12 měsíců), rozpad stavů přijatých faktur, rozpad DPH na vstupu podle sazby, náklady po rocích a měsících, rozpad nákladů podle kategorií, **riziko koncentrace dodavatelů**, distribuce doby úhrady, **plán plateb dodavatelům** (splatné závazky do 30 / 60 / 90 dní), aging závazků a distribuce velikosti přijatých faktur. Respektuje plátcovství DPH (náklad bez / s DPH) a řadí podle pozdějšího z dat DUZP / vystavení.
- **CRM — závazkové metriky:** **DPO** (průměrná doba úhrady dodavatelům, protějšek DSO), **koncentrace dodavatelů** (závislost na top dodavatelích) a **pracovní kapitálový cyklus** (DSO − DPO — zda financujete provoz, nebo vás financují dodavatelé).

## [4.3.5] — 2026-05-27

Měsíční daňový export do jednoho ZIPu, oprava zařazení přijatých faktur do období DPH a doladění filtrů v seznamech.

### Added

- **Měsíční export** (*Daně → Měsíční export*) — stáhne jeden ZIP za zvolený měsíc s vystavenými i přijatými fakturami (PDF + ISDOC), bankovními výpisy (PDF + GPC) a Knihou DPH, roztříděné do pojmenovaných složek. U přijatých faktur bez originálního PDF se přiloží naše rekonstrukce. Vybíráte zaškrtnutím, u každé části vidíte počet dostupných dokladů. Běží jako **úloha na pozadí** (průběh, stažení výsledku, historie posledních exportů, automatický úklid po 7 dnech) — nespadne na timeout u velkého počtu faktur. (migrace `0059`)
- **Filtr dodavatele** v seznamu přijatých faktur (za filtrem typu dokladu).

### Changed

- **Zařazení přijatých faktur do období DPH** (přiznání, kontrolní hlášení, kniha DPH) nově respektuje **pozdější z dat DUZP / vystavení** — nárok na odpočet nelze uplatnit dříve, než plátce drží daňový doklad (§ 73 ZDPH). Vystavené faktury se nadále řadí podle DUZP (daň na výstupu). Dříve se přijaté řadily jen podle DUZP, což u faktury se zpětným DUZP vystavené v pozdějším měsíci zařadilo odpočet do nesprávného (dřívějšího) období.

### Fixed

- Filtry „Všichni klienti" v seznamech **zakázek** a **faktur** nabízely i dodavatele bez vystavených faktur — nově nabízejí jen zákazníky.

## [4.3.4] — 2026-05-27

Doladění brandingu faktur a e-mailů (issue #43): akcentová barva se propisuje konzistentně i do dosud napevno fialových prvků a přibyl přepínač pro zobrazení názvu firmy vedle loga v PDF.

### Added

- **Přepínač „Zobrazit i název firmy vedle loga"** (*Nastavení → Branding*, výchozí vypnuto, aktivní jen když je nahrané logo). Vhodné pro malá/symbolová loga bez názvu — vedle loga se v PDF faktuře vykreslí obchodní (nebo firemní) název. Varianty „jen logo" a „jen nadpis" zůstávají beze změny. (migrace `0058`)
- **Světlé pozadí e-mailu dle akcentu** — hlavička i box s částkou v odchozích e-mailech (odeslání, schválení) přebírají světlou variantu akcentové barvy dodavatele (mix s bílou); bez brandingu zůstává původní odstín.

### Changed

- **Akcentová barva v PDF se propisuje i do světlých ploch a linek** — dosud napevno fialové prvky (pilulka ISDOC, podbarvení „K úhradě", tenké linky mezisoučtů/banky/QR) i levý okraj poznámek nyní respektují zvolený akcent. Sémantické barvy (dobropis červená, storno šedá, reverse-charge amber, „uhrazeno" zelená, šedý CZK přepočet) zůstávají záměrně neutrální.

### Fixed

- **Levý okraj poznámek v PDF** nedržel akcentovou barvu (zůstával fialový i při vlastním brandingu).

## [4.3.3] — 2026-05-27

Daňové uplatnění u přijatých faktur (nárok na odpočet DPH + daňová uznatelnost) a interní číslování přijatých faktur dle daňového typu dokladu.

### Added

- **Nárok na odpočet DPH** u přijaté faktury (`vat_deduction`): **Plný** / **Bez nároku** / **Krácený (§75)**.
  - *Bez nároku* — faktura **nevstupuje** do Knihy DPH ani do DPHDP3 / KH (jen účetní náklad — typicky reprezentace, osobní spotřeba).
  - *Krácený (§75)* — zadáš **Odpočet %**, o které se zkrátí základ i daň odpočtu (např. auto 70 % pro ekonomickou činnost); zbytek je nedaňová část.
- **Daňová uznatelnost nákladu** (`tax_deductible`) — nezávislý příznak; neuznatelný náklad se nezahrne do daně z příjmů (DPFO/DPPO). Na DPH nemá vliv (pojmy jsou ortogonální).
- **Interní číslo přijaté faktury dle daňového typu** — formát `{PP}{YYMM}{CCC}` (např. `PF2602001`). Prefix: **PF/PN** plný nárok (uznatelný/neuznatelný), **KU/KN** krácený, **NU/NN** bez nároku. Počítadlo je per měsíc (nad 999 dokladů přirozeně přeteče na 4+ místa).
- Oba příznaky jsou vidět v editoru i v detailu přijaté faktury.

### Changed

- **Změna daňového uplatnění** u už očíslované přijaté faktury **přepíše prefix** interního čísla na odpovídající typ (`PF2602001` → `NN2602001`); číselnou řadu i ručně zadaná čísla zachová.

## [4.3.2] — 2026-05-27

Řazení seznamu pravidelných faktur, číslo účtu v názvu staženého PDF výpisu a aktualizace OpenAPI specifikace.

### Added

- **Řazení seznamu pravidelných faktur** — vpravo za součtem částek nový přepínač: dle **data vystavení** (výchozí), dle **zákazníka (A–Z)** nebo dle **částky přepočtené na CZK (sestupně)**. Řazení i přepočet na CZK (dnešní kurz ČNB) probíhá server-side, takže funguje napříč stránkováním a **nemixuje měny** (1000 EUR > 20 000 CZK).
- **Číslo účtu v názvu staženého PDF bankovního výpisu** — pokud název nahraného PDF číslo účtu neobsahuje, předřadí se (např. `2026-02.pdf` → `1000000005-2026-02.pdf`), ať se výpisy z více účtů nepletou.

### Changed

- **Seznam pravidelných faktur** defaultně filtruje stav **„Aktivní"** (dřív „Vše").
- **OpenAPI specifikace** (`/api/openapi.yaml`) — opraveny názvy polí výkazu víceprací (`work_date`, `total_amount` místo neexistujících `performed_on`/`amount`), doplněno `discount_percent` u faktur i pravidelných fakturací, a chybějící pole pravidelných fakturací (`draft_open_mode`, `tax_date_mode`, `reminder_days_before`, `last_error`). Doplněna i poznámka, že výkaz víceprací je samostatný od položek faktury.

## [4.3.1] — 2026-05-27

Branding barva napříč PDF i e-maily, proklikávací výkaz víceprací v PDF a opravy QR v e-mailech a importu slev z iDokladu.

### Added

- **Proklikávací výkaz víceprací v PDF** — má-li faktura výkaz víceprací, položka „Výkaz víceprací" v tabulce položek je **podtržený odkaz**, který v PDF přeskočí přímo na stránku s výkazem (interní link). Ostatní položky i slevové řádky zůstávají běžným textem.

### Fixed

- **QR kód se nezobrazoval v odeslaných e-mailech** ([#51](https://github.com/radekhulan/myinvoice/issues/51)) — QR se vkládal jako `data:` URI v `<img>`, což Gmail, Outlook a další klienti z bezpečnostních důvodů blokují (na faktuře v PDF fungoval). Nově se vkládá jako **inline CID příloha** (stejně jako logo dodavatele), takže se zobrazí napříč klienty. Týká se odeslání faktury, testovacího e-mailu i upomínek.
- **Import slevy z iDokladu přes PDF s embedded ISDOC** ([#48](https://github.com/radekhulan/myinvoice/issues/48)) — oprava ve 4.3.0 řešila jen import přes iDoklad API; přes PDF s embedded ISDOC se sleva pořád ignorovala a faktura se naimportovala za plnou (předslevovou) cenu. ISDOC nemá na řádce dedikovaný discount element — sleva se promítá tím, že `LineExtensionAmount` (čistý součet řádku po slevě) je nižší než `UnitPrice × množství`. Parser teď čte efektivní cenu z `LineExtensionAmount`. Týká se vydaných i přijatých faktur.
- **Branding barva dodavatele se nepromítala do PDF faktury** — `email_accent_color` se používala jen v e-mailech, PDF bylo vždy fialové (ani `regenerate` nepomohl, barva se do PDF vůbec nevkládala). Nově se akcent barva aplikuje na akcenty PDF (linka pod hlavičkou, hlavička tabulky položek, řádky „Celkem" / „K úhradě", labely, popisky QR/banky, nadpis a odkaz výkazu) — gated na zapnutý branding. Sémantické barvy (dobropis, storno, „po splatnosti") zůstávají.
- **Branding barva se nepromítala do těla e-mailů** — velké částky, tlačítka a odkazy v těle byly natvrdo fialové. Nově používají akcent barvu dodavatele (gated na branding). Zelené tlačítko „Schválit vícepráce", „Uhrazeno" a oranžová „po splatnosti" zůstávají sémantické.

### Changed

- **Náhled e-mailu** v nastavení brandingu nově ukazuje akcent barvu i v těle (box „K úhradě" + tlačítko „Zobrazit fakturu"), ne jen v hlavičce a patičce — náhled tak věrněji odpovídá reálnému e-mailu.

## [4.3.0] — 2026-05-26

Procentuální **sleva na celou fakturu** + oprava importu slev z iDokladu, rozšíření na **pravidelné fakturace** a robustnější generování konceptů.

### Added

- **Procentuální sleva z celkové částky faktury** ([#50](https://github.com/radekhulan/myinvoice/issues/50)) — u vydaných faktur lze zadat slevu v % na úrovni celého dokladu (pole „Sleva z celé faktury"). Sleva se při uložení materializuje jako **záporná položka „Sleva X %"** — účetně nejčistší varianta: rozpadne se **na každou sazbu DPH zvlášť** (správné DPH i u smíšených sazeb) a díky tomu se automaticky promítne do totals, rozpisu DPH i do výkazů (Kniha DPH / EPO DPHDP3 / Kontrolní hlášení). Sleva je vidět v editoru, detailu i v PDF. Přenáší se i při klonování a při vystavení finální faktury k proformě.
- **Sleva i u pravidelných fakturací** — šablona drží `discount_percent`; vygenerovaná faktura ho zdědí a slevová položka se dopočítá stejně. Sleva je vidět ve formuláři i na detailu šablony.
- **„Vygenerovat koncept"** u pravidelné fakturace — vedle „Vygenerovat teď" (které respektuje `auto_issue`) lze vytvořit koncept ručně i u šablony s automatickým vystavením. U režimu „koncept na začátku období" volá stejnou idempotentní cestu jako cron (neposouvá rozvrh).

### Fixed

- **Import slevy z iDokladu** ([#48](https://github.com/radekhulan/myinvoice/issues/48)) — faktury (vydané i přijaté) se slevou se importovaly za plnou, předslevovou částku, protože se pole slevy z iDoklad API vůbec nečetlo. Nově se mapuje sleva na úrovni dokladu (`DiscountType=OnDocument`) i položková sleva: u vydaných na `discount_percent`, u přijatých jako záporná položka „Sleva X %" po sazbách. Importovaná částka teď odpovídá iDokladu.
- **Chybné DPH (0 %) u pravidelných fakturací** — generátor vkládal položky mimo standardní cestu a ukládal `vat_rate_snapshot = 0`, takže vygenerovaná faktura měla nulové DPH a prázdnou klasifikaci pro výkazy. Generátor teď používá kanonickou cestu (správná sazba z `vat_rates` + klasifikační kód).
- **Rychlá editace výkazu** v seznamu faktur (tlačítko „Výkaz") u faktury se slevou — vynulovávala slevu a zamrazila slevovou položku. Modal teď slevu zachová.

### Changed

- **Tlačítko „Výkaz"** v seznamu faktur je nově dostupné i pro koncepty vygenerované z pravidelné fakturace (dřív jen když už výkaz existoval nebo to vyžadoval projekt).
- **Ochrana proti vypršelé sazbě DPH** — při generování pravidelné faktury i při klonování se ověří, že přišpendlené sazby jsou platné k datu plnění. Po změně sazby (nový řádek `vat_rates` + `valid_to` na starém) se tak nevystaví doklad se starou sazbou; místo toho přijde jasná chyba.
- **Banner o selhání generování** na detailu/v seznamu pravidelné fakturace — když cron fakturu nevygeneruje (např. kvůli vypršelé sazbě), uloží se poslední chyba a uživatel ji uvidí přímo u šablony (dřív jen v admin logu jako počet).

## [4.2.5] — 2026-05-25

Oprava souhrnu v **Knize DPH**.

### Fixed

- **Souhrn v Knize DPH sčítal uskutečněná i přijatá plnění dohromady** — celková karta dole pod žurnálem sečetla základ a DPH vystavených (daň na výstupu) i přijatých dokladů (odpočet na vstupu) do jednoho čísla, což účetně nedávalo smysl. Souhrn je nově rozdělený na **uskutečněná plnění** (daň na výstupu) a **přijatá plnění** (odpočet na vstupu) zvlášť, plus řádek **výsledná DPH** (na výstupu − odpočet) s rozlišením vlastní daňová povinnost / nadměrný odpočet. Secondary sekce (ř. 43 reverse charge mirror, ř. 47 majetek) se do souhrnů nezapočítávají, jako dřív. PDF nebylo dotčeno — to grand totals nikdy nerenderovalo, jen subtotaly per sekci.

## [4.2.4] — 2026-05-25

Opravy importu/exportu (**reverse charge**) a **zálohování na Docker/PaaS**.

### Fixed

- **Reverse charge u importu z iDokladu** ([#41](https://github.com/radekhulan/myinvoice/issues/41)) — faktura od neplátce DPH se chybně označovala jako reverse charge. ISDOC `<VATApplicable>false</>` znamená *neplátce / plnění mimo DPH*, ne přenesenou daň. povinnost. Reverse charge se teď čte výhradně z `<LocalReverseChargeFlag>`.
  - Stejný typ chyby opraven i v **Pohoda importu** (RC z `<inv:classificationVAT>` PDP kódu, ne z `<inv:isExecuted>`), v **ISDOC exportu** (`VATApplicable` dle plátcovství dodavatele + reverse charge přes `<LocalReverseChargeFlag>`) a **Pohoda exportu** (zrušeno zneužití `<inv:isExecuted>`).
- **Záloha DB selhávala `rc=2` na Docker/PaaS** ([#42](https://github.com/radekhulan/myinvoice/issues/42)) — `cron-backup` měl `errFile` a fallback `.dump.cnf` natvrdo v `rootDir/storage/backup`, který při `MYINVOICE_DATA_DIR` mimo app kořen neexistuje → shell redirect selhal ještě před spuštěním `mariadb-dump`. Obojí teď míří do vyřešeného výstupního adresáře zálohy (zbytek po [#34](https://github.com/radekhulan/myinvoice/issues/34)).

## [4.2.3] — 2026-05-25

Rozšíření role **readonly** + bezpečnostní hardening po hloubkovém auditu.

### Changed (role readonly)

- **`readonly` vidí a exportuje totéž co `accountant`.** Nově má přístup
  k exportům (PDF / ISDOC / Pohoda / ZIP) i k daňovým výkazům (DPH, KH, SHV,
  daň z příjmů, kniha DPH, archiv EPO) — náhled i stažení XML/PDF. Vše jsou
  operace čtení; `readonly` dál **nic nevytvoří, neupraví ani nesmaže**. Rozdíl
  mezi `accountant` a `readonly` je tak jediný: zápis.
- **UI skrývá zápisová tlačítka podle role** (Nový / Upravit / Smazat i akce
  jako odeslat, zaplaceno, párování banky). Zápisové stránky (`/…/new`,
  `/…/edit`) jsou navíc chráněné route-guardem — `readonly` je z nich
  přesměrován na nástěnku.

### Security

- **XSS guard** v náhledu release notes (Systém → Aktualizace) — vlastní
  markdown renderer u odkazů povolí jen bezpečná schémata (`http(s)`,
  `mailto:`, `/`, `#`); `javascript:` / `data:` zahodí a vykreslí jako text.
- **`/api/health`** už nevrací pole `env` (drobný information disclosure).
- **Konverze loga** (rsvg-convert) používá `escapeshellarg` i pro cestu
  k binárce (robustnost cest s mezerami; nešlo o exploit).

### Docs

- Přepsaná specifikace rolí v manuálu — § 19.2.2 (uživatelská tabulka) a
  § 20.5 RBAC (úplná matice oprávnění + jak je RBAC vynucené: backend / PAT / UI).

## [4.2.2] — 2026-05-25

Chytřejší **párování plateb** v bance a oprava **importu z iDokladu**.

### Added (banka)

- **Návrhy ke spárování dle částky.** Když transakce nemá VS (nebo nesedí),
  dialog „Spárovat" nabídne faktury odpovídající částkou v okně **±14 dní** —
  vydané i přijaté (dobropisy obracejí směr platby). Klik = spárováno; ruční
  zadání VS zůstává jako druhá možnost.
- **Párování v cizí měně přes kurz.** Tuzemská (CZK) platba cizoměnové faktury
  (EUR/USD) se porovnává přes kurz faktury (CZK = částka × kurz) s relativní
  tolerancí 4 % (bankovní spread + drift kurzu). Platí pro automatické i ruční
  párování. Cizoměnový účet × jiná měna faktury se bezpečně nepáruje.
- **Spárování i s už zaplacenou fakturou** (duplicitní/druhá platba, doplatek,
  dodatečné dohledání úhrady) — návrhy zahrnují i zaplacené doklady se štítkem
  „Zaplaceno"; faktura se jen naváže (stav se nepřepisuje).

### Fixed

- **Import faktury z iDokladu** (#39) — iDoklad embeduje do PDF ISDOC XML
  s vedoucím UTF-8 BOM, což rozbíjelo detekci formátu (`str_starts_with('<?xml')`,
  kterou `ltrim` neočistí) a soubor padal na Pohoda parser s chybou
  `Není Pohoda XML — root není dataPack`. Detekce teď rozpozná ISDOC podle
  namespace nezávisle na BOM a vedoucí BOM odstraní.

## [4.2.1] — 2026-05-25

Patch zaměřený na **ISDOC v cizí měně** — export i import teď odpovídají standardu
ISDOC 6.0.2, ověřeno proti oficiálnímu XSD. Plus přejmenování vydaných faktur a
migrační nástroj z Money S3.

### Fixed

- **ISDOC export cizoměnových faktur byl nekonformní se standardem.** Base částky
  (`UnitPrice`, `LineExtensionAmount`, `PayableAmount` …) se zapisovaly v měně
  faktury a sourozenecké `*Curr` elementy chyběly úplně. Dle ISDOC jsou base částky
  vždy v `LocalCurrencyCode` (CZK) a cizoměnové hodnoty patří do `*Curr`. Konformní
  účetní software tak u např. EUR faktury interpretoval částku špatně (o kurz).
  Export teď generuje base v CZK (× kurz) + `*Curr` v měně faktury, ve správném
  pořadí dle XSD. **CZK faktury zůstávají beze změny.**
- **ISDOC import z cizích systémů** (#38) — jednotková cena cizoměnových faktur se
  čte z `LineExtensionAmountCurr` / množství, ne z `<UnitPrice>` (který je dle
  standardu vždy v lokální měně). Dříve se u EUR faktury importovala CZK hodnota.
- **Rekontrola AI extrakce** — `PdfTotalExtractor` četl z embedded ISDOC base (CZK)
  total a porovnával s `total_with_vat` v měně faktury → falešné varování u
  cizoměnových dokladů. Teď čte `*Curr`. Opraven i čtený element na `PayableAmount`
  (dřív se hledal neexistující `PayableRoundedAmount`).
- **Admin** — doplněn překlad `login_otp`; sazby DPH seřazené (platné tučně nahoře).

### Added

- **XSD validace ISDOC.** Commitnuto oficiální `isdoc-invoice-6.0.2.xsd` do
  `api/xsd/`; `XmlSchemaValidator` nově umí `isdoc`. Testy: unit (syntetická data
  + round-trip přes parser) i integrace (reálné faktury → XSD). `cmd/download-xsd.{cmd,sh}`
  umí stáhnout i ISDOC schema (token `isdoc`).
- **Migrační skript Money S3 → MyInvoice** přes REST API v1 (`tools/money-s3-import`;
  vyloučen z produkčních artefaktů).

### Changed

- **„Faktury" přejmenovány na „Vydané faktury"** v menu i v nadpisu seznamu —
  odlišení od přijatých faktur. Anglicky „Issued invoices".

## [4.2.0] — 2026-05-24

Velký krok u **daňových výkazů** a **banky**. Jádrem je nový `VatLedgerService` —
jeden kanonický producent VAT řádků, ze kterého teď projektují **všechny** výkazy
(DPHDP3, KH, Souhrnné hlášení, Kniha DPH) i KPI boxy a predikce. Sjednocení
odhalilo a opravilo několik daňových chyb. Banka umí založit přijatou fakturu
přímo z výpisu a chytřeji páruje platby kartou.

### Added (banka)

- **Doklad o úhradě z výpisu.** U odchozí (záporné) platby tlačítko **Vytvořit
  fakturu** → vybereš existujícího dodavatele (nebo založíš nového) → vznikne
  koncept přijaté faktury v hrubé částce a otevře se v editoru. Platba se
  automaticky sváže s konceptem. VS → pole VS, číslo dokladu dočasně `BANK-{id}`.
- **Chytřejší párování plateb kartou.** Platby bez VS se zkusí spárovat na
  přijatou fakturu i podle **částky + podobnosti názvu** dodavatele (Jaccard
  token overlap, normalizace, odstranění právních forem/lokalit). Spáruje jen
  při jednoznačné shodě.
- **Tlačítko „Otevřít"** u spárované transakce — skok na navázanou fakturu
  (vydanou i přijatou).
- **Bohatší GPC parsing.** Z advice řádků (078/079) se vytáhne **název
  protistrany** u plateb kartou + doplňující info do popisu.
- **Seskupení výpisů po měsících** v přehledu.

### Added (daňové výkazy)

- **`VatLedgerService`** — kanonický per-(doklad, sazba) producent VAT řádků
  sdílený všemi výkazy. Reverse-charge samovyměření, CZK přepočet, klasifikace
  s per-tenant override (override vyhrává nad globálním).
- **Sestavitel přiznání — samostatné příjmení.** Nové pole `sest_prijmeni`
  (sjednoceno s jednatelem `opr_*`). Když je prázdné, builder odvodí příjmení
  splitem `sest_jmeno` (zpětná kompatibilita). Migrace `0050`.
- **Historické snížené sazby DPH 15 % / 10 %** (issue #36) — seed `vat_rates`
  pro doklady před 2024 (periodika, knihy apod.). Migrace `0049`.
- **DPHDP3 oddíl C** (ř. 20–26) — `Veta2` lineMap.

### Fixed (daňové výkazy)

- **#35 — zařazení dokladů do sekcí KH a řádků DPHDP3.** Pořízení z JČS se už
  nezdvojuje v B.2; dobropisy nepadají chybně do sumace; sjednotné DIČ→sumace;
  prahy přes `abs()`; přeskok řádků s nulovým základem.
- **Souhrnné hlášení — chybějící přepočet kurzu.** SH teď projektuje z ledgeru
  (základ v CZK), takže cizoměnové dodávky do EU mají správnou částku.
- **Kniha DPH** — samovyměření RC i podle `is_reverse_charge` (konzistence s
  DPHDP3), oprava NULL bugu u přijatých dokladů s nevyplněnou hlavičkovou
  klasifikací (dříve se ticho vynechaly).
- **Kvartální DPHDP3 export** má v názvu `Q1–Q4` místo měsíce.

### Fixed (import)

- **Worker se nikdy nespouštěl** — sjednocení s funkčním cron mechanismem, cesta
  z `Bootstrap::rootDir()`, oprava běhu pod IIS a zaseknutých jobů.
- **`vat_rates` platnost** přes `valid_from`/`valid_to` místo neexistujícího
  `is_active` (Fakturoid import).
- **Fakturoid** — stahování příloh (parita s iDokladem).
- Ruční **smazání import jobu** z UI.

### Fixed (UI)

- **Editor přijaté faktury** — dvě 0% sazby (osvobozeno vs. přenesená DPH) se
  odliší popiskem, stejně jako u vydané faktury (dřív obě „0%“).

### Changed

- KH, Kniha DPH, KPI boxy a DPH trend přepsány na projekci z `VatLedgerService`
  (sloučení 3 samostatných builderů, odstranění mrtvého kódu).
- Deterministické řazení ledger řádků (`ORDER BY` datum, id, položka).

### Tests

- Integrační pokrytí všech daňových případů KH/DPH; zafixovaná Kniha DPH;
  EPO XSD validace VetaP polí (vč. `sest_prijmeni`). Lifecycle test importu
  (reapStale / cancel / delete). `Connection::close()` proti kumulaci spojení.
  Celkem **381 testů** zelených.

### Docs

- Manuál: nová sekce 13.4.3 (přijatá faktura z výpisu) + tip ke kartám;
  sekce 24 doplněna o sestavitele a pravidla generování DPHDP3/KH.

## [4.1.3] — 2026-05-24

Volitelné **e-mailové OTP** jako druhý faktor pro uživatele bez authenticator
aplikace (typicky externí účetní). Opt-in, výchozí stav vypnuto — žádný dopad
na existující instalace.

### Added (2FA — e-mailové ověření)

- **E-mailové OTP fallback.** Kdo nemá aktivní TOTP, dostane po ověření hesla
  6místný kód na e-mail a musí ho opsat. Řízeno `cfg.auth.email_otp.enabled`
  (default `false`). TOTP má přednost — e-mailový kód je čistě fallback pro
  uživatele bez authenticator appky.
- **„Zapamatovat toto zařízení na 30 dní"** — volitelná cookie důvěryhodného
  zařízení (`trusted_devices`), na kterém se druhý faktor po danou dobu
  nevyžaduje. Heslo se vyžaduje vždy. Doba dle `trusted_device_days`.
- **Tlačítko „Kód nedorazil? Odeslat znovu"** s odpočtem (anti-spam cooldown,
  default 60 s). Kód platí 10 min, je jednorázový a v DB jen jako sha256 hash
  (`login_otps`), nikdy plaintext.
- **E-mailová šablona `login_otp`** (cs/en, editovatelná v adminu).
- Migrace `0047_email_otp_2fa.sql` — tabulky `login_otps` + `trusted_devices`.

### Security

- Šestimístný kód chráněn per-user lockoutem (10 selhání / 10 min) stejně jako
  TOTP. `cron-cleanup.php` maže expirované kódy i důvěryhodná zařízení.
- `reset-2fa.php` nově vedle vypnutí TOTP maže i `trusted_devices` a
  `login_otps` účtu (úplný reset 2FA). `reset.php` wipuje obě nové tabulky.

### Tests

- 15 nových live-DB testů (`EmailOtpServiceTest`, `TrustedDeviceServiceTest`):
  vydání/ověření kódu, jednorázovost, reuse, cooldown, max pokusů, expirace,
  hashování, vázání na uživatele. Celkem 367 testů zelených.

### Fixed

- **Kniha DPH** měla v levé navigaci stejnou ikonu jako DPH přiznání —
  dostala vlastní ikonu otevřené knihy.

## [4.1.2] — 2026-05-24

Patch release nad v4.1.1 (~3 hodiny později) — čtyři logické bugy v bank
matching a AI importu odhalené reálnými user reporty plus výrazné cost
optimization recheck skriptu.

### Fixed (Banka — párování přijatých faktur)

- **Auto-párování přijatých faktur nefungovalo, i když VS sedělo na
  číslo dokladu dodavatele**. `StatementMatcher` hledal
  `purchase_invoices.varsymbol` (= naše interní `PF-YYYYMM-NNNN`), ale
  do bank převodu uživatel typicky zadává **VS dodavatele** =
  `vendor_invoice_number`. Match nikdy neprošel. Fix: WHERE klauzule
  `(varsymbol = ? OR vendor_invoice_number = ?)`. Stejně v manuálním
  matchi přes `/api/bank-transactions/{id}/match` s varsymbol fallbackem.
- **`auto_partial` pro outgoing payments byl tichý** — když byl rozdíl
  0,01–1,0 Kč, `matchPurchase()` vracel jen JSON status, ale **nezapisoval
  nic do DB**. Transakce zůstala `unmatched`, UI partial match nikdy
  nezobrazil. Fix: `payment_matches` insert + `tx.match_status =
  'auto_partial'` (stejně jako exact path, jen confidence 70 vs 95).
- **Tolerance `auto_exact` 0,01 Kč → 0,05 Kč** — user report Vodafone
  faktury (bank −1 502,00 Kč vs faktura 1 502,02 Kč = diff 0,02 Kč ze
  21 % DPH roundingu) padla jako partial místo exact, faktura zůstala
  neoznačena jako paid. Nová tolerance pokrývá DPH rounding step
  (1 241,34 × 1,21 = 1 502,0214 → různá round-by-row vs round-by-total
  strategie dává ±2-4 haléře rozdíl). Konstanty `EXACT_MATCH_TOLERANCE`
  / `PARTIAL_MATCH_TOLERANCE` v `StatementMatcher` — snadné rozšíření
  per-měna v budoucnu.

### Added (Banka — UI)

- **Sloupec „pojmenování účtu"** v `/bank` seznamu (např.
  „CZK — Fio Bank") — z `currencies.label` per supplier scope. Šedě pod
  číslem účtu v desktop tabulce i mobile kartě.
- **Tlačítko Stáhnout originální GPC** přesunuté do panelu transakcí
  vedle „Přepárovat výpis" (od původního header umístění je tam víc
  kompaktní). Zobrazuje se jen pokud `has_file=true`.

### Fixed (AI import — rounding)

- **AI rounding počítaný vůči chybnému AI total**, ne vůči přepočtenému
  items total. User report Vodafone faktura 1025255728:
  ```
  Items recompute:  1502,02   ← deterministický InvoiceMath
  AI total_with_vat: 1502,03  ← AI math off-by-one v 21 % DPH
  PDF "K úhradě":   1502,00

  Před fixem: rounding = rounded − AI_total = -0,03  → UI 1501,99 ✗
  Po fixu:    rounding = rounded − items_recompute = -0,02  → UI 1502,00 ✓
  ```
  Refaktor: `computeRounding()` smazána (postavená na chybném předpokladu);
  rounding se počítá AŽ PO `recompute()` v nové `applyRoundingFromPdfTotal()`
  s priority `total_with_vat_rounded` (PDF "K úhradě") → fallback
  `total_with_vat` (AI). Regression test pokrývá Vodafone case explicitně.

### Changed (Recheck — cost optimization)

- **`api/bin/recheck-ai-extracted-invoices.php` přepsán na hybrid hierarchii**
  zdrojů PDF totalu (per user request „je to docela nákladné dělat vše
  znovu přes AI"):
  1. **ISDOC** (PDF/A-3 embedded — iDoklad / Fakturoid / Pohoda /
     MyInvoice vlastní faktury) — autoritativní `LegalMonetaryTotal/PayableRoundedAmount`,
     zdarma, ~10 ms.
  2. **PDF text regex** (nová composer dep `smalot/pdfparser` ^2.12,
     ~3 MB) — max peněžní hodnota s mandatorním currency suffixem
     (Kč/CZK/EUR/USD/€). Funguje napříč layouty (max je typicky total
     faktury). Zdarma, ~50-200 ms.
  3. **AI fallback** (jen pokud 1 i 2 selhaly) — nový lightweight
     `AnthropicClient::extractPdfTotal()` (max_tokens 100, minimalistický
     prompt, ~5-10× levnější per call než `extractInvoice` —
     Haiku ~$0.0001 vs $0.001).

  Nové CLI flagy `--ai-only` (legacy behavior) a `--no-ai`. Statistika
  per source na konci („Ušetřeno AI volání: 91 % z 80 zpracovaných").

### Migrations

Žádné — vše code-only changes. Stačí pull image + restart.

## [4.1.1] — 2026-05-24

Patch release nad v4.1.0 (~3 hodiny později) — production hotfix
regrese z migrace 0045 + drobné UX vylepšení.

### Fixed (Banka)

- **`GET /api/bank-statements/{id}` vracelo 500** — `Json::ok()` házelo
  `JsonException: Malformed UTF-8 characters` v `BankStatementAction::detail()`.
  Root cause: `SELECT bs.*` po migraci 0045 tahá i `file_content` (MEDIUMBLOB
  se surovými CP1250 bajty GPC souboru), `json_encode()` default vyžaduje
  validní UTF-8 → padne. Důsledek: detail bank výpisu se v UI nezobrazil
  na žádném tenant, který stihl po upgrade nahrát alespoň jeden nový GPC.
  Fix: explicit column list ve SELECT, `file_content` se vůbec nevrací
  z `/detail` (bajty zůstávají dostupné jen přes `/download` endpoint
  s `Content-Type: text/plain; charset=windows-1250`). Místo BLOBu
  exposujeme `(file_content IS NOT NULL) AS has_file` — stejný pattern,
  který list endpoint už používal od commitu `95aed56`. Detail jsem
  zapomněl propsat, odtud regrese.

### Added (Banka)

- **Hromadný GPC upload** — `<input type="file" multiple>` v `/bank`.
  User může vybrat víc souborů najednou; frontend sekvenčně iteruje
  (kvůli `bank_statements.file_hash` dedupu — paralelní upload by druhou
  duplicitu nezachytil mezi `INSERT` a `SELECT … WHERE hash`). Single-file
  mode zachovává původní UX (toast + redirect na detail), batch mode ukáže
  souhrnný toast `"5 importováno, 2 duplicit"` a zůstane na seznamu.
  Prvních 5 chybných souborů v error banneru s názvem + důvodem.

### Docs

- **Manuál `24_Vykazy_DPH.md`** — nová sekce **Pole EPO / VetaP**:
  kompletní mapping všech polí v *Nastavení → Daňové nastavení* na XML
  atributy DPHDP3/DPHKH1 (`c_ufo`, `c_pracufo`, `c_okec`, `typ_ds`,
  `typ_platce`, `ulice`+`c_pop`+`c_orient` jako tři samostatné atributy
  od v4.0.6, `opr_*` od v4.0.6 pro PO, `sest_*`). Plus 7-krokový postup
  podání na EPO portál a sekce „Časté problémy".
- **Manuál `17_Importy.md`** — pět nových sekcí (17.8–17.12) o API
  importech: iDoklad OAuth2 Client Credentials, Fakturoid s podporou
  obou auth flow (legacy email+token i nový OAuth2 z v4.1.0), dry-run
  preview, background worker s progress pollingem, časté problémy.
- **`openapi.yaml`** — `GET /api/v1/codebooks/years`,
  `DELETE /api/v1/bank-statements/{id}`,
  `GET /api/v1/bank-statements/{id}/download` + nová pole na schématech
  (`BankStatement.currency`/`has_file`, `PurchaseInvoice.is_fixed_asset`).
  Aditivní změny, API version `1.0` zachována.

### Chore

- **`.github/FUNDING.yml`** — block-style YAML list místo inline flow array
  (některé parsery jsou na single-item flow alergické), comment dovysvětlení
  proč GitHub Sponsor button může být skrytý (repo-level setting toggle
  v *Settings → Features*).

## [4.1.0] — 2026-05-24

Velký combo release reagující na pět GitHub issues od externích uživatelů
(#29, #30, #31, #33, #34). Hlavně dvě regulační opravy DPH výkazů — reverse
charge v cizí měně a pořízení dlouhodobého majetku — plus Fakturoid OAuth2,
dynamický year dropdown, GPC bank import v EUR a Docker deploy fixes.

### Added (Fakturoid)

- **Podpora OAuth2 Client Credentials** (issue #31) — Fakturoid v roce 2024
  deprecoval personal API tokens pro nově založené účty; jediné dostupné
  credentials u nového účtu jsou *API v3 přístupové údaje* (Client ID +
  Client Secret). Externí integrace → Fakturoid teď nabízí přepínač mezi
  oběma flow:
  - **OAuth2 (nové účty)** — Client ID + Client Secret. MyInvoice si Bearer
    token sám obnovuje (POST `/api/v3/oauth/token`, TTL ~2h, cache šifrovaná
    AES-256-GCM v `supplier.fakturoid_access_token_enc`). Při HTTP 401
    se token vyhodí a obnoví automaticky.
  - **Email + API token (starší účty)** — legacy BasicAuth flow zůstává plně
    funkční pro účty založené před deprecation 2024.
  Oba způsoby koexistují per-supplier; pokud má supplier vyplněné oba bloky,
  OAuth2 má prioritu. Žádný impact na existující uživatele — kdo má dnes
  uložený personal API token, ten dál fachá beze změny.

### Fixed (UI)

- **Year dropdown hardcoded na posledních 5 let** (issue #33) — importovaná
  historická data (5-10+ let z Fakturoid/iDoklad migrací) byla v UI
  neviditelná, i když datová vrstva starší roky podporuje. Nový endpoint
  `GET /api/codebooks/years` vrací distinct roky z `invoices` a
  `purchase_invoices` aktuálního supplier; composable `useYearOptions()`
  doplní aktuální + minulý rok (ať dropdown nikdy nebyl prázdný) a aktuálně
  vybraný rok z URL (ať `?year=2018` byl v dropdownu i bez dat). Opraveno
  na `/invoices`, `/purchase-invoices` a všech 5 reportech (DPH přiznání,
  KH, Souhrnné hlášení, Kniha DPH, daň z příjmů).

### Fixed (Banka)

- **GPC výpis v EUR se zobrazoval jako CZK na detailu výpisu** —
  `StatementDetail.vue` měl měnu natvrdo na 6 místech (header summary boxy
  + amount sloupec u transakcí). Teď používá `statement.currency ?? 'CZK'`,
  per-tx pak `tx.currency ?? statement.currency ?? 'CZK'`. Hlavička detailu
  navíc zobrazuje měnový badge vedle čísla účtu.
- **Automatické párování ignorovalo měnu** — `StatementMatcher` načítal
  `cur.code` faktury jen pro informaci, nepoužíval ho ve WHERE. EUR výpis
  na 1000 EUR by tak teoreticky napároval CZK fakturu na 1000 Kč se
  stejným VS jako `auto_exact`. Nový currency guard: tx currency (resp.
  statement currency fallback) musí být shodná s `currencies.code` faktury;
  bez currency (staré výpisy) match jako dosud (backward compat).
- **GPC výpis v EUR se zobrazoval jako CZK** — `StatementImporter` ukládal
  `bank_statements.currency = NULL` (sloupec ignoroval); UI pak měnu
  formátovalo fallbackem na CZK i u skutečně EUR / USD výpisů. Fix:
  měna výpisu se detekuje z 075 transakcí (pozice 118-122, ISO 4217
  numeric — kód `00978` = EUR atd.), fallback je lookup do
  `currencies.account_number`. Per-transakce currency je uložená jako
  předtím + fallback na statement currency pro banky, které 075.currency
  nevyplňují. Existující výpisy importované před touto verzí mají
  `currency = NULL` — pro nápravu je smaž a re-importuj (Banka → koš).

### Added (Banka)

- **Smazání výpisu** — tlačítko (koš) v seznamu Banka, **jen pro admina**
  (účetní může nahrávat a párovat, mazat ne — forensic integrity uzávěrky
  DPH/KH). CASCADE smaže i transakce a payment_matches; status zaplacených
  faktur zůstává (řeš ručně, pokud je to relevantní).
- **Stažení originálního GPC souboru** — tlačítko v seznamu/detailu vrátí
  originální bajty (uložené v `bank_statements.file_content` od migrace
  0045). Výpisy importované před touto verzí soubor nemají → tlačítko
  pro ně nezobrazuji (`has_file=false`).
- **Sloupec měny v seznamu /bank** + měnový badge na mobilních kartách.
  Zůstatek je nyní formátován měnou výpisu (předtím vždy CZK).

### Fixed (Docker / deploy)

- **ARES/VIES lookup na ENV-only deploy** (issue #30): image-baked stub
  `<?php return [];` neměl `ares.api` ani `vies.rest_api`, takže `Config::get()`
  vracel prázdný string a Guzzle padal s `cURL error 3: No host part in the URL`,
  což UI hlásilo zavádějícím „ARES je dočasně nedostupný". Fix: `Config::baselineDefaults()`
  vrátí veřejné konstanty (ARES + VIES URLs, timeouty, cache TTL) jako baseline
  před cfg.php override; nové ENV `MYINVOICE_ARES_API`, `MYINVOICE_ARES_TIMEOUT`,
  `MYINVOICE_VIES_REST_API`, `MYINVOICE_VIES_TIMEOUT` pro override. `AresClient`
  loguje config error odděleně od network error.
- **cron-backup zápisem do ephemerálního FS** (issue #34): `cron-backup.php`
  i `cron-backup-pdf.php` hardcodily `storage/backup`, ignorovaly
  `cron.backup.output_dir` / `storage.backup_dir` / `MYINVOICE_DATA_DIR`,
  takže ZIPy zmizely při každém deployi na Fly.io / Railway / Render. Fix:
  resolve v pořadí cfg → DATA_DIR → rootDir fallback.
- **Image neměl `mariadb-client`** (issue #34): cron-backup je dokumentován
  jako „kritická úloha" ale `mariadb-dump` nebyl v image → cron failnul
  out-of-the-box na čerstvém deploymentu. Fix: přidán `mariadb-client`
  (~20 MB) do apt install v Dockerfile.



### Fixed (DPH výkazy)

- **Reverse charge / EU pořízení v cizí měně** (issue #29): kurz se nyní
  aplikuje na základ DPH (1 000 € × 25 = 25 000 Kč na ř. 3) a samovyměřená
  daň se dopočítá ze sazby (5 250 Kč). Současně se mirror odpočet zobrazí
  na ř. 43 a v kontrolním hlášení se vygeneruje sekce **A.2** s atributy
  `k_stat`, `vatid_dod`, `c_evid_dd`, `dppd`, `zakl_dane1/dan1`,
  `zakl_dane2/dan2`. Migrace 0044 doplnila `dphdp3_line_secondary='43'`
  pro kódy 5 (tuzemský RC) a 23 (EU pořízení zboží), takže mirror
  odpočet jde do XML automaticky.
- **Mapping atributů Veta1/Veta4 v DPHDP3**: ř. 3-13 (výstup samovyměřené
  daně) a ř. 40-47 (odpočet) byly propojeny se správnými atributy EPO XSD
  (`p_zb23/dan_pzb23`, `pln23/odp_tuz23_nar`, `odp_rezim/odp_rez_nar`,
  `nar_maj`, ...). Dříve některé řádky (3-13) propadaly bez ukládání do
  XML a ř. 40/41 šel do špatného atributu.

### Added (DPH výkazy)

- **Pořízení dlouhodobého majetku — ř. 47 DPHDP3** (issue #29): nový
  příznak `is_fixed_asset` na hlavičce přijaté faktury (a per řádek pro
  mixed doklady). Když je nastaven a doklad jde do odpočtu (ř. 40-45 přímo
  nebo přes RC mirror na ř. 43), hodnota majetku se navíc uvede na ř. 47
  jako doplňující údaj (atribut `nar_maj`). Daň se neduplikuje v součtu —
  ř. 47 je informativní řádek vedle ř. 40.
- **Editor přijaté faktury**: checkbox „Pořízení dlouhodobého majetku"
  vedle reverse charge, plus tooltip s odkazem na § 4 odst. 4 písm. c).
- **Kniha DPH** ukazuje samovyměřenou daň u RC řádků (předtím byla 0)
  a má dvě nové sekce: **43.XXX** (RC mirror odpočet) a **47.XXX**
  (hodnota pořízeného majetku, sumační doplňující údaj).
- **Auto-klasifikace**: EU vendor + RC + 21 % → kód `23` (Pořízení zboží
  z JČS) místo `40` (tuzemsko), takže řádek dorazí do KH A.2 automaticky.

### Changed

- `VatClassificationMapper::aggregateForDphPriznani` přepočítá per řádek
  (ne per agregaci) kvůli aplikaci kurzu, dopočtu samovyměřené daně
  a podpoře secondary line.
- `openapi.yaml` doplněn o `GET /api/v1/codebooks/years`,
  `DELETE /api/v1/bank-statements/{id}`,
  `GET /api/v1/bank-statements/{id}/download` a o nová pole
  (`BankStatement.currency`/`has_file`, `PurchaseInvoice.is_fixed_asset`).
  Aditivní změny, API version "1.0" zachována.

### Migrations

- **0044** — `is_fixed_asset` sloupec na `purchase_invoices` i
  `purchase_invoice_items`; secondary line `'43'` pro kódy 5 a 23.
- **0045** — `bank_statements.file_content MEDIUMBLOB NULL` pro download.
- **0046** — `supplier.fakturoid_client_id`, `fakturoid_client_secret_enc`,
  `fakturoid_access_token_enc`, `fakturoid_access_token_expires_at` pro
  OAuth2 flow vedle stávajících BasicAuth polí (migrace 0031).

## [4.0.7] — 2026-05-22

Drobný hotfix — UI tlačítko pro hromadný export přijatých faktur ve formátech
Pohoda XML a ISDOC ZIP nedělalo nic. Backend handlery byly už hotové
(`ExportPurchaseInvoicesAction::exportIsdocZip` a `::exportPohodaDataPack`),
ale frontend tlačítko **Stáhnout** pro tyto dva formáty silentně vypadlo
z `download()` v `Export.vue` (zachované jen pdf-zip + komentář „v fázi 5/6").

### Fixed

- **`/purchase-invoices/export`** — Pohoda i ISDOC tlačítko teď reálně stahuje
  XML / ZIP. `exportUrl()` v `web/src/api/purchaseInvoices.ts` přijímá třetí
  parametr `format` (`pdf-zip` | `pohoda` | `isdoc`).

### Changed

- **Manuál 10.6** — sekce „Hromadný export za měsíc" nahradila NOTE o tom, že
  bulk export je „v plánu pro v4.0.0".

## [4.0.6] — 2026-05-22

DPH/KH XML kompletně odpovídá tomu, co posílá EPO portál: doplněny atributy
`c_okec`, `d_poddp`, `trans` v `<VetaD>`, plus `c_orient`, `c_pop`, `c_telef`,
`opr_*`, `sest_jmeno`/`sest_prijmeni`/`sest_telef` v `<VetaP>`. KH také získává
`c_radku` (sekvenční číslo řádku) v sekcích A1/A4/B1/B2 a finální `<VetaC>`
rekapitulaci se sumami obratů.

### Added

#### EPO výkazy — kompletní `<VetaP>` napříč DPH/KH/SHV
- Nový shared helper `EpoSupplierBlockBuilder::fillVetaP()` — DPH, KH i SHV sdílí
  jeden generátor identifikačního bloku poplatníka. Atributy přesně podle toho,
  co posílá reálné EPO podání:
  - **Adresa:** `ulice`, `c_pop` (číslo popisné), `c_orient` (orientační), `naz_obce`,
    `psc`, `stat`. Pokud má uživatel vyplněná samostatná pole `street_number_pop` /
    `street_number_orient`, použijí se a z `street` se odřízne trailing číslo (aby
    se neduplicovalo). Jinak fallback parsing z formátu `"Ulice 1104/36"`.
  - **Kontakt:** `email`, `c_telef`. Telefon je automaticky normalizován —
    `+420` / `00420` prefix a mezery se strippují (EPO konvence: 9-místné číslo).
  - **Oprávněná osoba:** `opr_jmeno`, `opr_prijmeni`, `opr_postaveni`
    (typicky jednatel u s.r.o.) — povinné u právnických osob.
  - **Sestavitel:** `sest_jmeno`, `sest_prijmeni` (split z DB), `sest_telef`.
    Pozn.: `sest_email`/`sest_funkce` nejsou v EPO XSD — držíme je jen v DB
    pro vnitřní UI použití.
- `normalizeOkec()` — robustní normalizace CZ-NACE / OKEČ hodnoty (`"62.09"` /
  `"620900"` / `"629000"` → 6-digit string). Hodnotu uživatel zadá v Nastavení,
  validitu proti číselníku ověřuje proti `mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/okec`.

#### DPH (`DphPriznaniBuilder`)
- VetaD: `c_okec` (CZ-NACE z `supplier.cz_nace_code`), `d_poddp` (datum podání =
  dnes), `trans` (A = vznikla daňová povinnost / N = nadměrný odpočet,
  dopočítáno po Veta6).
- VetaP přes shared helper — všechny atributy odpovídající reálnému EPO podání.

#### KH (`KontrolniHlaseniBuilder`)
- VetaD: `d_poddp` (datum podání).
- VetaP přes shared helper — opr_*, sest_*, c_orient, c_pop, c_telef atd.
- `c_radku` (sekvenční číslo řádku 1..N) přidáno do `VetaA1`, `VetaA4`,
  `VetaB1`, `VetaB2` — odpovídá reálnému EPO formátu.
- Nová `<VetaC>` rekapitulace na konci se sumami `obrat23`, `obrat5`, `pln23`,
  `pln5`, `pln_rez_pren`, `rez_pren23/5`, `celk_zd_a2`.

#### Settings — nová pole pro tax info
- **Sekce „Daňové údaje":** `cz_nace_hint` odkazující na ARES/mojedane,
  `street_number_pop` (č.p.), `street_number_orient` (č.o.) s vysvětlením
  fallback parsingu.
- **Nová sekce „Oprávněná osoba":** `opr_jmeno`, `opr_prijmeni`, `opr_postaveni`.
  Povinná u právnických osob; u OSVČ ponechat prázdné.
- i18n CS+EN.

#### Unit a integration testy
- 2 nové integration testy (`EpoXsdValidationTest`): kontrolují, že VetaP
  v DPH i KH obsahuje **všechny** atributy z reálného EPO XML (`c_orient`,
  `c_pop`, `c_telef`, `opr_*`, `sest_*`) + že `ulice` neduplikuje číslo
  když je `c_pop`/`c_orient` zvlášť + že `c_telef` je normalizovaný (bez
  +420 a mezer) + `d_poddp = dnes`.
- Celkem 335 testů, 704 asercí, vše zelené.

### Migrations

- `0043_supplier_epo_fields.sql` — přidává sloupce `street_number_pop`,
  `street_number_orient`, `opr_jmeno`, `opr_prijmeni`, `opr_postaveni`
  do `supplier`. Idempotentní (`ADD COLUMN IF NOT EXISTS`).

### Changed

- `DphPriznaniBuilder.loadSupplier` a `KontrolniHlaseniBuilder.loadSupplier`
  rozšířené o nová pole (cz_nace_code, opr_*, sest_*, street_number_*).
- VetaP sdílen mezi DPH a KH přes `EpoSupplierBlockBuilder` (DRY refaktor).

## [4.0.5] — 2026-05-22

Nová funkce **Kniha DPH** (měsíční VAT žurnál) a zásadní zlepšení AI extrakce
přijatých faktur — detekce vendor↔customer záměny, auto-upgrade na silnější
model při slabém výsledku, sanity check sumy řádků, korektní handling slev
se zápornou hodnotou, ne-destruktivní placeholder při katastrofálním mismatch.
Plus inline PDF náhled v editoru, filtr „Ke kontrole" v seznamu přijatých
faktur a 23 nových unit testů.

### Added

#### Kniha DPH (měsíční VAT žurnál)
- Nová stránka `Daně → Kniha DPH` (pod Kontrolním hlášením). Interní reporting
  výkaz seskupený podle řádků DPH přiznání (např. `15.040` přijaté tuzemsko 21 %,
  `36.001` uskutečněná tuzemsko 21 %, `43.012` + `43.043` dovoz služby).
- Měsíční selektor (rok + měsíc) + tlačítko **Stáhnout PDF** (landscape A4,
  11 sloupců: datum plnění, zaúčtování, doklad, popis, ZD CZK, DPH CZK, celkem
  CZK, partner + DIČ, orig. číslo dokladu, orig. datum plnění, KH kód).
- Zahrnuje i drafty (vizuálně označené) — užitečné pro pracovní přehled před
  uzavřením období.
- Není to EPO podání — čistě interní reporting / archiv.
- Endpointy: `GET /api/reports/dph-book/preview` (JSON) + `GET /api/reports/dph-book`
  (PDF download). Guard `admin|accountant`.
- Migrace `0042_vat_classifications_secondary_line.sql` přidává sloupec
  `dphdp3_line_secondary` — umožňuje, aby jedno přijaté plnění generovalo
  současně dva řádky (typicky dovoz služby: ř.12 přiznání DPH + ř.43 nárok
  na odpočet z téhož).

#### AI extrakce přijatých faktur — výrazná vylepšení

**Tenant context block v promptu.** Před extrakcí se do systémového promptu
vloží explicitní pravidlo: *„Tato firma (s tímto IČ a názvem) je VŽDY odběratel
— NIKDY ne dodavatel."* Předchází tomu, aby AI u faktur s dominantní hlavičkou
dodavatele (autoservisy, mobilní operátoři, hostingy) zaměnila vendor↔customer.

**Auto-upgrade na silnější model.** Pokud Haiku 4.5 vrátí slabý výsledek
(vendor = tenant bez použitelného customer pro swap-back NEBO Σ items vs AI
total > 50 %), extractor automaticky retry-uje s `claude-sonnet-4-6`. Pokud
uživatel má Sonnet/Opus jako default, retry se přeskočí.

**Sanity check sumy řádků.** Nový sloupec `purchase_invoices.extraction_warning`
(migrace `0041_purchase_invoice_extraction_warning.sql`) drží diagnostický
text. Po extrakci se spočítá `Σ (qty × unit_price_without_vat)` se znaménky
(slevy s mínusem, dobropisy se zápornou qty) a porovná s AI `total_without_vat`.
Při rozdílu > 2 % se uloží warning. **Porovnání je vždy bez DPH na obou
stranách** — žádný `total_with_vat / 1.21` fallback, který by u multi-rate
faktur (mix 21/12/0 %) generoval false-positive.

**Handling slev a dobropisů.** AI prompt explicitně instruuje, že u řádků se
slevou/rabatem mají být qty nebo unit_price záporné (pokud jsou na PDF se
znaménkem mínus). Importér přestal násilně aplikovat `abs()` na běžné faktury
— znaménko z AI se respektuje. U dobropisů se sign aplikuje dle `document_kind`.

**Placeholder fallback při katastrofálním mismatch (> 50 %).** Pokud AI items
sečtené dají 5–10× víc než reálný total (typicky komplexní multi-column
servisní faktury, kde Haiku nezvládá rozparsovat sloupce), extractor zachová
popisy řádků z AI extraktu (jsou typicky správně) s qty = 0 a price = 0,
a přidá první řádek **KOREKCE** s AI totalem z „K úhradě". Uživatel pak
postupně doplňuje qty/ceny k jednotlivým řádkům a nakonec smaže korekční
řádek. Práh 50 % je úmyslně vysoký — drobné chyby (sleva s opačným znaménkem
~22 %) zůstávají v rukou uživatele, prompt se neztratí.

**Vendor=tenant fallback.** Když AI vrátí `vendor.ic == tenant.ic` a customer
chybí, extrakce se odmítne s jasnou hláškou. Auto-upgrade na Sonnet typicky
tento případ vyřeší.

**Rounding z AI total.** Pokud AI nevrátila `total_with_vat_rounded`, ale
`total_with_vat` se od přesného součtu z items liší o méně než 1 Kč, rozdíl
se automaticky uloží jako rounding offset. Zachycuje typické zaokrouhlení
„K úhradě" na celé Kč.

**markAlreadyPaid s logováním.** Když AI detekuje „JIŽ UHRAZENO" / „PAID" /
„Hradí se ze zálohy" a faktura skočí draft → paid, případné selhání už není
silent — logger zaznamená důvod (varsymbol konflikt, race na statusu).

**Backfix CLI** `api/bin/recheck-ai-extracted-invoices.php` — projde existující
přijaté faktury s PDF přílohou, re-spustí AI extrakci a porovná AI total s
DB totalem. Při rozdílu > prahu (default 2 %) zapíše `extraction_warning`.
Default dry-run, `--apply` pro skutečný zápis, `--supplier-id`, `--limit`,
`--threshold`, `--include-flagged`.

#### UI vylepšení přijatých faktur
- **Inline PDF náhled v editoru** — tlačítko **Zobrazit PDF** v `InvoiceEditor.vue`
  (stejný pattern jako v Detail.vue, 80vh iframe, FitH).
- **Žluté zvýraznění + ikona** v seznamu přijatých faktur u faktur s
  `extraction_warning != NULL`.
- **Filtr „Ke kontrole"** v topbaru seznamu — zobrazí jen faktury vyžadující
  manuální revizi. URL sync (`?needs_review=1`).
- **Tlačítko „Beru na vědomí"** ve warning banneru (`Detail` i `Editor`) —
  POST `/api/purchase-invoices/{id}/dismiss-extraction-warning`, smaže warning
  bez nutnosti posunout stav.
- **Auto-clear warning při transition draft → received/booked/paid** — uživatel
  posunul stav = ověřil data, warning už není potřeba.

#### Vendor list — drafty v počtu faktur
- Sloupec **Počet faktur** v `clients?role=vendors` teď zahrnuje i drafty.
  `costs` (sumarizace nákladů) zůstává jen z non-draft non-cancelled faktur —
  draft není ekonomicky reálný.

#### Unit testy
- Nový test soubor `AiPdfExtractorUnitTest.php` (17 testů) — pokrývá
  `detectWeakExtraction`, `maybeFlagTotalsMismatch`, `applyRoundingFromAiTotal`
  proti reálným scénářům: clean extraction, sleva s mínusem, dobropis se zápornou
  qty, katastrofální mismatch s placeholderem + zachovanými AI popisy, chybějící
  `total_without_vat` (žádný `/1.21` fallback).
- Nový test soubor `AnthropicClientUnitTest.php` (5 testů) — pokrývá
  `buildTenantContextBlock` (plné info, jen name, prázdný supplier, DB error).
- `composer require --dev dg/bypass-finals` — runtime obejití `final class`
  pro mocky v unit testech (PurchaseInvoiceRepository, Connection a další).
- `tests/bootstrap.php` registruje BypassFinals; `phpunit.xml` ho používá.

### Fixed

- **Sidebar highlight kolize** — položka „DPH přiznání" se rozsvěcovala i na
  podstránce „Kniha DPH", protože `isActive` v `AppLayout.vue` matchovala přes
  `startsWith(toPath)` (`/reports/dph` je prefix `/reports/dph-book`). Změna
  na exact match nebo skutečný child segment (`toPath + '/'`).
- **Sanity check sčítal položky přes `abs()`** — sleva se zápornou cenou se
  do sumy započítala jako kladná, což generovalo falešné varování (~22 % diff)
  i u faktur, kde byly items správně. Teď signed sum, abs() až na výsledku
  pro porovnání s AI totalem.
- **Recheck CLI měl bug u dobropisů** — `$dbTotal` bez `abs()` ukazoval 100 %
  diff i u korektně extrahovaných credit notes. Plus stejný `/1.21` fallback
  jako v hlavním extractoru → multi-rate false positive. Obě místa fixnutá.

### Changed

- AI prompt rozšířen o sekce: pravidla pro slevy (záporné qty/cena), pravidla
  pro `total_with_vat` (jen „K úhradě", NIKDY ze subtotalu), few-shot příklad
  servisní faktury, instrukce ignorovat řádky „Celkem/Subtotal/Mezisoučet".
- Disclaimer banner v Kniha DPH sjednocen s DPH přiznáním (`bg-danger-50
  border-2 border-danger-500`).

## [4.0.4] — 2026-05-22

Velký funkční audit napříč projektem — opravy multi-currency rankingu, VAT
klasifikace s NULL handlingem, KH XML schema mismatch, self-service změna
hesla, AI vendor↔customer swap detekce, standalone Work Report modal a
spousta UX vylepšení.

### Added

#### Self-service profil (heslo + 2FA)
- Nová stránka `/profile/password` se záložkami **Heslo** + **2FA**.
  Předtím šlo heslo měnit jen přes admin → users; účetní si ho nemohl změnit
  vůbec.
- Live validace: min 12 znaků (matches `PasswordHasher::MIN_LENGTH`), max 128,
  match new ↔ confirm. Show/hide toggle, info hint o invalidaci ostatních
  sessions.
- 2FA záložka migrovaná z původního `/profile/totp` (redirect zachován pro BC).
- Header link u jména uživatele → `/profile/password` (vedle TOTP odstraněn,
  oba pod jedním klikem).
- Tab badge „aktivní" když je TOTP zapnuté.

#### Standalone Work Report modal
- Nová komponenta `WorkReportModal.vue` otevíraná tlačítkem **„Výkaz"**:
  - V detailu faktury (`InvoiceDetail.vue`): vedle Edit, viditelné pro draft +
    workflow projekt NEBO již existující výkaz.
  - V seznamu faktur (`InvoiceList.vue`): nahrazuje **KONCEPT** badge ve sloupci
    Stav u relevantních drafů — rychlý přístup bez navigace na detail.
- Editor řádků: description, work_date, hours, rate; live total per row + Σ
  hodin a sumy.
- ▲ / ▼ tlačítka pro přesun položek (mirror invoice items layout — vlevo).
- Save flow: `PUT /api/invoices/{id}/work-report` (uloží WR) + `PUT
  /api/invoices/{id}` (sync sumy do `invoice_items` jako jeden řádek se sumou).
- Stejné šipky přidány i do plného editoru faktury (`InvoiceEditor.vue`).

#### AI privacy notice
- Admin → Integrations → AI: warning panel nahoře vysvětluje, že obsah PDF
  (vč. citlivých dat) se odesílá na servery Anthropic (USA). Doporučení
  ISDOC importu pro citlivé doklady.

#### Footer odkaz na projekt
- Sidebar footer: link „MyInvoice.cz" → `https://myinvoice.cz/` (vedle verze).

#### Force-edit přijatých faktur i pro paid
- Admin může s `?force=1` upravit i `paid` přijatou fakturu (dříve jen
  received/booked). `cancelled` zůstává immutable.
- Tlačítko *Upravit (force)* s `confirm()` varováním o riziku bank-match rozbití.

### Changed

#### Multi-currency CZK ranking (všude)
Dosud řadily SUM agregace podle nepřepočtené částky napříč měnami — 1000 EUR
ranked pod 20 000 CZK. Sjednocený fix přes `i.exchange_rate` /
`pi.exchange_rate` (mirror Top klienti z 4.0.3):
- **Project Stats** (`/stats` Top zakázky): `topProjects` + `topProjects12m`
  přepočítávají na CZK, multi-currency projekt = 1 řádek.
- **CRM Dashboard**: `expenseBreakdown` (kategorie nákladů — 100 EUR + 50 000
  CZK už ne 50 100 nesmysl), `churnRisk` (klient v EUR + CZK jako jeden řádek).
- TypeScript `TopClient`/`TopVendor`: nové `currencies?: string` pole, `currency`
  vždy `'CZK'`.

#### VAT klasifikace s NULL handling (KH/DPH)
- `KontrolniHlaseniBuilder` INNER JOIN `vat_classifications` → LEFT JOIN.
  Faktury bez explicit klasifikace dosud silently dropped ze sekcí A.1/B.1
  (regulatory risk). Fallback: pokud chybí code, použij `i.reverse_charge` /
  `pi.reverse_charge` flag.
- `VatClassificationMapper` (DPH přiznání): auto-default code přes CASE WHEN
  pokud chybí — z `reverse_charge` + `vat_rate_snapshot` (RC=20/5,
  21%=1/40, 12%=2/41, 0%=3). Historická data + recent imports bez auto-classifier
  už nepropadají DPH řádky.
- GREATEST(tax_date, issue_date) → COALESCE (GREATEST dělalo NULL když tax_date
  NULL, jako u sales faktur).

#### Paginace + server-side filtry
- **Pravidelné fakturace** (`/recurring`): paginace + load-more, status filter
  server-side, `meta.status_counts` tab badges, `cfg.pagination.recurring_per_page`.
- **Schvalovací inbox** (`/admin/approvals`): paginace + load-more, status
  filter server-side.
- **Klienti** (`/clients?role=vendors`): role filter SQL backend místo Vue,
  `meta.role_counts`. Fix "15 z 15" pro 45 dodavatelů.
- Menu link reset: klik na „Faktury"/„Přijaté faktury" na té samé stránce →
  smaže filtry (předtím zůstávaly „zaseknuté").
- Vendor sort role-aware: `/clients?role=vendors` „last_activity" řadí podle
  `last_purchase_date`, „revenue" podle `costs` (dříve podle sales fields).

#### UX detaily
- **purchase-invoices/export** default `dateBy = 'issue'` (datum vystavení)
  místo `'tax'` (DUZP) — uživatel typicky exportuje podle data na faktuře.
- **Účetní role** vidí Exporty v menu PRODEJ + tax výkazy (Daň z příjmů,
  Archív podání). Router `beforeEach` enforce `accountantOrAdmin` meta.
- **formatMoney** respektuje per-currency decimals (JPY/HUF=0, BHD=3, ostatní 2)
  místo hardcoded 2. Locale dynamicky z i18n (en → en-US, cs → cs-CZ).
- **formatDate** lokalizovaný (předtím hardcoded cs-CZ pro všechny).

### Fixed

#### KH XML schema mismatch (regulatory)
- `KontrolniHlaseniBuilder` generoval atributy, které neodpovídaly MFČR XSD
  commitnuté v 4.0.1. Vygenerované KH XML by neprošlo validací při podání
  na MFČR portále.
- **VetaA1** (Přenesená daňová povinnost — dodavatel): `dppd` → `duzp`,
  doplněn povinný `kod_pred_pl='5'` (obecný tuzemský RC; v budoucnu z
  `vat_classifications.code` per faktura).
- **VetaB2** (přijatá tuzemská nad 10 000 Kč): doplněny povinné `pomer='N'`
  (poměrný odpočet podle §75) a `zdph_44='N'` (oprava nedobytné pohledávky).
- EpoXsdValidationTest::testDphkh1PassesXsdValidation nyní prochází.

#### AI extractor regression fixes
- **vendor↔customer swap** detekce: AI občas zaměnil strany (tenant v
  vendor pozici). Imports jsou vždy purchase faktury, takže pokud vendor.ic
  == tenant.ic → swap zpět. Backfill skript `backfill-vendor-swap.php`
  pro již zaimportované swap faktury.
- **reverse_charge auto-detect** (AI i iDoklad): vendor je non-CZ A všechny
  items vat_rate=0 → automaticky `reverse_charge=true`. Uživatel už nemusí
  ručně zaškrtávat u EU faktur.

#### DPH predikce: multi-currency drafts
- `DphPriznaniAction::draftsPrediction` dosud používal `COALESCE(IF(cur='CZK',
  1, i.exchange_rate), 1)` — drafty bez kurzu počítány 1:1 jako CZK.
- Nyní fallback přes `exchange_rates` cache (CASE WHEN exchange_rate IS NULL):
  dohledá se nejbližší ČNB kurz k DUZP, jen pokud cache prázdná pro danou měnu
  spadne na 1.

#### GetProjectAction `_czk` fieldy
- `unpaid_summary` doplněn o `unpaid_total_czk` + `overdue_total_czk`
  (mirror `GetClientAction`). Multi-currency projekt teď může v UI sečíst
  CZK přes všechny měny.

### Earlier (commits od v4.0.3)
- `feat(invoices)`: Výkaz button v seznamu nahrazuje KONCEPT badge u draftů
  s workflow / WR
- `feat(work-report)`: tlačítka ↑↓ pro přesun položek (modal + editor)
- `fix(work-report-modal)`: layout sumace pod tlačítkem „Přidat řádek",
  whitespace-nowrap pro „4 500,00 CZK"
- `fix(profile)`: sjednocená stránka `/profile/password` s tabs

## [4.0.3] — 2026-05-22

Patch release: multi-currency CZK přepočet u Top klientů/dodavatelů (jak na
Dashboardu, tak v CRM), opravený role filtr u klientů + paginace u recurring
a approvals, force-edit i pro zaplacené faktury, sekce „Podpora autora"
v README a oprava exchange_rate u sample dat.

### Added

#### Podpora autora (donate)
- **README**: nová sekce „Podpora autora" s číslem účtu Partners Banka
  (`7700000038 / 6363`), IBAN, BIC a QR kódem (`manual/donate/qrcode.jpg`).
- **GitHub**: `.github/FUNDING.yml` aktivuje tlačítko *Sponsor* v hlavičce
  repa (custom URL na README anchor).

#### Force-edit přijatých faktur i pro paid status
- Admin může s `?force=1` upravit i `paid` přijatou fakturu (dříve jen
  `received` / `booked`). `cancelled` zůstává immutable (auditní stopa).
- Tlačítko *Upravit (force)* v UI je teď button s `confirm()` dialogem
  (po vzoru force-delete) varujícím, že u zaplacené faktury změna částky
  může rozbít párování s bankovní transakcí.

### Changed

#### Top klienti / dodavatelé — CZK ranking
- **Dashboard, Tržby (Stats), CRM Dashboard**: Top klienti/dodavatelé
  dosud řadili podle nepřepočtené částky (1000 EUR pod 20 000 CZK). Nyní
  všechny SUM agregace přepočítají na CZK přes `i.exchange_rate` /
  `pi.exchange_rate` a multi-currency klient/vendor je jediný řádek se
  součtem napříč měnami.
- TypeScript shape `TopClient` rozšířen o `total_czk` + `currencies` (CSV,
  např. `'CZK,EUR'`); `currency` zachován pro BC ale vždy `'CZK'`.

#### Paginace + server-side filtry
- **Klienti** (`/clients?role=vendors`): role filter se aplikuje SQL na
  backendu, ne v Vue. `meta.role_counts` dodává správné tab badges
  „Klienti (X) | Dodavatelé (Y) | Vše (Z)". Dříve "15 z 15" i když bylo
  45 dodavatelů celkem.
- **Pravidelné fakturace** (`/recurring`): paginace + load-more tlačítko,
  status filter server-side. cfg klíč `pagination.recurring_per_page`.
- **Schvalovací inbox** (`/admin/approvals`): paginace + load-more,
  status filter server-side, `meta.status_counts` pro tab badges.

### Fixed

#### Chybějící tabulky v reset.php
- `php api/bin/reset.php` přidává mazání `payment_matches` (migrace 0034)
  a `purchase_invoice_counters` (migrace 0026), které předtím chyběly.

#### Sample data: exchange_rate u vystavených EUR faktur
- `SampleDataGenerator` nastavoval `exchange_rate` jen na purchase_invoices.
  Vystavené EUR faktury (Bratislava Soft, NorthLight GmbH) měly NULL kurz
  → v Top klientech se počítaly 1:1 jako CZK (15 436 EUR jako 15 436 Kč).
- Nyní `exchange_rate = 25.0` pro non-CZK sales invoices i credit_notes
  (kopíruje rate z parent invoice).
- Pozn.: `invoices` tabulka **nemá** sloupec `exchange_rate_source` —
  původní commit obsahoval bug s "Unknown column 1054" který byl
  obratem opraven.

#### Backfill exchange rates i pro invoices
- `api/bin/backfill-exchange-rates.php` dosud doplňoval kurz jen na
  `purchase_invoices`. Nyní pokrývá obě tabulky — pro existující sample/import
  data spustit `php api/bin/backfill-exchange-rates.php --apply`.

#### AI extractor: detekce dobropisu ze záporných částek
- AI občas vrátil `document_kind='invoice'` i pro PDF dobropisy se zápornými
  částkami. Code potom `abs()`-oval quantity a uložil pozitivní řádky jako fakturu.
- Nyní `AiPdfExtractor` post-process zkontroluje quantity/unit_price v items:
  pokud převažují záporné, override `document_kind = 'credit_note'`.
- Nový backfill: `php api/bin/backfill-credit-note-kind.php --apply`
  překlasifikuje už zaimportované `purchase_invoices` s `document_kind='invoice'`
  AND `total_with_vat < 0` na `credit_note`.

## [4.0.2] — 2026-05-22

Patch release zaměřený na bank matching (přepárování + ručně zaplacené faktury),
opravu chybějící migrace pro `payment_matches`, paginaci-independentní agregaci
nákladů v detailu klienta a několik UX drobností.

### Added

#### Přepárování bankovního výpisu
- **`/bank/statements/{id}` nové tlačítko *Přepárovat výpis*** v hlavičce
  seznamu transakcí. Užitečné, když uživatel doplní vystavenou/přijatou fakturu
  ex-post (po importu výpisu) — místo párování po jedné transakci se znovu
  spustí auto-match na všech `unmatched` + `auto_partial` transakcích výpisu.
- Stávající `auto_exact`, `manual` a `ignored` zůstanou netknuté.
- Backend: `POST /api/bank-statements/{id}/rematch`, audit log
  `bank.statement_rematch` s počty (newly_matched / newly_partial / still_unmatched).

#### Párování i ručně zaplacených faktur
- Bank matcher dosud hledal jen mezi nezaplacenými fakturami. Pokud uživatel
  označil fakturu jako zaplacenou ručně, transakce zůstávala visad ve výpisu
  jako `unmatched`. Teď `StatementMatcher` zahrnuje i fakturu se `status='paid'`
  — naváže transakci, ale **status a `paid_at` nepřepíše** (ručně nastavená
  hodnota zůstává).
- Pravidlo platí pro obě strany: vystavené (`invoices`) i přijaté
  (`purchase_invoices`).

### Changed

- **Detail klienta — *Náklady po letech / měsících*** se počítají server-side
  (`GetClientAction` přidal `costs_by_year` a `costs_by_month` jako mirror
  k `revenue_by_year / month`). Dříve se agregace dělala v Vue z načtené první
  stránky přijatých faktur — při `pagination.invoices_per_page=20` v `cfg.php`
  rok s 11 fakturami zobrazil jen 3.
- **Detail klienta — listing přijatých faktur** žádá `per_page=200` (backend
  max), aby v detailu byly všechny faktury najednou. Vyřešen scénář
  „v DB 28 faktur, UI ukazuje 20" u dodavatelů s víc fakturami než per-page.
- **Admin → Integrations → AI**: lze uložit změnu výchozího Claude modelu, aniž
  by uživatel musel znovu zadat API klíč. Dříve formulář vyžadoval `sk-ant-...`
  i když byl input read-only kvůli existujícímu klíči.

### Fixed

#### Chybějící migrace `payment_matches`
- Commit `c540d46` (fáze 3: bank matching pro přijaté faktury) v commit message
  ohlásil migraci `0034_payment_matches`, ale soubor se omylem nedostal do gitu.
  Tabulka existovala jen v lokálních DB (ručně vytvořená), na produkci chyběla
  → `INSERT INTO payment_matches` při auto-match outgoing transakce padal.
- Doplněno `db/migrations/0034_payment_matches.sql` (idempotentní
  `CREATE TABLE IF NOT EXISTS`, projde i tam kde už tabulka je).

#### Mazání klienta v roli vendor
- `DELETE /api/clients/{id}` kontroloval jen vystavené faktury a zakázky.
  Pokud byl klient v roli vendor s přijatými fakturami, vyhozený `RESTRICT`
  z FK `purchase_invoices.vendor_id` skončil ošklivým 500.
- Teď přidaná kontrola `purchase_invoices.vendor_id = ?` — friendly 409
  „Klienta nelze smazat — má X vystavených, Y přijatých faktur a Z zakázek."

### Earlier (commits od v4.0.1)
- `feat(ai-import)`: rate-limit retry/throttle + live progress v cron skenu
- `feat(ai-import)`: detekce dobropisu z PDF a záporné položky
- `feat(dph)`: predikce DPH navázaná na zvolený měsíc/kvartál
- `fix(tests)`: skip `EpoXsdValidationTest` v CI bez `cfg.php`
- `docs(manual)`: refresh `01_dashboard.webp` screenshot

## [4.0.1] — 2026-05-22

Patch release navazující na 4.0.0 — drobná vylepšení UX kolem multi-currency
přehledů, predikce DPH z konceptů, robustnější migrace, CI fix, oprava EPO XSD
souhrnného hlášení.

### Added

#### Multi-currency CZK přepočet u klientů
- **`/clients?role=vendors` sloupec *Náklady*** zobrazuje hodnotu v CZK (SQL už
  počítá multiplier přes `pi.exchange_rate`, frontend chybně labeloval s
  `c.currency_default` — např. 96 089 CZK ukazoval jako EUR).
- **`/clients/{id}` detail dodavatele**: při fakturách ve více měnách
  (EUR + USD a další) se *graf Náklady po měsících*, *Náklady po letech* a
  *Obrat po měsících/letech/zakázkách* automaticky přepočítají na CZK (přes
  `i.exchange_rate` resp. `pi.exchange_rate` fixovaný k DUZP). Single-currency
  klient zachovává původní měnu. V hlavičce karty se ukáže hint `CZK (přepočet
  z EUR, USD)`.
- Backend: `GetClientAction` doplnil `total_czk` k `revenue_by_month/year/project`
  a `unpaid_total_czk` + `overdue_total_czk` k `unpaid_summary`.

#### Predikce DPH z konceptů
- **`/reports/dph` nový řádek 4 boxů** se zobrazí, pokud existují koncepty
  vydaných nebo přijatých faktur (`status='draft'`). Ukazuje predikované DPH
  na výstupu/vstupu a vlastní povinnost (nebo nadměrný odpočet), 4. box
  vysvětluje, že jde o odhad ze zatím nevystavených/nepřijatých dokladů.
- Backend: nový endpoint `GET /api/reports/dphdp3/drafts-prediction` se sumací
  `total_vat` × `exchange_rate` (CZK přepočet), per tenant + role-guard.

#### Auto-backfill v migrate.php
- `php api/bin/migrate.php` po dokončení migrací detekuje 4 kategorie stale dat
  a automaticky spustí příslušný backfill skript s `--apply`:
  - non-CZK přijaté faktury bez `exchange_rate` → `backfill-exchange-rates.php`
  - přijaté faktury bez `varsymbol` → `backfill-purchase-varsymbols.php`
  - položky přijatých faktur bez `vat_classification_code` →
    `backfill-vat-classification.php`
  - položky vystavených faktur bez `vat_classification_code` →
    `backfill-vat-classification-invoices.php`
- Idempotentní: prázdné COUNT → skip; opakovaný běh = no-op.
- Volitelný flag `--no-backfills` pro CI / read-only deploy.

#### XSD schémata commitnutá v repo
- MFČR EPO schémata (`dphdp3`, `dphkh1`, `dphshv`, `dpfdp5`, `dppdp9`) přesunuta
  ze `storage/xsd/` (gitignored) do `api/xsd/` (commitnuté, ~250 KB).
- Po `git clone` má vývojář funkční XSD validaci bez `bash cmd/download-xsd.sh`
  setup kroku; CI runner projede `EpoXsdValidationTest` namísto soft-skipu.
- Update workflow zachován — `bash cmd/download-xsd.sh` / `cmd\download-xsd.cmd`
  stahují nové verze přímo do `api/xsd/`.

### Changed
- **`/reports/dph` *Vývoj DPH (12 měsíců)*** seřazený sestupně dle data — nejnovější
  měsíc nahoře.
- `cron-scan-purchase-inbox.cmd` při interaktivním spuštění streamuje výstup
  řádek po řádku na konzoli + do logu (`Tee-Object`), PHP `-d output_buffering=0`
  aby echo nedrhly v bufferu. Exit code se propaguje z PHP přes `$LASTEXITCODE`
  (Task Scheduler monitoring zachován).

### Fixed
- **Currency dropdown v editoru přijaté faktury** (`/purchase-invoices/{id}/edit`):
  po přidání nové měny (modal *„+ Přidat měnu"*) se nově přidaná měna neobjevila
  v selectu — refresh chybně volal `currencies()` bez `include_inactive=true`,
  takže měna s `is_active=false` ze seznamu vypadla. Nyní `currencies(true)`,
  dropdown se ihned aktualizuje a měna se vybere.
- **Graf nákladů a tabulka Náklady po letech v ClientDetail** zobrazovaly raw
  `total_with_vat` (např. 1585.10 USD jako „CZK") namísto přepočtu, protože
  `PurchaseInvoiceListItem` z `/api/purchase-invoices` neobsahoval `exchange_rate`.
  Doplněno do SELECT v `PurchaseInvoiceRepository::listGroupedByMonth` + TS interface.
- **`SouhrnneHlaseniBuilder` generoval `VetaA1`** ze starého schématu, ale aktuální
  XSD `dphshv.xsd` (EPO2) očekává `VetaR` s přejmenovanými atributy
  (`vatid_pod` → `c_vat`, `kod_plneni` → `k_pln_eu`, + povinné `c_rad`, `k_storno`).
  Pre-existing bug, který se projevil teprve při auto-klasifikaci EU dodávek na kód
  `22` v reálných datech.
- **CI selhával na `EpoXsdValidationTest`** — `setUp()` volal `Bootstrap::buildApp()`
  jako první krok, který fatálně padl na chybějícím `cfg.php` (gitignored) ještě
  před soft-skipem uvnitř testů. Nově soft-skip kontroluje přítomnost XSD adresáře
  jako úplně první krok v `setUp()`. Po commitnutí XSD do `api/xsd/` (viz výše)
  CI projde testy plnohodnotně bez skipu.

### Inspirace
Mnoho funkcí z větve 4.0.0 (přijaté faktury, AI extrakce, DPH/KH výkazy,
multi-currency, ISDOC) bylo inspirováno forkem [milhaus123/myinvoiceDph](https://github.com/milhaus123/myinvoiceDph) — díky Honzovi za prototyp DPH-aware fakturace a
detailní zmapování českých účetních pravidel, který sloužil jako reference
při návrhu vlastní implementace.

## [4.0.0] — 2026-05-22

Major release. Z fakturačního systému se MyInvoice stává plnohodnotnou
**fakturační + účetní platformou**: vystavené i přijaté faktury, AI extrakce
PDF, CRM dashboard, výkazy DPH a daň z příjmů, public REST API v1.

### Added

#### Přijaté faktury (nákupy)
- **Kompletní lifecycle přijatých faktur** — status `draft → received → booked
  → paid` (+ cancelled), barevné badges, hromadné akce (*Označit jako přijaté*,
  *Zaúčtovat*, *Označit zaplacené*, *Stornovat*, *Smazat*).
- **Dodavatelé** jako role v `clients` (`is_vendor=1`) — jeden řádek může být
  zároveň klient (K) i dodavatel (D). Filtr `/clients?role=vendors` se
  sloupcem *Počet faktur*, badge K+D pro dual-role firmy.
- **Multi-currency** — faktura v USD, platba v CZK, kurz ČNB k DUZP +
  tracking `exchange_diff_base` (kurzový zisk/ztráta). Vendor costs sumace
  přepočítává EUR/USD/... na CZK přes `pi.exchange_rate`.
- **PDF archiv** se SHA-256 dedupe, force-delete pro admina s orphan PDF
  cleanup, Windows case-insensitive path-traversal guard.
- **Export Pohoda XML / ISDOC ZIP / PDF ZIP** analogicky vystaveným fakturám
  (s XML attribute sanitization + ZIP streaming pro DoS mitigation).
- **Editovatelné rounding** v editoru, snapshot dodavatele do `vendor_snapshot`
  JSON, pagination (load-more pattern) v seznamu.
- **Auto-klasifikace** `vat_classification_code` per item podle sazby + RC
  v `PurchaseInvoiceRepository::replaceItems()` — bez ní by faktury nedorazily
  do DPH přiznání ani KH (mapper SKIPNE řádky s code=NULL).
- **Dedup guard** — `findIdByVendorInvoice()` ve všech importerech proti
  `UNIQUE KEY uq_pi_vendor_invoice` violation při re-importu.

#### AI extrakce + inteligentní import
- **AI extrakce PDF** přes Anthropic Claude (BYOK, AES-256-GCM šifrovaný
  API key per dodavatel). Strukturovaný JSON: dodavatel + IČ/DIČ, číslo
  dokladu, datumy, položky se sazbami DPH, sumy, IBAN, e-mail/telefon/web,
  detekce *„NEPLAŤTE, JIŽ UHRAZENO"* (auto-paid s generací varsymbolu),
  rounding handling.
- **ISDOC priorita** — extractor PdfIsdocExtractor + parser ukládá ISDOC XML
  jako primární zdroj dat, AI se volá jen pro PDF bez ISDOC.
- **Pohoda XML import** vystavených i přijatých faktur.
- **iDoklad + Fakturoid synchronizace** — OAuth pull klientů + faktur + PDF
  příloh, dedup guard proti re-importu.
- **Inbox scan cron** (`cron-scan-purchase-inbox`) — sleduje konfigurovaný
  adresář, ISDOC priorita, AI fallback, rate limit 30 calls / 5 min / user,
  fallback na admin user pro `created_by` FK.
- **ClientResolver** — 3-úrovňový lookup (IČO → DIČ → exact company_name)
  brání duplikování dodavatelů, VIES fallback pro EU bez IČO.
- **PurchaseInvoiceCnbApplier** — centrální služba sdílená všemi importery
  pro auto-ČNB kurz na non-CZK fakturách. AI / ISDOC / iDoklad / Fakturoid.
- **Backfill skripty**: `backfill-vat-classification.php`,
  `backfill-exchange-rates.php`, `backfill-purchase-varsymbols.php` pro
  existující legacy data (dry-run default, `--apply` zapíše).

#### CRM dashboard
- **KPI** — tržby / náklady / zisk per měsíc + YTD + trend % vs minulý měsíc.
- **Akce pro tebe** — daily TODO list (overdue faktury, recurring k vystavení,
  DPH deadline, neaktivní klienti) s dismiss per den / týden / navždy / *pro
  historická data* (snapshotuje aktuální ID, zobrazí jen NOVÉ výskyty —
  užitečné při migraci 2 roky zpět) + Restore UI.
- **Aging buckets** pohledávek i závazků (V termínu / 1-30 / 31-60 / 61-90 /
  90+ dní), per currency.
- **DSO** (Days Sales Outstanding), platební morálka %, riziko koncentrace
  (Pareto + Top 1 client %), Top klienti / dodavatelé.
- **Cash flow forecast** 4 týdny dopředu (predicted in/out per week).
- **Late-risk score** per klient (predikce pravděpodobnosti pozdní platby).
- **Churn risk** — neaktivní klienti 60+ dní bez objednávky.
- **Náklady po rocích / měsících**, expense breakdown podle kategorií,
  reminder effectiveness funnel, payment time histogram.
- **Auto-recompute** `crm_monthly_summary` při stale > 5 min — odpadá ruční
  klik *Přepočítat* po importu.

#### EPO výkazy (DPH a daň z příjmů)
- **DPHDP3** — přiznání k DPH (měsíční / kvartální, respektuje
  `is_vat_payer` + `financial_office_code`).
- **DPHKH1** — Kontrolní hlášení (A.1-A.5, B.1-B.3, ř. 40-43, reverse charge,
  dovoz).
- **DPHSHV** — Souhrnné hlášení (EU intracom dodávky).
- **DPFDP5** (OSVČ) + **DPPDP9** (právnické osoby) — daň z příjmů MVP
  foundation.
- **XML pro EPO portál MFČR** + XSD validace přes `DOMDocument::schemaValidate`
  s libxml error collector.
- **Archiv podání** (`tax_submissions`) — každé generování XML s timestamp +
  summary + status + SHA-256 hash.
- **VAT klasifikace** per řádek položky (`vat_classifications` per-tenant
  + globální seed).

#### Public REST API v1
- **Personal Access Tokens** (PAT) přes Bearer `Authorization`.
- **101 endpointů** v `/api/v1/*` (vystavené + přijaté faktury, klienti,
  zakázky, CRM, výkazy, codebooks, activity).
- **OpenAPI 3.1** spec v `api/openapi.yaml` (50+ paths, 41+ schemas).
- **Swagger UI** `/api/docs` + **Redoc** `/api/reference`.
- Rate limit 600 req/min/token, `X-RateLimit-*` hlavičky.
- Per-token scope (read-only / write), audit log.

#### Admin: Cron jobs monitoring
- **`/admin/cron-jobs`** přehled všech cron skriptů s health badge
  (ok / overdue / failing / never_ran).
- Last run / last OK / duration / status / report JSON.
- **Failed items expandable list** — pro `cron-scan-purchase-inbox` rozbalitelný
  seznam neimportovaných souborů s důvodem (path traversal, AI nedostupné,
  prázdný PDF, …).
- **Manuální *Spustit nyní*** tlačítko, hash katalog s `every_*` + `max_age_hours`.

#### Sample data
- `php api/bin/sample.php` přidává **4 dodavatele** (Anthropic, Microsoft
  Czech, GitHub, Office Pro) + **12 přijatých faktur** rozprostřených přes
  6 měsíců s mixem statusů received/booked/paid + 1-3 položek každá.

### Changed

- **Sidebar** — section headers (PRODEJ / NÁKUP / FINANCE / DANĚ / SYSTÉM)
  jako soft pill badge s barvou sekce (primary / warning / success /
  danger / neutral). Sjednoceno s dashboard section headers (bez tečky,
  jen barevný pill).
- **Dashboard** — KPI rozděleny do 3 sekcí: Vystavené (primary pill),
  Přijaté (warning), Pohledávky podle splatnosti (success).
- **Manual** — renumber `09a → 10` + shift `10-24 → 11-25`, **25 kapitol**
  celkem (z 17). Nové: 10 Přijaté faktury, 23 CRM, 24 Výkazy DPH, 25 Daň
  z příjmů. Hamburger menu pattern pod 1024px (transform translateX).
- **Rate limits** bumped (`cfg.sample.php`): `read_per_min_per_user`
  300 → 1200, `mutation_per_min_per_user` 60 → 120 (CRM dashboard volá
  ~16 GETů paralelně).
- **Manual `index.php`** — `overflow-x: auto` na `.code-block` (dlouhé curl
  ukázky scrollují v rámci své šířky), hamburger menu s `transform:
  translateX(-100%)` pod 1024px.
- **Vendor list** — sloupec *Zakázky* nahrazen *Počet faktur* pro
  `?role=vendors`, multi-currency costs sumace přepočtena na CZK.
- **scrollBehavior** v routeru — `top:0` při navigaci sidebar linky
  (respektuje `savedPosition` pro back/forward + `#hash` anchors).

### Fixed

- **Path-traversal guard** v `DownloadPurchaseInvoicePdfAction` — Windows
  case-insensitive `strtolower()` obou stran (`realpath()` vrací inkonzistentní
  casing).
- **`$imported` undefined variable** v `PurchaseInvoiceInboxScanner` →
  `$created++`.
- **Cron `cron-scan-purchase-inbox` FK constraint** — validace `app.cron_user_id`
  + fallback na nejnižší aktivní admin (FK `purchase_invoices.created_by →
  users.id`).
- **Purchase invoices `?overdue=1`** filter z homepage — `InvoiceList`
  nečetl `route.query` při mountu, fix přes `useRoute()` + auto-clear
  `year` filteru (overdue je cross-year).
- **`isAiConfigured()`** dotazoval neexistující tabulku `anthropic_credentials`
  → fix na `SELECT 1 FROM supplier WHERE id = ? AND anthropic_api_key_enc
  IS NOT NULL`.
- **CRM dropdown width** — `min-w-[200px]` → `w-[280px]` (absolutně pozicovaný
  div bez explicitní šířky se v některých prohlížečích roztahoval na 687px).
- **Cash flow tabulka** na mobilu — wrapper `overflow-x-auto` +
  `min-w-[560px]` na `<table>`.
- **Tailwind warning-700 / success-700** neexistují → změna na `-600`
  (sidebar pill text byl černý).
- **YAML parse error** v `api/openapi.yaml` — `summary: %` → `summary: "%"`
  (% je YAML directive token).
- **AI auto-paid varsymbol** — `markAlreadyPaid()` přímý SQL UPDATE
  obcházel `TransitionPurchaseInvoiceStatusAction` (která generuje varsymbol
  při draft→received). Fix: volá `ensureVarsymbol()` před UPDATE.
- **Vystavené faktury — `vat_classification_code` chyběl** ve výkazech DPH /
  KH. `InvoiceRepository::replaceItems()` neukládala kód vůbec →
  VatClassificationMapper SKIPNUL všechny řádky → DPH přiznání na výstupu
  byly nuly. Centralizovaný fallback v `replaceItems()` + ostatní vstupní
  cesty (Pohoda import, bulk reissue, cancel→credit note).
- **Country-aware auto-klasifikace** pro vystavené i přijaté faktury —
  podle ISO-2 země protistrany:
  - Vystavené: CZ → `'1'`/`'2'`/`'3'`, EU 0 % → `'22'` (služby), non-EU 0 % → `'26'` (vývoz)
  - Přijaté: CZ → `'40'`/`'41'` (+ RC → `'5'`), EU 0 % → `'24'` (acquire EU),
    non-EU 0 % → `'25'` (dovoz)
- **Vendor costs multi-currency** — `pi_agg` sumace v `ClientRepository`
  mixovala EUR + CZK do jednoho totalu. Fix: přepočet přes
  `pi.exchange_rate * total_with_vat` (CZK ccy → multiplier 1).
- **Re-import dedup guard** — `findIdByVendorInvoice()` ve všech importerech
  proti `UNIQUE KEY uq_pi_vendor_invoice` violation (SQL 23000).
- **Pagination v `/purchase-invoices`** — natvrdo `per_page: 200` → load-more
  pattern (analog vystavených), default per_page z `cfg.pagination`.
- **Namespace fix** v `PurchaseInvoiceCnbApplier` — `Service\Cnb` →
  `Service\Currency\CnbExchangeRateClient` (DI container 500 v ISDOC / iDoklad /
  Fakturoid / AI importu).
- **Backfill `--force`** flag — re-classifikace existujících kódů
  (idempotent: skip pokud derived == current).
- **Privacy** — User-Agent v `FakturoidClient` anonymizován z osobního
  e-mailu na URL repa.

### Security

- **AI rate limit** middleware — 30 calls / 5 min / user pro endpointy
  `/api/admin/imports/ai-extract-pdf` a `/api/purchase-invoices/scan-inbox`,
  bucket `rl:ai:user:{id}`. Ochrana před BYOK billing rizikem při
  kompromitované admin session.
- **AES-256-GCM** šifrování citlivých polí v DB (TOTP secret, AI API keys).
- **XML attribute sanitization** v Pohoda export pro XSD compliance.
- **ZIP export streaming** — `Slim\Psr7\Stream` místo `file_get_contents`
  (DoS mitigation pro ~500 × 20 MiB PDF batch).

### Migrations

- `0026_purchase_invoices.sql` — purchase_invoices + purchase_invoice_items +
  purchase_invoice_counters
- `0027_expense_categories.sql`
- `0028_ai_extractions.sql`
- `0029_import_jobs.sql` + `0030_idoklad_attachments.sql` +
  `0031_fakturoid_credentials.sql` + `0032_fakturoid_ids.sql`
- `0033_anthropic_credentials.sql` (per-supplier AI key)
- `0035_crm_support.sql` + `0036_crm_recompute_proc.sql`
- `0037_vat_classifications.sql` + `0038_supplier_tax_settings.sql`
- `0039_tax_submissions.sql`
- `0040_crm_action_item_dismissals.sql`

Všechny migrace jsou idempotentní (MariaDB native `IF NOT EXISTS`).

### Upgrade poznámky

Po deploy spusť:

```bash
php api/bin/migrate.php
php api/bin/backfill-vat-classification.php --apply    # legacy faktury → DPH přiznání
php api/bin/backfill-exchange-rates.php --apply        # non-CZK faktury bez kurzu
php api/bin/backfill-purchase-varsymbols.php --apply   # varsymboly chybí
```

Pak v `/crm` klikni *Přepočítat* aby se aktualizovala `crm_monthly_summary`.

## [3.6.9] — 2026-05-20

### Added

- **Pravidelné fakturace — režim DUZP (`tax_date_mode`).** Nové pole v šabloně
  s hodnotami `same_as_issue` (default — zachovává původní chování) a
  `previous_month_last_day`. Při režimu „poslední den předchozího měsíce" se
  generovaná faktura vystaví dnes, ale DUZP se nastaví na poslední den
  předcházejícího měsíce — typický CZ scénář „fakturuji 1.6. za květnové
  služby, DUZP 31.5.". Migrace `0025_recurring_tax_date_mode.sql`, UI selectbox
  v editoru šablony, manuál § 14.2.2.

- **„Vygenerovat teď" — date picker s defaultem dnes.** Místo `confirm()`
  dialogu se otevře modal s `<input type="date">`. Default je dnešní datum,
  ne `next_run_date` z šablony — opakovaný klik na tlačítko už nevyrobí
  budoucně-datovanou fakturu (tax-wise problematické). Hint pod inputem
  zobrazuje plánovaný cron termín; pokud uživatel zvolí datum v budoucnu,
  zobrazí se žluté varování.

- **Systémové logování DB chyb.** Nové třídy
  `LoggingPdo` / `LoggingPdoStatement` / `DbErrorLogger` —
  PDO subclass, který přes `ATTR_STATEMENT_CLASS` transparentně zachytí každou
  `PDOException` v `prepare()/exec()/query()/execute()`. Loguje strukturovaný
  záznam přes existující Monolog (`log/app-YYYY-MM-DD.log`) s polem
  `{sqlstate, sql, params, caller}`, kde `caller` je první frame mimo
  `Infrastructure\Database` namespace (= skutečný Repository/Action/cron).
  Citlivé parametry (sloupce `password`, `token`, `secret`, `totp_secret`,
  `recovery_codes`) jsou v logu nahrazeny `*** params hidden ***`. Žádné
  callery se neupravují — `$pdo->prepare(...)->execute(...)` se loguje
  automaticky.

### Fixed

- **Pravidelné fakturace — popisek položky se synchronizuje s DUZP, ne
  inkrementuje +1.** Dosud generator slepě přičítal +N měsíců k popisu
  šablony (`PeriodicityCalculator::monthsFor(frequency)`), takže šablona
  „Hosting 05/2026" generovala fakturu „Hosting 06/2026" hned v 1. cyklu —
  a další cykly opakovaly stejnou hodnotu, protože šablonový popis se nikdy
  nemění. Nová třída `MonthSynchronizer::syncTo($desc, $taxDate)` najde
  pattern `M/YYYY` a **nahradí** ho měsícem/rokem z DUZP (`tax_date`),
  případně z `issue_date` u proform. Sync je idempotentní a deterministický:
  faktura na 5/2026 vždy řekne „05/2026", faktura na 6/2026 vždy „06/2026".
  Aktualizován i existující PHPUnit test + přidán test pro
  `previous_month_last_day` mode.

- **Pravidelné fakturace — cron padal na NOT NULL `created_by`.** Při
  generování z cronu byl `$userId = null` (cron nemá session), což končilo
  `SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'created_by'
  cannot be null` a žádná z due šablon se nevygenerovala. Fix: pokud
  `$userId` není explicitně předán (cron kontext), fallback na
  `template['created_by']` (autor šablony) — invoices.created_by i
  activity_log mají konzistentní audit.

- **PDF cache — po `Upravit (admin)` se vrátil starý PDF, dokud uživatel
  nepřidal `?regenerate=1`.** Windows file-lock: PDF otevřené v Chromu
  zabránilo `invalidate()` přesunout starý soubor do `_archive/`
  (rename/unlink silently selhaly kvůli `@`). DB UPDATE `pdf_path=NULL,
  pdf_generated_at=NULL` proběhl, ale stale soubor zůstal na deterministické
  `cachePath()`, kde ho cachePath fallback v `render()` znovu zachytil a
  vrátil. Fix: branch fallbacku teď vyžaduje
  `!empty($invoice['pdf_generated_at'])`, takže po invalidate (která ten
  sloupec nastaví na NULL) se orphan už nezachytí a render projde do
  regenerace.

## [3.6.8] — 2026-05-18

### Security

- **PDF import — zip-bomb hardening (defense-in-depth).** `PdfIsdocExtractor`
  v 3.6.7 volal `gzuncompress()` a `gzinflate()` bez parametru `$max_length`.
  Útočník s admin/accountant rolí mohl vyrobit PDF s extrémně-redundant
  FlateDecode streamem (typicky 20 MiB nul = ~20 KiB compressed) a teoreticky
  vyčerpat memory PHP procesu během dekomprese. Post-check
  `strlen($inflated) > MAX_DECOMPRESSED_BYTES` proběhl až po alokaci, takže
  byl k ničemu. Riziko bylo zmírněné existujícími upload limity (20 MiB/file,
  50 MiB total, admin/accountant gate), ale ne odstraněné.

  Fix: `gzuncompress($stream, MAX_DECOMPRESSED_BYTES)` a obdobně pro
  `gzinflate` — dekomprese se zastaví na 10 MiB a vrátí `false`. Doplněn
  regression test `testRejectsZipBombFlateDecodeStream` (20 MiB nul jako
  vstup, ověření že memory delta extrakce < 15 MiB).

## [3.6.7] — 2026-05-18

### Added

- **Import faktur z PDF (PDF/A-3 s embedded ISDOC).** V `/admin/imports`
  jde teď nahrát i PDF soubor s embedded `*.isdoc` přílohou — typický
  výstup českých fakturačních systémů (**iDoklad**, **Fakturoid**,
  **Superfaktura**, **Pohoda**, **MyInvoice**). Nová služba
  `PdfIsdocExtractor` rozpozná PDF/A-3 attachment, najde ISDOC podle
  filename (`*.isdoc`, ne nutně `invoice.isdoc`) nebo přes content-sniff
  ISDOC namespace, dekomprimuje FlateDecode stream a předá XML
  existujícímu `IsdocParser`. Robustní vůči různým producentům: testováno
  na mPDF (vlastní), Superfaktura (ISDOC 6.0.2) i iDoklad (ISDOC 6.0.1
  s octet-stream Subtype a custom filename `Vydaná faktura-…isdoc`).
  Pokud PDF embedded ISDOC nemá, uživatel uvidí čitelnou chybu „PDF
  neobsahuje ISDOC přílohu". 7 nových unit testů `PdfIsdocExtractorTest`.
  Frontend `accept` přijímá `.pdf` + `application/pdf`; manuál § 16.6
  popisuje jak ověřit, že PDF přílohu má (Adobe Reader / `pdfdetach`).

### Fixed

- **ISDOC import — round-trip MyInvoice → ISDOC → MyInvoice ztrácel data.**
  Exporter byl ve v3.6.2 přepracován na schema-validní ISDOC 6.0.2
  (`<ForeignCurrencyCode>` místo `<CurrencyCode>`, wrapper
  `<OrderReferences>/<OrderReference>/<SalesOrderID>` místo
  `<OrderReference>/<ID>`, `<ContractReferences>/<ContractReference>/<ID>`,
  rozdělená adresa `<StreetName>` + `<BuildingNumber>`), ale `IsdocParser`
  zůstal na legacy cestách — při importu vlastního ISDOC souboru se
  ztratila cizí měna (fallback na CZK), `project_number` i číslo popisné
  v adrese. Parser teď čte schema-validní cesty jako primární a legacy
  jako fallback, takže zůstává kompatibilní i s ISDOC od jiných systémů.
  Pokryto 7 novými unit testy v `IsdocParserTest`.

## [3.6.6] — 2026-05-18

### Added

- **Systém → Plánované úlohy** (admin). Nová stránka ukazuje doporučený
  seznam cron skriptů (`cron-cleanup`, `cron-backup`, `cron-backup-pdf`,
  `cron-bank-scan`, `cron-send-reminders`, `cron-send-approval-reminders`,
  `cron-generate-recurring-invoices`, `cron-version-check`), kdy každý
  z nich naposled běžel, jak dopadl a kolik chyb měl za posledních 24 h.
  Pokud poslední úspěšný běh chybí nebo je starší než doporučená frekvence
  (`max_age_hours`), úloha je označená *Stáří* / *Selhává* / *Neběželo*.
  Detekuje "cron není nastavený" i "cron běží, ale failuje" napříč Linux
  cron / Windows Task Scheduler / Docker — bez čtení OS-level konfigurace.
  Implementace: nová tabulka `cron_runs` (migrace `0024`) + helper
  `MyInvoice\Service\Cron\CronRun` (`::start()` při startu, `->finish()`
  při konci, `register_shutdown_function` jako safety net pro `exit(1)` /
  fatal errors), katalog `CronCatalog`, endpoint `GET /api/admin/cron-jobs`.
  Refresh každých 60 s. Cleanup starých záznamů (`cron_runs_purged`)
  v `cron-cleanup`, default držíme 500 posledních běhů na skript.
- **Validace email šablony před uložením.** `PUT /api/admin/email-templates/{code}/{locale}`
  teď před uložením zkusí šablonu pre-renderovat přes sandbox a vrátí
  čitelnou chybu (s názvem pole `body_html`/`body_text`), pokud najde
  nepovolený tag (`Tag „include" není povolený…`), nepovolený filtr
  (`Filtr „|url_encode" není povolený…`), nepovolenou funkci nebo
  syntaktickou chybu (`Chyba syntaxe (řádek N): …`). Uživatel tak vidí
  problém okamžitě v adminu, místo aby narazil na runtime crash teprve
  při odeslání emailu — follow-up issue #25.
- **Hromadné „Vystavit" pro koncepty** v seznamu faktur. Nová bulk akce
  v `/invoices` umožňuje označit více konceptů a jedním kliknutím je
  vystavit. Pořadí vystavení respektuje `issue_date`, aby varsymboly
  zůstaly sekvenční (žádný „přeskakovaný" var. symbol uvnitř dávky).
- **Automatický přepočet splatnosti** v editoru faktury a v pravidelné
  fakturaci:
  - **InvoiceEditor:** u draftu/nové faktury při změně **Vystaveno**
    přepočti **Splatnost** podle defaultu klienta nebo zakázky
    (zakázka přebíjí klienta).
  - **RecurringForm:** po výběru klienta převzít jeho
    `payment_due_default`; následný výběr zakázky přebije projektovou
    hodnotou. V edit módu se hodnota uložené šablony při hydrataci
    nepřepisuje.
- **VIES kontrola DIČ klienta v editoru pravidelné fakturace.** Stejná
  validace jako v `InvoiceEditor` — po výběru klienta se DIČ ověří proti
  VIES a uživatel hned vidí, jestli je platné, neplatné, klient DIČ nemá,
  nebo VIES služba odpověděla chybou. Zrcadlí `verifyClientVies()`
  v `InvoiceEditor.vue`.

### Fixed

- **Dashboard — cash-flow forecast: počty faktur pro různé měny se slévaly
  do jednoho řádku** (např. `6 faktur4 faktur` místo dvou samostatných
  řádků pro CZK a EUR). Renderovalo se přes `<span v-for>` bez oddělovače;
  částky vedle používají `<div v-for>` se `space-y-0.5`, counts to teď
  zrcadlí.
- **Dashboard — cash-flow forecast hint: nepřeložený `due_date`** v textu
  („Z neuhrazených faktur s due_date v daném okně.") → „se splatností"
  v `cs`, „due date" v `en`.
- **Pravidelná fakturace — přepočet `next_run_date` při editaci šablony,
  která ještě neběžela.** `RecurringTemplateRepository::update()` nikdy
  nepřepisoval `next_run_date` — ten se nastavil jen jednou při create
  (= `anchor_date`). Když uživatel editoval šablonu před prvním
  spuštěním (změna `end_of_month` / `day_of_month` / `anchor_date`),
  první generování zůstalo viset na původní hodnotě. Pro šablony
  s `last_run_date` necháváme cyklus naplánovaný (posouvá ho
  `PeriodicityCalculator` po každém generování).

---

## [3.6.5] — 2026-05-14

### Fixed

- **Email šablona — render selhával po jakékoli úpravě
  ([#25](https://github.com/radekhulan/myinvoice/issues/25)).** Po editaci
  šablony v `/admin/email-templates` (DB override) selhalo odeslání chybou
  `Tag "block" is not allowed in "_layout.html.twig" at line 63.`. Sandbox
  v `Mailer::sandboxedTwig()` neumožňoval `block`/`extends`/`use`, takže
  rendering DB šablony, která dědí z `_layout.html.twig`, byl odmítnut.
  Tyto tagy jsou čistě strukturální a `FilesystemLoader` je rooted v
  `api/templates/email/`, takže jejich povolení neotvírá SSTI vektor.
  Doplněn unit test `MailerSandboxRenderTest`.

---

## [3.6.4] — 2026-05-14

### Added

- **Inline vytváření klienta a zakázky z editoru faktury.** Vedle pickeru
  klienta a pickeru zakázky v editoru faktury (`/invoices/new` i
  `/invoices/{id}/edit`) jsou nová tlačítka „+ Nový klient" a „+ Nová".
  Klik otevře modální okno s plnou formou (ARES, VIES, billing emails,
  měna, jazyk, splatnost, hodinová sazba, …). Po uložení se modal zavře,
  nová entita se přidá do seznamu a **automaticky vybere** v pickeru;
  rozepsaná faktura se neztratí. Tlačítko pro zakázku je aktivní pouze,
  když je vybraný klient (pre-fillne `client_id`, měnu a sazby).
- **Stejný flow v editoru pravidelné fakturace** (`/recurring/new` i
  `/recurring/{id}/edit`).
- **Sdílené komponenty:** `components/ui/Modal.vue` (generic modal —
  Teleport, ESC close, click-outside, sticky header + scroll body),
  `components/modals/ClientFormModal.vue`,
  `components/modals/ProjectFormModal.vue`.
- **Embedded mode pro existující formuláře.** `ClientForm.vue` a
  `ProjectForm.vue` dostaly props `embedded`, `defaults`/`clientId` a
  emity `created` / `cancel`. V embedded módu skrývají vlastní header
  a místo `router.push` vrací výsledek přes event — což umožňuje jejich
  reuse v jakémkoli modálním okně bez duplikace kódu.

### Fixed

- **InvoiceEditor — duplicitní „+" u tlačítka „+ Nový klient".** i18n
  hodnota `client.new` obsahuje `"+ Nový klient"`, vedle ní byla ještě
  SVG plus ikona → uživatel viděl `++`. Tlačítko teď používá
  `client.new_title` (`"Nový klient"` bez plusu).

---

## [3.6.3] — 2026-05-14

### Added

- **Nová stránka „Grafy"** (`/stats`) — kompletní reporting hub. Položka
  v hlavním menu mezi Zakázky a Faktury. Obsahuje:
  - 9 KPI tilů (3×3 grid): plovoucí 12měsíční obrat s DPH-limit indikátorem
    (2 000 000 Kč pro CZK plátce), obrat letošek/loni s YoY %, **Forecast
    aktuálního roku** (growth-adjusted seasonality — YTD + sezonalita loni
    × YoY růst), počet faktur/klientů/zakázek per měna per rok, počet
    aktivních klientů + recurring šablon, Ø doba úhrady, obrat 30 dní.
  - Grafy: měsíční obrat 12m bar + prev-year linka, **kumulativní YTD vs
    loni** (CumulativeYtdChart), Top klienti koláč YTD + loni, Top zakázky
    bar YTD + loni, status donuty (faktury + zakázky).
  - **Concentration risk** — % obratu z TOP3/TOP5 klientů za rolling 12m
    s 3 úrovněmi warning (≥50/70 % a ≥70/90 %).
  - **Histogram doby úhrady** — 0-7 / 8-14 / 15-30 / 30+ dní + Ø dní.
  - **DPH rozpad obratu** — donut per sazba (21 % / 12 % / 0 % / RC),
    pouze pro plátce DPH.
  - **Cash-flow YTD** — kumulativní křivka skutečných plateb (`paid_at`)
    letošek vs loni; doplňuje obrat o reálné inkaso.
  - **Aging report** — stáří pohledávek (current / 1-30 / 31-60 / 61-90 /
    90+ dní) per měna, stacked horizontal bar + číselná tabulka.
  - **Distribuce velikosti faktur** — bar chart 0-5k / 5-25k / 25-100k /
    100k+ Kč (CZK ekvivalent přes uložený `exchange_rate`).
  - Číselné tabulky: obrat po rocích (s forecast řádkem nahoře),
    obrat po měsících (12), Top 12 klientů + zakázek za rolling 12m.
- **VAT-aware obrat** napříč celou aplikací. Plátci DPH
  (`supplier.is_vat_payer = 1`) vidí ve všech statistikách / grafech /
  cache `total_without_vat` (relevantní pro DPH limit a fair reporting);
  neplátci `total_with_vat`. Týká se Dashboard, Grafy, detail klienta,
  detail zakázky a cache tabulek (`client_revenue_cache` /
  `project_revenue_cache`).
- **Detail klienta — graf „Obrat podle zakázek"** + číselná tabulka
  agregovaná per zakázka. Klikatelné na detail zakázky. Faktury bez
  `project_id` agregované pod „(bez zakázky)".
- **Dashboard — oživení homepage:**
  - Sparkline 12měsíční obrat (mini bar chart) pod částkou v KPI tile
    „Obrat 2026 (CZK)".
  - **Cash-flow forecast** — 3 boxy „Co přiteče z neuhrazených faktur
    v příštích 30 / 60 / 90 dnech" per měna.
  - **Splatnost karty** — Splatné dnes / tento týden / tento měsíc
    (kumulativně) s warning barvou pro „dnes > 0".
  - „Top klienti — 12 měsíců" tabulka teď vlevo na 50 %, vedle ní
    doughnut graf se stejnými daty (Top 8 + Ostatní).
- **Reorganizace navigace** — „Banka" přesunuta do submenu Systém
  (za Dodavatelé). Top-level highlight Systém zahrnuje i `/bank/*` route.
- **Nové komponenty grafů:** `CumulativeYtdChart`, `SparklineChart`,
  `PaymentDaysHistogramChart`, `VatBreakdownChart`, `AgingChart`,
  `InvoiceSizeChart`.
- **Nové stored procedures + cache rebuild** — `sp_recompute_*`
  přepsány aby používaly VAT-aware sloupec dle dodavatele
  (JOIN `supplier` ON `is_vat_payer`). Migrace `0023` automaticky volá
  `sp_recompute_all_caches()` po nasazení.

### Fixed

- **Forecast ročního obratu — matematická duplikace s rolling 12m.**
  Předchozí vzorec `YTD + prev_year_remainder` dával identický výsledek
  jako plovoucí 12měsíční obrat (stejné kalendářní okno, jen rozdělené).
  Nový vzorec: `forecast = YTD + (prev_year_remainder × growth_ratio)`,
  kde `growth_ratio = YTD_letos / YTD_loni_do_stejného_dne` (cap [0.3, 3.0]).
  Predikce zbytku roku z loňské sezonality, škálovaná aktuálním YoY růstem.
- **`SummaryAction::activeRecurringCount` — špatný název tabulky.**
  Použito `recurring_templates`, správně je `recurring_invoice_templates`.
- **OpenAPI YAML parser error** na ř. 2147 — české uvozovky `„…"` uvnitř
  `"…"` YAML stringů (ASCII `"` předčasně ukončoval string).
  Přepsáno na single-quote YAML stringy.

### Documentation

- **OpenAPI 3.1 spec rozšířena** o všechny nové fieldy v
  `/dashboard/summary` (cca 20 nových sekcí response) a `/projects/stats`
  (`top_12m`, `is_vat_payer`). Nová schemas:
  - `TopClient`, `MonthBucket` (sdílené reusable schemas)
  - `ProjectStats` + `ProjectStatsBlock` (pro `/projects/stats`)
  - `ClientDetail` (`allOf` extend Client + revenue agregace pro
    `/clients/{id}`, vč. nového `revenue_by_project`)
  - `DashboardSummary` doplněn o `top_clients_12m`, `revenue_by_year`,
    `rolling_12m`, `revenue_last_30d`, `revenue_forecast`, `cashflow_ytd`,
    `cashflow_forecast`, `due_buckets`, `aging_report`,
    `payment_days_histogram`, `vat_breakdown_12m`,
    `invoice_size_histogram`, `active_clients_count`,
    `active_recurring_count`, `is_vat_payer`.

### Migration

- **`0023_revenue_vat_aware.sql`** — `DROP + CREATE` pro
  `sp_recompute_client_revenue`, `sp_recompute_project_revenue`,
  `sp_recompute_all_caches`. Nový JOIN na `supplier` a `CASE WHEN
  is_vat_payer = 1 THEN total_without_vat ELSE total_with_vat END`.
  Idempotentní; volá `CALL sp_recompute_all_caches()` na konci pro
  okamžitý přepočet existující cache.

---

## [3.6.2] — 2026-05-14

### Added

- **ISDOC příloha v PDF faktuře.** Při generování PDF se přibalí strojově
  čitelný `invoice.isdoc` (ISDOC 6.0.2 XML) jako PDF/A-3 attachment (`/AF` +
  `/Names /EmbeddedFiles` v catalog). České účetní programy (Money S3, Pohoda,
  Helios, …) si data extrahují přímo z PDF — uživatel přepošle jediný soubor
  místo zvlášť PDF + ISDOC. Adobe Reader / Foxit zobrazí ikonu sponky v
  Attachments panelu. Pod variabilním symbolem se vykreslí vizuální `ISDOC`
  badge. Vkládá se jen pro **CZK faktury s přiděleným VS** — gating přes
  nový `supplier.embed_isdoc` (default zapnuto), lze vypnout v *Nastavení →
  Dodavatel* (migrace `0022_supplier_embed_isdoc.sql`).

### Fixed

- **ISDOC export — neplatná XSD struktura.** Refactor `IsdocExporter::buildXml`
  proti oficiální XSD 6.0.2 (z mv.gov.cz/isdoc). Předchozí výstup byl
  schema-INVALID a Money S3 / Helios ho odmítaly. Změny:
  - Přidán povinný `<ElectronicPossibilityAgreementReference/>` mezi
    `VATApplicable` a `LocalCurrencyCode`.
  - `<CurrencyCode>` → `<ForeignCurrencyCode>` (pouze pro non-CZK faktury).
  - Odstraněn nelegální `currencyID` atribut na amount elementech.
  - `<OrderReference>` zabalený do `<OrderReferences>`, obsahuje `<SalesOrderID>`
    místo `<ID>` + povinný `@id` atribut.
  - `<ContractReference>` v `<ContractReferences>` + `<IssueDate>` + `@id`.
  - `<PostalAddress>` rozdělen na `<StreetName>` + povinný `<BuildingNumber>`.
  - V `<TaxSubTotal>` použít `<TaxCategory>` (ne `<ClassifiedTaxCategory>` —
    to zůstává v `InvoiceLine`).
  - V `<PaymentMeans>/Details` odstraněn vnořený `<BankAccount>` wrapper —
    BankAccount group (`ID`/`BankCode`/`Name`/`IBAN`/`BIC`) je inline.
  - Validováno proti `isdoc-invoice-6.0.2.xsd` přes `lxml.etree.XMLSchema`.
- **ISDOC — prázdné adresy u legacy faktur.** `IsdocExporter::resolveSupplier`
  + `resolveClient` teď načtou live data ze `supplier` / `clients` tabulek
  a snapshot wins přes `array_merge`. Předchozí logika brala snapshot as-is
  → cizí/legacy snapshoty bez `street/city/zip` vyrobily ISDOC s prázdnou
  adresou (sledovatelné v `c:\tmp\Faktura-2604009.pdf` reference).
- **Pohoda XML — stejný snapshot bug.** `PohodaXmlExporter::resolveClient`
  dostal stejný defensive-merge pattern jako ISDOC. Pohoda XML teď encoduje
  v **UTF-8** (původně `Windows-1250` z historických důvodů — moderní Pohoda
  2010+ UTF-8 akceptuje, žádné mojibake na exotičtější diakritice).
- **PDF rendering — stejný snapshot bug.** `InvoicePdfRenderer::resolveClient`
  + `resolveBank` dostaly defensive-merge live + snapshot. Týká se i
  hromadných PDF ZIP exportů (`admin/export`) — cizí snapshoty (import
  z ISDOC/Pohody) měly potenciálně neúplnou adresu v PDF.

### Added (ISDOC obsah)

- **IBAN dopočítaný** z `account_number` + `bank_code` přes `CzechIbanAdapter`
  (mod-97 check digits). Pokud uživatel má `iban` explicitně v `currencies`,
  má přednost.
- **BIC z mapy** 36 nejčastějších CZ bank kódů (ČNB číselník 2026,
  např. `0300 → CEKOCZPP`, `2250 → CTASCZ22`).
- **`<IssuingSystem>MyInvoice.cz</IssuingSystem>`** — root level, identifikace
  generátoru pro debugging na straně účetního SW.
- **`<RegisterIdentification><Preformatted>`** — zápis v obchodním rejstříku
  z `supplier.commercial_register` (např. „Spisová značka C 45039 vedená
  u Krajského soudu v Plzni").

### Internal

- Nová migrace `0022_supplier_embed_isdoc.sql` (idempotentní,
  `ADD COLUMN IF NOT EXISTS`). Default `1` = vkládat ISDOC do PDF.
- `IsdocExporter` dostává `Connection` přes DI (potřeba pro live merge).
- PHPUnit 264/264 PASS, `vue-tsc --noEmit` clean, ISDOC výstup
  schema-VALID proti oficiální XSD 6.0.2.

---

## [3.6.1] — 2026-05-14

### Added

- **Slevové položky na faktuře** ([PR #24](https://github.com/radekhulan/myinvoice/pull/24)).
  Položka může mít zápornou cenu nebo zápornou množství (sleva, dobropis-jako-řádek)
  za podmínky, že **celková částka faktury zůstane kladná** — nedá se vystavit faktura
  s nulovým nebo záporným celkem. Validace `InvoiceAmountPolicy` je společná pro
  invoice + recurring template; per-item chyby se hlásí dohromady (uživatel vidí
  všechny v jednom round-tripu).
  - Nový červený highlight řádku v editoru když má položka **současně** záporné
    `qty` i `unit_price` (oboje záporné = math je sice kladné, ale je to skoro
    vždy překlep).
  - `canBeMarkedPaid()` honoruje `parent_invoice_id` — finální daňový doklad
    k zaplacené proformě má `amount_to_pay=0` by design; mark-paid + bank-match
    nadále fungují jako legitimní bookkeeping.
  - Hint „negativní položky jsou OK pokud celkem > 0" se skryje u dobropisů
    (`credit_note`), kde se očekává záporný total.

### Fixed

- **Recurring detail — chybějící součty.** `/recurring/{id}` teď pod tabulkou
  položek zobrazuje **Bez DPH / DPH / Celkem** spočtené z položek šablony
  (respektuje `reverse_charge`). Dosud se daly vidět jen řádky s jednotkovou
  cenou, ale ne kolik vlastně bude faktura stát.
- **Recurring form — den v měsíci se nepředvyplňoval.** Při zakládání nové
  šablony se `day_of_month` autoplnil dnem z `anchor_date` (capped na 28).
  Doposud zůstal prázdný a pak na pozadí backend padal na fallback „den
  z anchor_date" — což nebylo z UI vidět a uživatelé to mylně chápali jako
  default `1.`. Při změně `anchor_date` se prázdný den znovu doplní; ručně
  zadaná hodnota se nepřepisuje.

### Internal

- Test refactor: `InvoiceAmountRegressionTest` → `InvoiceAmountSourceGuardsTest`
  v nové testsuite `Architecture` (phpunit.xml). Test čte zdrojový kód a hlídá
  call-sity — není to runtime test, je to static lint. 264/264 PHP testů PASS,
  `vue-tsc --noEmit` clean.

---

## [3.6.0] — 2026-05-13

### Breaking — Docker volume layout

> ⚠️ **MIGRACE pro Docker uživatele 3.5.x a starší.** Default Compose layout
> přechází na **single-volume** (`app-data:/data`) místo dřívějších tří separátních
> volumes (`app-log`, `app-storage`, `app-private`). `cmd/docker-update.{sh,ps1}`
> autodetekuje starý layout a **automaticky spustí migraci** před `up -d` — staré
> volumes zůstávají nedotčené (ručně k smazání po ověření). DB volume (`db-data`)
> není migrací dotčen.
>
> **Pokud spouštíš update ručně** (`docker compose pull && up -d` bez `docker-update`),
> spusť před tím `cmd/docker-migrate-volumes.{sh,ps1}` ručně — jinak po `up -d`
> uvidíš prázdnou app (data zůstanou ve starých volumes, ale aplikace je nenamountnula).

### Fixed

- **#23 — Origin nesedí s app URL po `docker-update.sh`** ([issue #23](https://github.com/radekhulan/myinvoice/issues/23)).
  Setup wizard ve 3.4.2+ zapisoval auto-detekované `app.url` a `auth.require_totp`
  do `/var/www/html/cfg.local.php` v image filesystému kontejneru. Po `docker-update.sh`
  (= `docker compose pull && up -d` = recreate kontejneru) soubor zmizel a `app.url`
  se vrátila na default `http://localhost:8080` z `cfg.docker.php`. CSRF `Origin`
  check pak odmítl všechny POST requesty z LAN IP s `origin_mismatch`.
  - `CfgLocalWriter` má nový helper `resolveTargetDir()`, který preferuje
    `MYINVOICE_DATA_DIR` (single-volume) před repo rootem. `SetupAction`,
    `bin/setup.php` a `bin/reset.php` ho používají.
  - Default Docker Compose layout přechází na single-volume, `cfg.local.php`
    leží v perzistentním `app-data:/data` volumu a přežije image updaty.

### Changed

- **`docker-compose.yml` + `docker-compose.production.yml`** používají
  single-volume layout: `app-data:/data` + `MYINVOICE_DATA_DIR=/data` env.
  Staré 3 volumes (`app-log`, `app-storage`, `app-private`) zanikly v default
  compose souboru. Volitelný `docker-compose.single-volume.yml` override byl
  odstraněn jako redundantní.
- **`cmd/docker-update.{sh,ps1}`** autodetekuje starý 3-volume layout a před
  `up -d` automaticky spustí `docker-migrate-volumes` (s prominentním banner
  warningem). Bez detekce starého layoutu (fresh installs, post-migrate updaty)
  běží jako dřív.
- **`cmd/docker-migrate-volumes.{sh,ps1}`** přidávají snapshot `cfg.local.php`
  z běžícího 3.5.x kontejneru přes `docker cp` před `down` — soubor se po
  migraci obnoví v novém `app-data` volumu (přežijí tak `app.url` a
  `auth.require_totp` z původního setupu). Skript taky sám spustí `up -d` na
  konci místo aby instruoval uživatele.
- **`cmd/docker-update-watcher.{sh,ps1}`** dynamicky detekují cestu k
  `storage/upgrade-{requested,inflight,result}.json` v kontejneru přes
  `printenv MYINVOICE_DATA_DIR` — funkční ve 3-volume i single-volume layoutu.

---

## [3.5.1] — 2026-05-13

### Security

Bezpečnostní release zaměřený na 4 nálezy z externí code review.
**Reportoval [@andrejtomci](https://github.com/andrejtomci)** — díky za detailní
reports s reprodukčními kroky a navrhovanými opravami.

- **High (8.1) — Cross-tenant bank transaction tamper (CWE-639 BOLA + CWE-778
  insufficient logging).** `BankStatementAction::manualMatch`, `unmatch`
  a `ignore` ověřovaly jen invoice ownership (resp. nic); `txId` z URL
  nebyl scopovaný na supplier. Authenticated `accountant` z S1 mohl napárovat
  / odpárovat / tiše „ignore" bank-tx S2 (a navíc `ignore` nezapisoval do
  `activity_log` — silent destructive op).
  - Přidán helper `txBelongsToCurrentSupplier()` který přes JOIN
    `bank_transactions → bank_statements → currencies` ověří, že transakce
    patří aktuálnímu supplier-i (přes účet supplier-a). Všechny 3 mutující
    metody (`manualMatch`, `unmatch`, `ignore`) ho teď volají hned na začátku.
  - `ignore` teď zapisuje `bank.tx_ignore` action do `activity_log` s
    `previous_status` a `previous_invoice_id` (forensic trace).

- **High (6.2) — Arbitrary local file read via `logo_path` mass-assignment
  (CWE-915 + CWE-22 + CWE-538).** `SettingsAction::updateSupplierById` měl
  `logo_path` a `signature_path` v mass-assign whitelistu bez validace
  cesty. `EmailBrandingAction::preview` neměl admin role guard a četl
  `file_get_contents($supplier['logo_path'])` → base64 v inline `<img>`
  data: URI. Pre-existing chain: admin (malicious nebo compromised) podstrčí
  cestu → libovolný auth user (i `readonly`) si přečte `cfg.php` →
  exfiltruje `app.pepper`, `secret_encryption_key`, `db.password`, SMTP creds.
  - `logo_path` a `signature_path` odebrány z mass-assign whitelistu
    v `SettingsAction`. Logo lze měnit jen přes `EmailBrandingAction::uploadLogo`
    (multipart upload procházející `SupplierLogoConverter`).
  - `EmailBrandingAction::preview` má teď admin role guard (defense-in-depth
    pro případ jiné cesty plant).
  - Nový helper `\MyInvoice\Service\Mail\SafeLogoPath::resolve()` validuje
    cestu: musí být `storage/supplier-logos/sup-{ID}.{png|svg|jpg|...}`,
    extension allowlist, `realpath()` rejection mimo `storage/supplier-logos/`,
    žádné null bytes / `..` traversal. Použito v 3 sinks: `Mailer::sendTemplate`
    (`embedFromPath`), `Mailer::addLogoDisplaySize` (`getimagesize`),
    `EmailBrandingAction::preview` (`file_get_contents`).

- **Medium (5.4) — HTML injection v outbound emailu přes importovaný
  `varsymbol` + `{{ intro|raw }}` (CWE-20 + CWE-79).** `InvoiceImportService`
  neaplikoval `InvoiceValidation::invoice()` ani charset whitelist na
  varsymbol z ISDOC/Pohoda XML. `InvoiceEmailVarsBuilder::build` skládal
  `intro` jako string s embedovaným `<strong>č. {VS}</strong>` a šablony
  `invoice_send.{cs,en}.html.twig:8` ho renderovaly přes `{{ intro|raw }}` —
  bypass Twig autoescape. Útočník (`accountant` z libovolného tenanta) mohl
  nahrát fakturu s varsymbolem `<a href=//evil.tld>` (16 znaků = fitne do
  `VARCHAR(20)`) a klient pak dostal DKIM-podepsaný e-mail s útočníkovým
  HTML — phishing-laundering přes legitimní mail-from authority. JS se
  v moderních mail klientech neexecutuje (stripping), takže to není stored
  XSS, ale realistický phishing primitive.
  - **Gateway fix**: `InvoiceImportService::processOne` validuje varsymbol
    proti `^[A-Za-z0-9_-]{1,20}$` — neplatný varsymbol → import řádek
    `failed` s důvodem.
  - **Sink fix**: šablony už nepoužívají `{{ intro|raw }}`. Místo toho
    `<p>{{ intro_prefix }} <strong>č. {{ invoice.varsymbol }}</strong>.</p>`
    kde `intro_prefix` je plain text z PHP, `<strong>` static markup
    v šabloně a `varsymbol` projde Twig autoescape (HTML entities). EN
    šablona používá `No.` místo `č.`.
  - **Defense-in-depth na parity sinks**: `InvoicePdfRenderer::cachePath` +
    `WorkReportPdfRenderer` filesystem path (sanitize `[^A-Za-z0-9_-]` →
    `_`); ZIP entry names v `ExportAction` + `InvoicesZipAction` (zip-slip);
    CSV cell escaping v `ExportCsvAction` (OWASP formula injection guard:
    prefix `'` u buněk začínajících `=`, `+`, `-`, `@`, TAB, CR).

- **Medium (4.3) — WorkReport cross-supplier `project_id` (parity miss
  MS-P1-1, CWE-639).** `SaveWorkReportAction` ověřoval invoice ownership
  ale `project_id` z body předával na `WorkReportRepository::save()` bez
  scope checku. Accountant z S1 mohl uložit work_reports řádek s
  `project_id` ze S2 (silent FK drift; žádný API endpoint dnes nepivotuje
  na `wr.project_id`, takže to je latentní problém pro budoucí
  aggregátory). Fix mirruje MS-P1-1 (Invoice→Project edge): inject
  `ProjectRepository`, validace `SupplierGuard::owns($request, $project)`
  + belt-and-braces check `project.client_id == invoice.client_id`.

### Internal

- Nový integration test `SecurityFixesTest` (8 testů, ~30 assertions)
  ověřuje že každý fix je trvale uzamknutý (regression guard).
- Nový unit test `SafeLogoPathTest` (8 testů) pokrývá rejection cases —
  traversal, null bytes, wrong prefix, wrong supplier_id, wrong extension.
- Celkem testů: **240** (197 unit + 43 integration).

---

## [3.5.0] — 2026-05-13

### Added

- **Pravidelné fakturace (recurring invoices)** — šablony pro automatické
  generování faktur v zadaných intervalech (issue #21).
  - Migrace 0021 — nové tabulky `recurring_invoice_templates` +
    `recurring_invoice_template_items`, sloupec `invoices.recurring_template_id`
    (ON DELETE SET NULL), per-supplier kill-switch `supplier.auto_generate_recurring`.
  - Periodicita: měsíčně / čtvrtletně / pololetně / ročně + volba „poslední
    den měsíce" (28/29/30/31 dynamicky) nebo konkrétní `day_of_month` (1–28).
    `end_date` volitelně — šablona po něm sama přejde na status `expired`.
  - Per-šablona přepínače `auto_issue` (rovnou vystavit) + `auto_send_email`
    (rovnou odeslat klientovi). Default obojí ON = full automation.
  - Cron `api/bin/cron-generate-recurring-invoices.php` + wrappery
    `cmd/cron-generate-recurring-invoices.{cmd,sh}`. Catch-up logic: po
    výpadku cronu se generuje jen jedna faktura na cyklus.
  - REST API `/api/recurring/*` (8 endpointů: list/get/create/update/delete/
    pause/resume/run-now + `GET /api/recurring/{id}/invoices`).
  - UI: nová sekce **Systém → Pravidelné fakturace** (list + form + detail
    stránka se seznamem vygenerovaných faktur). Tlačítko **Vytvořit šablonu
    z této faktury** v detailu faktury (pre-fill ze stávající faktury). Badge
    „↻ Pravidelná" na vygenerovaných fakturách s odkazem na šablonu.
  - Responzivní list (md break-point: desktop tabulka / mobile karty).
  - Měsíc-increment v popiscích položek funguje pro všechny periodicity
    (monthly +1, quarterly +3, semi_annually +6, annually +12 měsíců).
  - Manuál: nová kapitola 14.

- **`payment_method` na fakturách** — ENUM `bank_transfer` / `card` / `cash`
  / `other` (migrace 0020). U non-bank-transfer se v PDF/emailu nezobrazí
  QR kód ani bankovní spojení; reminder cron + UI tlačítka „Odeslat
  upomínku" non-bank-transfer faktury přeskakují (manual + bulk + cron).

### Fixed

- **Faktura označená jako „uhrazeno" zobrazovala v PDF a e-mailu výzvu
  k platbě a QR kód** (issue #21 part 1). `InvoicePdfRenderer` a
  `InvoiceEmailVarsBuilder` teď respektují `status='paid'` — místo
  „K úhradě X Kč" se zobrazí zelený stamp „UHRAZENO" + datum úhrady;
  v e-mailu poznámka „Faktura již byla uhrazena, neplaťte prosím znovu."
- **Mark/Unmark Paid akce neinvalidovaly cached PDF** → starý PDF se
  dál servíroval. `MarkPaidAction` + `UnmarkPaidAction` teď volají
  `InvoicePdfRenderer::invalidate()`.
- **Smazání dodavatele padalo na cyklický FK** mezi `supplier` a
  `currencies` (`supplier.default_currency_id` ↔ `currencies.supplier_id`,
  oba NOT NULL bez ON DELETE). `SettingsAction::deleteSupplierById` teď
  uvnitř transakce dočasně vypne `FOREIGN_KEY_CHECKS` a hned po smazání
  zase zapne v `finally` bloku — řízený cleanup zůstává bezpečný díky
  předchozím kontrolám (last supplier guard, žádní klienti, žádné faktury).
- **`tools/renumberManual.php`**: `[\w./-]` char class padal na
  „Unknown modifier '-'" — `/` uvnitř char class musí být escapnuté
  i když je delimiter `/`.

### Changed

- **Refactor**: `BulkReissueAction::incrementMonthInString()` extrahováno do
  `MyInvoice\Service\Invoice\MonthIncrementer::increment($text, $months=1)`
  pro sdílení s `RecurringInvoiceGenerator`. Wrapper na `BulkReissueAction`
  zachován pro zpětnou kompatibilitu.
- **Manuál — přečíslování kapitol**: kapitola 14 = Pravidelné fakturace
  (nová), 15+ posunuto o jedno (Exporty 14→15, Importy 15→16, Multi 16→17,
  Nastavení 17→18, Bezpečnost 18→19, Aktualizace 19→20, API 20→21).
  FAQ ponecháno na 99. Auto-aktualizováno přes `tools/renumberManual.php`.

### Internal

- Nové unit testy: `PeriodicityCalculatorTest` (11 testů, edge cases EOM
  přes 28/29/30/31, leap year, year-rollover), `MonthIncrementerTest`
  (rozšířený increment o N měsíců pro quarterly/annually).
- Nový integration test `RecurringGeneratorTest` (3 testy, 27 assertions) —
  end-to-end ověření že cron skutečně vytvoří fakturu, vystaví ji, zkopíruje
  položky a posune `next_run_date`.
- Celkem testů: 225 — 197 unit + 28 integration.

---

## [3.4.3] — 2026-05-13

### Fixed

- **Docker: `/api/docs` (Swagger UI) a `/api/reference` (Redoc) padaly na CSP +
  403 pro `/api/openapi.yaml`.** Apache `.htaccess` nezrcadlil IIS `web.config`
  ohledně CSP pro externí CDN a navíc blokoval `.yaml` extension globálně:
  - CSP doplněn o `https://unpkg.com` (Swagger UI bundle + CSS) a
    `https://cdn.redoc.ly` (Redoc bundle + logo) v `script-src`, `style-src`,
    `connect-src` (sourcemapy) a `img-src` (Redoc logo). Plus `worker-src
    'self' blob:` pro Swagger workers a `font-src ... data:` pro embedded
    fonty. Sladěno s `web.config`.
  - `<FilesMatch "\.(env|sql|pem|log|lock|md|yaml|yml)$">` zablokoval i
    veřejný `api/openapi.yaml` → 403. `yaml|yml` z patternu odebráno; ostatní
    .yaml soubory jsou v `api/vendor/` a `web/node_modules/`, kde je už blokují
    rewrite rules.
  - Přidán MIME `AddType application/yaml .yaml`, aby browser nestáhl spec
    jako binární soubor.

- **Migrace: duplicate PRIMARY KEY na `migrations` tabulce při souběžném běhu.**
  `docker-entrypoint.sh` pouští `migrate.php` při startu kontejneru a
  `docker-ghcr.sh` ho pouštěl ještě jednou přes `docker compose exec`. Pokud
  oba procesy považovaly stejný soubor za pending, druhý padal na
  `INSERT INTO migrations` (race condition). Migrace samotné jsou idempotentní,
  takže schéma nebylo nikdy poškozené — jen skript skončil chybou.
  - `INSERT IGNORE` v bookkeeping tabulce — druhý migrátor tiše doběhne s
    poznámkou `already recorded — race with another migrator`.
  - `cmd/docker-ghcr.{sh,ps1}` už nespouštějí `migrate.php` druhým procesem;
    místo toho čekají na HTTP 200 z `/api/version` (entrypoint dokončí
    migrace před `apache2-foreground`).

### Internal

- `web.config` (IIS) — CSP přidáno `https://cdn.redoc.ly` do `img-src` a
  `connect-src` pro parity s Apache `.htaccess`.

---

## [3.4.2] — 2026-05-13

### Fixed

- **OpenAPI `openapi.yaml` byla v rozporu s reálným kontraktem backendu.**
  Field-names a query parametry vrácené v v3.4.0 dokumentaci neodpovídaly tomu,
  co backend skutečně čte/vrací — integrátor podle staré doc dostal `400` nebo
  se request mlčky ignoroval. Backend se nemění; jen dokumentace dohnala realitu:
  - `InvoiceInput`: `type` → `invoice_type` (enum opraven na
    `invoice|proforma|credit_note|cancellation`, `normal` neexistovalo);
    `taxable_date` → `tax_date`; přidán `currency_id` (FK, primární),
    `currency` (string code) ponechán jako `deprecated` legacy fallback;
    doplněny `varsymbol`, `advance_paid_amount`, `reverse_charge`, `language`,
    `exchange_rate`, `note_above_items`, `note_below_items`, `project_id`.
  - `Invoice` (response): stejné renames + `currency_id`, `exchange_rate(_date)`,
    `totals`, `vat_breakdown`, `czk_recap`, `project_billing_emails`, `bank_*`,
    `approval_status`, `issued_at`, `paid_at`, `cancelled_at`, `updated_at`.
  - `InvoiceItem` / `InvoiceItemInput`: `unit_price` → `unit_price_without_vat`;
    `vat_rate` (procento) → `vat_rate_id` (FK do `/codebooks/vat-rates`,
    což byl největší zdroj zmatku); `vat_rate_id` přidán do `required`.
  - `Client` / `ClientInput`: `email` → `main_email` (povinné);
    přidány `language`, `currency_default_id`, `hourly_rate`, `reverse_charge`,
    `payment_due_default`.
  - `ProjectInput.payment_due_days`: `minimum: 0` → `1` (sjednoceno s
    `Validation::project`, který 0 odmítal).
  - `GET /invoices` query: `?status=`, `?from=`, `?to=`, `?client_id=` →
    deep-object `filter[status]`, `filter[date_from]`, `filter[date_to]`,
    `filter[client_id]`, `filter[type]`, `filter[project_id]`, `filter[year]`,
    `filter[month]`, `filter[currency]`, `filter[unpaid_only]`,
    `filter[overdue]` + `q` fulltext. Stará rovinná forma se v handleru
    vůbec nečetla — filtry byly bez efektu.
  - `GET /clients` query: `include_archived` → `filter[archived]`; přidány
    `sort`, `page`, `per_page`.

### Added

- **`GET /api/v1/invoices/preview-varsymbol`** — route existovala od v3.4.0,
  ale chyběla v `openapi.yaml`. Vrátí náhled budoucího čísla faktury podle
  template aktuálního supplier-a, bez inkrementace counteru.
- **Setup wizard z LAN IP / non-localhost hostu vracel 403 `origin_mismatch`**
  (issue #22). `cfg.docker.php` má napevno `app.url = http://localhost:8080`,
  takže přístup z `http://10.0.0.8:8080/setup` (typicky Docker na headless
  serveru, browser z workstationu) selhal v `CsrfMiddleware` ještě než se
  dostal k setup endpointu — uživatel nemohl dokončit první spuštění.
- `CsrfMiddleware` nyní přeskakuje Origin/Referer check pro `/api/auth/setup*`
  endpointy, pokud aplikace ještě nemá admin účet (first-run state z
  `FirstRunLockMiddleware::needsSetup()`). Po vytvoření admina se ochrana
  okamžitě zapne — setup endpointy mají vlastní first-run guard, který po
  setupu vrací `setup_done`/`setup_already_done`, takže není defense-in-depth riziko.
- **Auto-detect `app.url` při first-run setupu.** `SetupAction` přečte
  `scheme://host[:port]` z hostiteleho requestu (s X-Forwarded-Proto fallbackem)
  a zapíše do `cfg.local.php`, pokud je v configu prázdná hodnota nebo některý
  ze známých placeholderů (`http://localhost:8080`, `https://dev.example.com`,
  `https://example.com`). Pokud uživatel app.url explicitně nastavil přes
  `MYINVOICE_APP_URL` env nebo `cfg.php`, není přepsán. Důsledek: reset hesla
  a schvalovací odkazy v emailech budou mít rovnou správnou URL, bez nutnosti
  ručního zásahu po setupu.

### Docs

- Manuál §2.1.4 a §99.1: dokumentuje přístup z LAN IP, env var
  `MYINVOICE_APP_URL` pro pokročilé scénáře (reverzní proxy, custom doména).

### Compatibility note

Žádný backend break — pole/parametry se přejmenovala jen v `openapi.yaml`,
aby odpovídala tomu, co backend od v3.4.0 odjakživa přijímá. Klient, který
postavil integraci podle původní (chybné) v3.4.0 doc, ji ve skutečnosti
neměl funkční (request buď padal na `400 validation_failed`, nebo se filtry
ignorovaly). v3.4.0 vyšla 2026-05-12, takže pravděpodobnost externí integrace
proti staré doc je minimální.

---

## [3.4.1] — 2026-05-12

### Fixed

- **Migrace selhávala při re-runu nad neprázdným schématem** (issue #20).
  `cmd/docker-ghcr.sh` na macOS hlásil `Duplicate column name approval_requested_at`
  protože tracker `migrations` byl prázdný, ale schéma už mělo některé sloupce.
- Migrace 0002–0010, 0014–0016 používaly fragile pattern
  `SET @col := (SELECT FROM INFORMATION_SCHEMA); PREPARE stmt FROM @sql; EXECUTE stmt`
  který se rozpadal přes splitSqlStatements v migrate.php (user-variables nepřežily
  každý PDO exec call). Nahrazeno MariaDB-native syntaxí: `ADD COLUMN IF NOT EXISTS`,
  `ADD KEY IF NOT EXISTS`, `DROP FOREIGN KEY IF EXISTS`, `MODIFY COLUMN IF EXISTS`
  (vše MariaDB 10.0.2+; projekt vyžaduje 10.6+).
- 0001_init.sql: všech 24 `CREATE TABLE` → `CREATE TABLE IF NOT EXISTS`,
  seedy `INSERT INTO {countries,vat_rates}` → `INSERT IGNORE INTO`,
  FK `fk_cur_supplier` drop+recreate. Doplněn COMMENT u `supplier.pohoda_*`
  (drift proti production schématu).
- 0018, 0019 dostali idempotent guards (`MODIFY COLUMN IF EXISTS`, `CREATE TABLE IF NOT EXISTS`).
- `reset.php` už dříve maže `api_tokens` — fix v3.4.0 zachován.

### Verified

- Všech 19 migrací × 2 průchody na fresh `myinvoicetest` DB — bez chyby.
- Deep schema diff `myinvoice` vs `myinvoicetest` (production vs fresh build):
  344 sloupců (vč. COMMENT), 104 indexů, 41 FK, 31 tabulek — **bit-by-bit identické**.

## [3.4.0] — 2026-05-12

### Added

- **Veřejné REST API v1** (issue #19). Personal Access Tokens (PAT) v hlavičce
  `Authorization: Bearer mi_pat_…`, scopes `read` / `read_write`, volitelný
  bind na konkrétního dodavatele, volitelná expirace. Step-up TOTP při tvorbě.
  Veřejná cesta `/api/v1/*` (stávající `/api/*` zůstává plně funkční pro SPA).
  Per-token rate limit 600 req/min + standardní `X-RateLimit-*` response headers
  pro klientský self-throttling.
- **Dvě dokumentační rozhraní** nad jediným OpenAPI 3.1 specem (`api/openapi.yaml`,
  50 paths, 41 schemas):
  - `/api/docs` — **Swagger UI 5** s „Try it out" a Authorize tlačítkem (token
    persistuje v localStorage),
  - `/api/reference` — **Redoc** s pretty static layoutem pro čtení a tisk,
  - `/api/openapi.yaml` — raw spec pro import do Postman / Insomnia / Zapier / Make.
- **Settings → API tokeny** — UI pro správu vlastních tokenů (list, vytvoření
  s jednorázovým zobrazením plain-textu, revokace). Tokeny jsou v `activity_log`.
- Migrace `0019_api_tokens.sql` — nová tabulka pro hashe (SHA-256) PAT tokenů.
- Manuál: nová kapitola **20. REST API** s `curl` příklady, best practices,
  multi-supplier guidance.
- Dev tooling: `cmd/check-openapi-coverage.php` — auditor driftu mezi Slim
  routes a `openapi.yaml` (vhodné do CI jako warning).
- 25 nových testů (10 unit + 15 integration) pokrývajících token service,
  bearer auth flow, scope enforcement, supplier scope binding, rate-limit
  headers, expiry, CSRF skip, doc endpointy.

## [3.3.1] — 2026-05-11

### Fixed

- **`auth.require_totp` po resetu/setupu zůstával zapnutý** — `SetupAction`
  i CLI `api/bin/setup.php` zapisovaly do `cfg.local.php` jen když uživatel
  zvolil "Vynutit 2FA". Stará `true` hodnota tam pak přežila i další setup
  s volbou "ne" a admin se zamykal na `/setup-totp`. Píše se teď VŽDY
  (true i false). `reset.php` navíc explicitně shazuje `auth.require_totp = false`,
  aby fresh start byl skutečně fresh.
- **„Chybí ID zakázky" při ukládání výkazu na faktuře bez zakázky** —
  `work_reports.project_id` byl `NOT NULL`, ačkoli `invoices.project_id`
  je volitelné. Migrace `0018_work_report_project_nullable.sql` uvolnila
  sloupec na `NULL`, `SaveWorkReportAction` + repo + frontend předávají
  `null` čistě.
- **Nejnovější verze v admin/update zůstávala stará** (např. `2.2.0` po
  upgradu na 3.3.0). `VersionService::getStatus()` teď ignoruje cache,
  kde `latest < current` (nemožný stav po manuálním upgradu), a vrací flag
  `cache_stale` když chybí check / je starší 24h / je nesmyslná. Frontend
  pak při otevření `/admin/update` automaticky spustí background refresh,
  takže nativní instalace bez cron job-u `cron-version-check.php` nezůstanou
  s neaktuální cache donekonečna.
- **`reset.php` po sobě nechával PDF historii, přílohy a version cache** —
  `invoice_pdfs`, `invoice_attachments` a `app_meta` nebyly v `$wipe`
  seznamu (TRUNCATE + `FOREIGN_KEY_CHECKS=0` cascade neaktivuje, takže
  řádky přežily i smazání faktur). Doplněno.

### Changed

- **Přenos výkazu víceprací do položky faktury** — místo `hodiny × průměrná sazba`
  se vkládá `1 ks × celková suma` (užitečnější pro klienta, jednodušší
  sync-check). Sync warning porovnává jen totals. Cíleně se používá kód `ks`
  z číselníku jednotek (ne systémový default), aby se hodiny zbytečně
  nepřenesly i tam, kde má uživatel default `h`.
- **Setup wizard** — email admina se předvyplní jako default email dodavatele
  při přechodu z kroku 1 do kroku 2 (jen pokud uživatel supplier email
  ještě needitoval).
- **Storno dobropisu** — modal "Storno / dobropis" je teď přístupný i pro
  dobropis (`canCancel` ho už nevylučuje). Pro dobropis se skrývá volba
  "Vystavit dobropis" (dobropis se nedobropisuje); zůstávají "Pouze interní
  storno" a "Smazat dobropis (admin)". Všechny popisky a per-status confirm
  popupy mají dedikované `_cn` varianty pro správnou terminologii.
  `CancelInvoiceAction` přijímá `mode=internal` i pro `invoice_type=credit_note`.
  Force-delete dobropisu **nesmaže** původní fakturu — jen samotný dobropis
  a jeho navazující stornovací doklad (pokud existuje); uvolnění čísla v
  číselné řadě dobropisů se děje přes existující `VarsymbolGenerator::releaseIfLatest`.

## [3.3.0] — 2026-05-10

### Added

- **Volitelné vynucení 2FA pro všechny uživatele** (`cfg.auth.require_totp`,
  env: `MYINVOICE_AUTH_REQUIRE_TOTP`, default `false`). Pokud je zapnuto,
  každý uživatel je po loginu zamčen na `/setup-totp` dokud neaktivuje TOTP.
  Backend `RequireTotpMiddleware` blokuje všechny endpointy mimo whitelist
  (`/api/auth/me`, `/api/auth/logout`, `/api/auth/totp/*`, `/api/health`,
  `/api/version`); frontend router-guard a axios interceptor zaručují
  redirect i z přímých API volání. Jediná „escape route" je odhlášení.
- **Instalační hooks pro `require_totp`** — CLI `php api/bin/setup.php`
  se ptá *„Vynutit 2FA?"*, web setup wizard má checkbox v kroku „Admin
  účet". Volba se zapisuje do `cfg.local.php` přes nový
  `CfgLocalWriter` helper (atomický merge, dot-notation klíče).
- Nová Vue stránka `ForcedTotpSetup.vue` (route `/setup-totp`) s QR kódem,
  6místným inputem a tlačítkem na odhlášení.
- `Login.vue` na mountu detekuje stale session a redirectuje rovnou na
  `/setup-totp` nebo `/`, ať není matoucí flow s druhým otevřeným oknem.

### Fixed

- **`api/bin/setup.php` zavedlo admina, který se nemohl přihlásit** —
  CLI hashovalo heslo přes `password_hash()` bez peppera, zatímco
  `LoginAction` ověřuje přes `PasswordHasher::verify()` s pepperem
  z `cfg.app.pepper`. Hash se nikdy neshodoval. CLI teď používá
  `PasswordHasher::hash()` stejně jako web setup wizard.



### Fixed

- **`MYINVOICE_DATA_DIR` je opět opt-in (žádný breaking change pro 3.1.x Docker uživatele)** —
  3.2.0 nastavovalo `MYINVOICE_DATA_DIR=/data` natvrdo v Dockerfile a v `docker-compose.yml`
  collapsovalo 3 volumes na 1, což po `docker compose pull && up -d` vypadalo jako ztráta dat
  (staré volumes nemountovaly na nové cesty). 3.2.1 vrací default chování — `app-log`, `app-storage`,
  `app-private` zůstávají, single-volume layout je opt-in přes `docker-compose.single-volume.yml`.
- Existující 3.1.x Docker stacky můžou udělat `docker compose pull` bez jakékoli další migrace.

### Changed

- `Dockerfile` — `ENV MYINVOICE_DATA_DIR=/data` odstraněno; `/data` adresář a `VOLUME ["/data"]`
  zůstávají, ale aktivují se až tehdy, když uživatel ENV explicitně nastaví.
- `docker-compose.yml` + `docker-compose.production.yml` — vráceny 3 named volumes
  (`app-log`, `app-storage`, `app-private`) jako default.
- `cmd/docker-migrate-volumes.{sh,ps1}` — header označen jako *optional*; spouštět jen při
  dobrovolném přechodu na single-volume mód.

### Added

- **`docker-compose.single-volume.yml`** — opt-in override pro single-volume mód
  (PaaS, Railway, Heroku, Fly.io). Použití:
  `docker compose -f docker-compose.yml -f docker-compose.single-volume.yml up -d`.

## [3.2.0] — 2026-05-10

### Breaking Changes

- **Docker volume layout** — named volumes `app-log`, `app-storage` a
  `app-private` byly nahrazeny jediným `app-data:/data`. Existující
  Docker instalace **musí před `docker compose up -d` s novou image
  spustit `cmd/docker-migrate-volumes.{sh,ps1}`**, jinak Docker připojí
  prázdný `app-data` a aplikace nebude vidět existující faktury, uploady,
  sessions ani DKIM klíče. Skript zkopíruje obsah starých volumes do
  nového a vypíše instrukce pro smazání starých. Detailní postup je
  v `manual/19_Aktualizace.md` § 19.5.

### Added

- **`MYINVOICE_DATA_DIR` env** — sjednotí všechny stateful adresáře
  (`log/`, `storage/{invoices,uploads,backup,sessions,cache}`,
  `private/dkim/`) pod jedinou cestu; default `/data` v Docker image.
  Cílem je clean Docker volumes — místo čtyř bind-mountů (`/storage`,
  `/private`, `/log`, `cfg.php`) stačí jediný persistent volume a zbytek
  kontejneru může běžet jako read-only. Per-instance override
  `cfg.local.php` z `${DATA_DIR}/` se auto-loaduje a přežije image update.
- **Stub `cfg.php`** v image — kontejner je samostatný, lze pustit s
  read-only `/var/www/html` a všechnu konfiguraci předat přes ENV.
  Bind-mount vlastního `cfg.docker.php` je proto nově **volitelný** —
  pro full-ENV deploy (Railway, Heroku, Fly.io) ho lze vynechat.
- **`cmd/docker-migrate-volumes.{sh,ps1}`** — sidecar migrace ze starého
  3-volume layoutu na nový jednovolume. Detekuje staré volumes, zastaví
  stack, zkopíruje data přes `alpine cp -a`, vypíše příkazy pro smazání
  starých volumes (mazání nedělá automaticky kvůli bezpečnosti).

### Changed

- `Bootstrap.php` — PHP error log honorí `Config::dataDir()` (když je
  `MYINVOICE_DATA_DIR` set, `php-errors.log` jde do `${DATA_DIR}/log/`).
- `VersionService::upgrade{Flag,Result}Path()` — flag a result soubory
  se ukládají do `${DATA_DIR}/storage/` když je ENV set; jinak fallback
  na rootDir (zachová zpětnou kompat se starým volume layoutem během
  přechodu před spuštěním migrace).

## [3.1.0] — 2026-05-10

### Added

- **CLI rescue pro 2FA lockout** — nový skript
  `php api/bin/reset-2fa.php <email>` resetuje `totp_enabled = 0` a
  `totp_secret = NULL` pro zadaného uživatele.

### Changed

- **Docker runtime auto-migrace** — runtime image používá
  `docker-entrypoint.sh`, který před startem Apache spustí
  `php api/bin/migrate.php` (s retry), takže nová/obnovená instance naběhne
  se schématem bez ručního zásahu.
- **ENV override hardening (Railway/PaaS)** — `Config` ignoruje nevyhodnocené
  placeholdery ve tvaru `${VAR}` v env overridech, aby se nepřepisovaly validní
  hodnoty konfigurace.

### Fixed

- **TOTP setup/enable při špatném encryption key** — endpointy vrací
  kontrolovanou JSON chybu místo neobsloužené 500 výjimky; chybové texty jsou
  v češtině kvůli správnému i18n překladu přes `ErrorCatalog`.
- **Validace `app.secret_encryption_key` v health/admin UI** — backend health
  endpoint vrací warning při chybějícím/invalidním klíči (včetně 24B klíče),
  admin stránka Aktualizace ho zobrazuje jako viditelné provozní upozornění.

## [3.0.3] — 2026-05-08

### Fixed

- **PowerShell watcher cosmetic error spam** —
  `cmd/docker-update-watcher.ps1` při běhu `docker compose pull` hlásil
  červené `NativeCommandError` / `RemoteException` na progress řádky
  (`Pulling fs layer`, `Downloading X MB`), i když exit code byl 0.
  PowerShell 7 default routuje stderr z native commandů jako error
  stream. Fix: `$PSNativeCommandUseErrorActionPreference = $false` na
  začátku scriptu + `2>&1 | Tee-Object | Out-Host` místo `*>&1`.

### Documentation

- `README.md` — nová sekce „Update watcher — jednoclick upgrade z UI"
  s test režimem (foreground) + produkční instalací (systemd / Scheduled
  Task).
- `manual/19_Aktualizace.md` — kapitola 19.4 přepsaná podle reality
  v3.0.2+ (flag uvnitř kontejneru, exec poll, log na hostu /tmp místo
  storage/, recovery přes `docker compose exec rm` ne hostový rm).

## [3.0.2] — 2026-05-08

### Fixed

- **Docker upgrade watcher neviděl flag soubor** — `storage/` v
  `docker-compose.production.yml` je Docker named volume (ne bind-mount),
  takže `Test-Path` / `[[ -f ]]` na hostu vždy false. UI správně zapsalo
  `storage/upgrade-requested.json` uvnitř kontejneru, ale watcher na
  hostu ho nikdy nenašel → tlačítko *Aktualizovat* skončilo věčně ve
  stavu „Upgrade probíhá…". Opraveno: `cmd/docker-update-watcher.{sh,ps1}`
  teď polluje přes `docker compose exec -T app test -f storage/...`,
  flag čte přes `cat`, lockuje přes `mv` uvnitř kontejneru, výsledek
  zapisuje zpět přes `sh -c 'cat > ...'`. Po `docker-update.{sh,ps1}`
  počká až se kontejner po restartu vrátí (až 60 s) a teprve pak píše
  result.json.

### Notes

- Watcher script je na hostu (mimo image), takže pro update na novou
  verzi script: `git pull` (pokud klonuješ) nebo
  `curl -O https://raw.githubusercontent.com/radekhulan/myinvoice/master/cmd/docker-update-watcher.sh`
  (Linux) /
  `curl -O https://raw.githubusercontent.com/radekhulan/myinvoice/master/cmd/docker-update-watcher.ps1`
  (Windows). Image samotná `:3.0.2` se chová stejně jako `:3.0.1` —
  fix je jen v host-side scriptu.

## [3.0.1] — 2026-05-08

### Fixed

- **`/admin/update` byla prázdná stránka po čerstvé instalaci** — vue-i18n
  parser shodil celou aplikaci s `SyntaxError: 2` na řetězci
  `cmd/docker-update-watcher.{sh,ps1}` v sekci `updates.*`. vue-i18n bere
  `{...}` jako placeholder pro interpolaci, takže `{sh,ps1}` (s čárkou)
  vyhodnotil jako neplatnou proměnnou a celý i18n soubor se nenačetl.
  Přepsáno na `(sh/ps1)` v `cs.json` + `en.json`. Same fix se týká i
  `queued_desc` a `how_docker_desc` klíčů.

## [3.0.0] — 2026-05-08

**Major release** — kontrola a upgrade nových verzí přímo z UI je poslední
plánovaná feature před zafixováním `master` větve. Po této verzi přejde
vývoj do `dev` větve a do `master` budou nové funkce přicházet v max.
měsíčních intervalech (kromě security patches).

Skok z 2.x na 3.x je bump kvůli významnosti pro provoz: footer aplikace
nově persistentně signalizuje stav verze, admin má kompletní upgrade
workflow z UI, a CI publikuje production bundle pro nativní deployment
bez Composer / Node na hostu.

### Added

- **`VERSION` soubor v rootu** — single source of truth pro semver.
  Backend ho čte při vykreslení footeru a porovnává s GitHub Releases API.
- **Daily check nové verze** — `api/bin/cron-version-check.php` denně volá
  `https://api.github.com/repos/radekhulan/myinvoice/releases/latest` a
  cachuje tag, release notes, URL do nové tabulky `app_meta` (key/value).
  Nastav cron 1× denně (manuál § Aktualizace).
- **Endpointy** — `GET /api/version` (public, footer), `GET
  /api/admin/update/status` (admin, plný stav), `POST /api/admin/update/refresh`
  (admin, fresh fetch z GitHubu), `POST /api/admin/update/trigger` (admin,
  zařadit upgrade).
- **Footer aplikace** — zobrazuje `vX.Y.Z` aktuální verzi; admin vidí navíc
  badge **„v2.5.0"** pokud je k dispozici nová verze (klik vede na Aktualizace).
- **Systém → Aktualizace** — nová stránka `/admin/update` (jen admin) s:
  aktuální + dostupnou verzí, tlačítkem **„Zkontrolovat teď"**, **„Aktualizovat"**,
  rendrovanými release notes (mini Markdown parser), výsledkem upgradu.
- **Docker upgrade flow** — UI vytvoří `storage/upgrade-requested.json`,
  host-side watcher (`cmd/docker-update-watcher.{sh,ps1}`) ho zachytí a
  spustí `cmd/docker-update.{sh,ps1}`. Watcher pošle `storage/upgrade-result.json`,
  UI ho pollne a zobrazí výsledek. Watcher je samostatný proces — install
  buď jako systemd unit, supervisord nebo Scheduled Task (návod v manuálu).
- **Nativní upgrade flow (zatím manual)** — UI ukáže copy-paste příkazy
  pro `git checkout vX.Y.Z` + composer/pnpm/migrate. Phase 2 doplní
  download production bundle + extract.
- **Production bundle v releases (CI)** — `docker-publish.yml` má nový job
  `bundle`, který při tag pushu vyrobí `myinvoice-X.Y.Z.tar.gz` (full
  deployable: `api/vendor/`, `web/dist/`, `manual/generated/`, `manual.pdf`)
  + SHA-256 a uploadne jako release asset. Připravuje cestu pro native
  auto-update bez Composer / Node na hostu.
- **`cmd/cron-version-check.{sh,cmd}`** — wrapper skripty stejné konvence
  jako ostatní crony (logy do `log/cron/version-check-YYYY-MM-DD.log`).
  Příklad crontab + `schtasks` v `cmd/README.md`.
- **„Jak upgrade funguje" sekce v Systém → Aktualizace** — vždy viditelná,
  environment-specific instrukce (Docker → watcher info + fallback shell;
  nativní → klasický git checkout + production bundle download), nezávisle
  na tom, jestli je k dispozici novější verze. Předtím se instrukce
  zobrazily jen po kliku na *Aktualizovat*.

### Documentation

- `README.md` — sekce v Docker quick-startu o upgrade z UI + watcheru;
  nová podsekce „Aktualizace nativní instalace" (git checkout / production
  bundle); cron-version-check v Cron skriptech.
- `manual/02_Instalace.md` — pointer u Docker varianty na § 19 + zmínka
  o `cron-version-check`.
- `manual/19_Aktualizace.md` — kompletně nová kapitola: workflow, instalace
  watcheru jako systemd unit / Scheduled Task, recovery při neúspěchu,
  external monitoring přes `/api/version`.
- `cmd/README.md` — nová položka cron-version-check + docker-update-watcher
  v tabulkách; schtasks + crontab + systemd unit příklady.

### Migration

- `db/migrations/0017_app_meta.sql` — generic key/value cache table pro
  infrastrukturní data, která nejsou per-supplier. První use-case: cache
  poslední dostupné verze + release notes.

## [2.3.0] — 2026-05-08

### Added

- **PDF verze manuálu** — `tools/exportManualToPdf.php` převede `manual/*.md`
  do `manual/manual.pdf` (cca 3 MB, 19 kapitol). Branding ladí s aplikací
  (purple `#4c1d95` / `#6c5ce7`, light accent `#ede9fe`), titulní strana
  s logem, automatický TOC z H1/H2, header/footer se značkou MyInvoice.cz
  a stránkováním. Cross-chapter `.md` linky se přepisují na interní PDF
  anchory. V sidebaru `/manual` přibyl button **„Stáhnout PDF"**, který
  se zobrazí jen pokud `manual/manual.pdf` existuje.
- **Docker build napeče PDF do image** — `Dockerfile` po
  `generateManualHtml.php` volá i `exportManualToPdf.php`, takže GHCR
  image (`ghcr.io/radekhulan/myinvoice:2.3.0`) má PDF dostupný
  out-of-the-box bez extra build kroku.

### Notes

- Markdown converter v `exportManualToPdf.php` extrahuje `` `code` `` spany
  do placeholderů před aplikací italic/bold formátování — DejaVu Sans Mono
  nemá italic variantu, takže `<em>` uvnitř `<code>` by mPDF shodil
  (`Cannot find TTF DejaVuSansMono-Oblique.ttf`).

## [2.2.0] — 2026-05-08

Cloud-native release — image lze nasadit na rootless PaaS (Railway, Heroku,
Fly.io) bez patchů. Reaguje na issue #9 od @TomasTriska88.

### Added

- **Dynamický port přes `${PORT}`** — `Dockerfile` nově nastavuje
  `ENV PORT=80` a sed-em přepíše `Listen ${PORT}` v `ports.conf` a
  `<VirtualHost *:${PORT}>` v `000-default.conf`. Apache 2.4 expanduje
  `${PORT}` z env při parsingu, takže Railway/Heroku, kde je port přidělen
  dynamicky, nasadí image out-of-the-box. Default 80 zachová zpětnou
  kompatibilitu pro `docker compose` / VPS / sdílený hosting.
- **Konfigurace přes ENV proměnné (12-factor)** —
  `Config::applyEnvOverrides()` po načtení `cfg.php` aplikuje overridy
  z env. Mapa pokrývá `app.*`, `db.*`, `redis.*`, `session.*`, `smtp.*`,
  `captcha.*`, `logging.*`. Plus parser pro kompozitní `DATABASE_URL` /
  `REDIS_URL` (Railway styl) a aliasy `MYSQL_*` / `REDIS_*` (Heroku).
  V kontejnerovém deploymentu stačí `cfg.php` s prázdnou strukturou
  (`<?php return [];`) a všechny citlivé údaje předat přes ENV.

### Fixed

- **MPM conflict při startu Apache** — base image `php:8.5-apache` za
  jistých okolností končí s víc načtenými MPM moduly a Apache padá s
  *More than one MPM loaded*. `Dockerfile` teď explicitně dělá
  `rm -f /etc/apache2/mods-enabled/mpm_* && a2enmod mpm_prefork` po
  instalaci ostatních modulů.
- **Idempotence migrací na MySQL 8** — `ADD COLUMN/KEY IF NOT EXISTS` je
  MariaDB-only syntaxe a na MySQL 8 padá s *1060 Duplicate column* nebo
  *1061 Duplicate key name*. Migrace 0002–0010, 0014, 0015, 0016 převedeny
  na `INFORMATION_SCHEMA` guard + `PREPARE/EXECUTE` (funguje na MariaDB
  i MySQL 8). No-op cesta používá `DO 0` místo `SELECT 1`, aby PDO
  nezůstávalo s nezpracovaným resultsetem (*HY000 / 2014 unbuffered
  queries active*). Fresh install z prázdné DB i opakovaný run pass na
  obou DBMS.

## [2.1.5] — 2026-05-07

### Added

- **HTML manuál uvnitř Docker imagu** — `Dockerfile` nově volá build-time
  `php tools/generateManualHtml.php`, takže `manual/generated/` (19 kapitol
  + INDEX + search-index) se napeče přímo do image. `/manual` route nyní
  funguje out-of-the-box pro všechny tři Docker varianty (GHCR, build z
  source, no-clone). Předtím vracel 503 *„Manuál není zatím vygenerovaný“*,
  protože `manual/generated/` je gitignored a žádný build krok ho v Dockeru
  nevyráběl.
- **`.gitattributes`** — `*.sh text eol=lf`, `*.cmd / *.ps1 text eol=crlf`.
  Přebíjí případně zapnutý `core.autocrlf=true` na Linux/WSL2 klonech, kde
  by jinak shell skripty dostaly CRLF a praskly na shebangu (`bash\r`).

### Fixed

- **`.dockerignore` shadowoval markdown manuál** — globální vzor `*.md`
  vyfiltroval `manual/*.md` z build kontextu, takže ani manuální spuštění
  generátoru by uvnitř image nemělo zdrojové soubory. Vzor zúžen na
  `/README.md` + `/CHANGELOG.md` + `/source` (dev-only specs); manuál
  prochází.

### Documentation

- **`manual/02_Instalace.md` § 2.1.8 HTTPS / TLS terminace** — doplněn
  konkrétní Caddy recept (Caddyfile + `docker run` na host network),
  vysvětlení role `X-Forwarded-Proto` (jinak redirect loop s `.htaccess`)
  a důsledků `__Host-` cookie prefixu po přepnutí na HTTPS.
- **WSL2 / Linux troubleshooting** — README.md i manual § 2.1.1 popisují,
  jak řešit `Permission denied` / `bash\r` po `git clone` v Linux/WSL2
  s `core.autocrlf=true` (`sed -i`, `chmod +x`, `git config --global`).
- **Varianta C (no-clone Docker)** — README + manual § 2.1.3 nově zmiňují,
  že `/manual` je dostupné přímo z GHCR image bez jakéhokoliv extra kroku.

## [2.1.4] — 2026-05-07

### Fixed

- **`docker-update.{sh,ps1}` špatně detekoval mode** — když uživatel instaloval
  přes `docker-ghcr.{sh,ps1}` (registry mode, používá
  `docker-compose.production.yml`), update detekoval podle defaultního
  `docker-compose.yml`, který má `build:` blok (dev compose), a spadl do
  source mode. To způsobilo: 1) zbytečný `git pull`, 2) lokální build
  duplicitního `myinvoice:latest` image vedle `ghcr.io/radekhulan/myinvoice`,
  3) `docker compose up -d` bez `-f production.yml` switchnul stack na
  lokální build. Fix: detekce preferuje skutečně **RUNNING** stack
  (`docker compose -f production.yml ps app`), `COMPOSE_ARGS` se propagují
  do všech compose volání ve skriptu (pull/build/up/ps/exec).

## [2.1.3] — 2026-05-07

### Fixed

- **Send modal v invoice detailu pre-fillne všechny příjemce** — když měla
  zakázka definované `project_billing_emails`, modal ukazoval jen
  `client_main_email`. Pre-fill rozšířen na `client_main_email + všechny
  project_billing_emails` (de-duplikováno čárkou) — odpovídá tomu, co
  reálně backend `SendEmailAction::resolveRecipients` posílá. Uživatel
  může v inputu libovolně upravit.

### Infrastructure

- **CI Frontend job + Dockerfile web-build stage**: Node 20 → **Node 24**
  (current LTS od října 2025). pnpm 11.0.8 (auto-resolved via
  `corepack@latest`) vyžaduje Node ≥ 22.13 — Node 20 padalo s
  `ERR_UNKNOWN_BUILTIN_MODULE: node:sqlite`. Bump rovnou na 24, ne 22 —
  Node 20 actions deprecated, removed Sep 2026.

### Note

`v2.1.2` release exists na GitHubu, ale docker-publish workflow pro něj
selhal (stejná Node 20 chyba) — proto **na GHCR žádný `:2.1.2` image
neexistuje**, `:latest` zůstával na `2.1.1`. Tato verze (2.1.3) je první
úspěšný Docker build po 2.1.1 a obsahuje všechny fixes z 2.1.2 (logo
display size, header border-bottom).

## [2.1.2] — 2026-05-07

### Fixed

- **Logo v hlavičce emailu se renderovalo přes celou šířku** — Outlook,
  Gmail web/native a Yahoo CSS `max-height` na `<img>` ignorují, takže
  logo přerůstalo zamýšlených 48 px. Fix: `Mailer::addLogoDisplaySize()`
  spočítá display rozměry server-side z PNG dimenzí (target height 48 px,
  width proporční podle aspect ratio) a Twig je vyplní jako HTML
  `width`/`height` atributy (univerzálně respektované všemi email
  klienty). Stejný compute v `EmailBrandingAction::preview` pro live
  preview iframe. Test: logo 480×234 → display 99×48.
- **Zbytečná tenká čára pod hlavičkou emailu** — odstraněn
  `border-bottom: 1px solid #E7E3EE` z header `<td>`. Gradient pozadí
  a padding samy oddělují header od obsahu.

## [2.1.1] — 2026-05-07

### Fixed

- **HTTP → HTTPS redirect blokuje LAN přístup přes IP** ([#6](https://github.com/radekhulan/myinvoice/issues/6))
  — `web.config` (IIS) i `.htaccess` (Apache) měly redirect na HTTPS pro
  všechno kromě `localhost`. Self-hosted Docker uživatelé přistupující
  přes `http://192.168.x.x:8080` dostávali 301 → `https://192.168.x.x/...`,
  což skončilo `SSL_ERROR_RX_RECORD_TOO_LONG` (stack TLS nedělá). Vyjímky
  rozšířeny o **RFC1918 privátní IP** (`10.*`, `172.16-31.*`, `192.168.*`),
  **loopback** (`127.*`), **`*.local`** mDNS jména a hlavičku
  **`X-Forwarded-Proto: https`** (request přes reverse proxy s TLS terminací).
  Production přístup přes veřejnou doménu redirect dál vynucuje.

## [2.1.0] — 2026-05-07

### Added

- **Per-supplier branding emailů a PDF** — `Nastavení → Branding emailů`.
  Toggle „Použít vlastní branding" gatuje branding **konzistentně napříč
  emaily i PDF faktur**. Když je zapnutý: default fialové „M" logo
  v hlavičce odchozích emailů se nahradí firemním logem (CID inline image,
  zobrazí se bez „Display images" promptu), název „MyInvoice.cz" se nahradí
  `display_name` + `tagline` dodavatele, akcent barva (default `#3B2D83`)
  se použije pro „M" fallback box a všechny odkazy v emailu, a v hlavičce
  PDF faktury se ukáže stejné logo místo textového jména firmy. Když je
  vypnutý: e-mail vrátí default MyInvoice branding a PDF zobrazí jméno
  firmy textem.
  - **Live preview iframe s CS/EN přepínačem** — náhled emailu se aktualizuje
    okamžitě po změně toggle / barvy (auto-save s 0,5 s debounce pro color
    picker) bez potřeby klikat „Save". Renderuje se přes `srcdoc` (fetch
    HTML přes axios + injektnutí do iframe), aby fungoval i s globálním
    `X-Frame-Options: DENY` v `web.config` / `.htaccess`.
  - **SVG dual-storage** — originální SVG se uloží jako sidecar
    (`sup-{id}.svg`) pro **PDF render přes mPDF** (vektor = crisp
    v libovolném zoomu), a zároveň se převede na transparentní PNG
    (`sup-{id}.png`) pro **email** (Outlook / Gmail / Yahoo SVG strippují,
    musí to být raster). SVG se před uložením sanitizuje proti XSS / XXE
    (žádný `<script>`, `<foreignObject>`, `on*` handlers, ENTITY ani
    externí `href`).
  - **SVG → PNG konverze** — cross-platform pipeline: PHP `Imagick`
    extension (Windows i Linux, s DPI boostem aby výstup měl alespoň
    240 px na výšku — 5× retina pro 48 px display) → fallback `rsvg-convert`
    CLI (balíček `librsvg2-bin`, pre-instalovaný v Docker image
    `ghcr.io/radekhulan/myinvoice`). Pokud žádný z nástrojů není dostupný,
    upload SVG selže se srozumitelnou instalační hláškou — PNG/JPG/WebP
    funguje vždy přes GD.
  - **PNG/JPG/WebP resize** — přes GD, max 800×240 px, transparentní pozadí.
  - **Pixel-bomb protection** — odmítne dekódovaný obrázek nad 12 MP
    (chrání před `100000×100000` PNG, který by sežral všechnu RAM).
  - **Storage:** `storage/supplier-logos/sup-{id}.{png,svg}` (mimo webroot).
  - **Snapshot vs live:** fakturační údaje v patičce zůstávají frozen
    ve snapshotu, branding (logo, barva, toggle) se vždy fetchuje LIVE
    z aktuálního stavu dodavatele — branding je „současná identita firmy",
    ne historický stav v okamžiku vystavení.
  - DB migrace `0016_email_branding`: nové sloupce
    `supplier.email_branding_enabled` (TINYINT default 0) a
    `supplier.email_accent_color` (VARCHAR 7 default `#3B2D83`).
- **Attribution řádek v patičce PDF faktury** — drobný šedý 7 pt text
  na patě **každé** stránky (mPDF `<htmlpagefooter>`): **„Používá fakturační
  systém [MyInvoice.cz](https://myinvoice.cz/)"** (CS) / **„Powered by
  MyInvoice.cz invoicing system"** (EN). „MyInvoice.cz" je proklikatelný
  odkaz. Stejná attribution se objeví i v patičce každého odchozího emailu.
- **SMTP debug v activity logu** — každý odeslaný email teď v activity
  payloadu obsahuje pole `smtp_response` (poslední řádek odpovědi SMTP
  serveru, např. `250 Ok: queued as 6B5F95C80063` pro úspěch nebo `5xx ...`
  pro odmítnutí) — při delivery problémech vidíš okamžitě, zda SMTP server
  zprávu přijal nebo odmítl. Plný SMTP transcript jde do `log/myinvoice-*.log`
  pod klíčem `mail.sent` (info level). Pokrývá `SendEmailAction`,
  `SendTestEmailAction`, `SendTestReminderAction`.

### Changed

- **Activity log v invoice detailu** — přepracovaný do tabulkového layoutu
  konzistentního s `admin/Activity log` (action badge / user / timestamp /
  payload). Payload se neořezává — wrapuje s `break-all whitespace-pre-wrap`,
  takže celý záznam je čitelný i u dlouhých `to=…cc=…bcc=…pdf_path=…` payloadů.
- **Twig email layout** (`api/templates/email/_layout.html.twig` +
  `_layout.txt.twig`) — přepracovaná hlavička: pokud
  `supplier.email_branding_enabled`, vykreslí se supplier logo + brand name;
  jinak fallback na MyInvoice „M" box. Akcent barva proměnná napříč šablonou
  (header, footer linky). Plain-text varianta upravena obdobně.

### Fixed

- **Duplicitní e-mailová adresa v invoice detailu** — když byl
  `client_main_email` totožný s některým z `project_billing_emails`,
  zobrazil se v UI 2× (header + reminder modal). Teď se de-duplikuje filtrem
  ve v-for. Backend (`SendEmailAction::resolveRecipients`) už dedupoval
  korektně, takže reálně se email pošle jen jednou — bug byl jen v UI.

### Infrastructure

- **Dockerfile** — runtime stage instaluje `librsvg2-bin` (~2 MB) pro SVG
  konverzi loga.
- **Mailer.php** — používá `Transport::send()` napřímo místo
  `SymfonyMailer::send()` (Symfony Mailer 8.x vrací `void`, jen Transport
  vrací `SentMessage` s SMTP transcriptem). `embedFromPath()` pro CID
  inline image. Po každém odeslání zaloguje plný SMTP transkript do Monolog
  na úrovni `info` pod klíčem `mail.sent`.
- **InvoiceEmailVarsBuilder** — `loadSupplierFooter()` rozšířen o branding
  fields (vždy live, nepatří do snapshotu).

## [2.0.3] — 2026-05-07

### Fixed

- **Modální okna se nezavírají kliknutím mimo** ([#5](https://github.com/radekhulan/myinvoice/issues/5))
  — backdrop click-to-close odstraněn ze všech 14 formulářových modálů
  (číselníky, dodavatelé, uživatelé, e-mail šablony, faktury, bankovní
  výpisy…). Stray klik mimo okno nebo přepnutí mezi taby v prohlížeči už
  nezahodí vyplněná data — modal se zavírá pouze přes explicitní
  **Zrušit / Uložit / Potvrdit / X** tlačítka. Odpovídá modernímu UX
  patternu (Notion, Linear, Stripe).
- **`docker-install.sh` / `docker-ghcr.sh` na macOS** — generování
  `cfg.docker.php` selhávalo, protože GNU sed extension `0,/pat/s|…|…|`
  nefunguje v BSD sed na macOS. Skript buď shodil `set -e`, nebo přepsal
  obě `'host' => '127.0.0.1'` řádky stejně, což rozbilo DB přístupy.
  Nahrazeno portable perl one-linerem — funguje out-of-the-box na macOS
  i Linuxu, žádný `brew install gnu-sed` už není potřeba.

### Documentation

- **Manuál — HTTPS / TLS terminace** ([#6](https://github.com/radekhulan/myinvoice/issues/6))
  — nový oddíl 2.1.8 v `manual/02_Instalace.md`: Docker stack běží na
  plain HTTP (port 8080), přístup přes `https://...` shodí prohlížeč
  s `SSL_ERROR_RX_RECORD_TOO_LONG`. Doplněn callout v 2.1.4 + tři
  rozumné cesty k HTTPS (Caddy / Nginx / Cloudflare Tunnel) včetně
  production cookie nastavení v `cfg.docker.php`.
- **Manuál — rozšíření úvodu** — tematicky rozdělené sekce funkcí
  v úvodu manuálu, odstranění inline image.

## [2.0.2] — 2026-05-06

### Added

- **Alokace varsymbolu při žádosti o schválení výkazu** —
  `POST /api/invoices/{id}/request-approval` teď před odesláním emailu
  alokuje varsymbol a zafixuje supplier/client/bank snapshoty (status
  zůstává `draft`). Důsledky: příloha v emailu je `Vykaz-2605004.pdf`
  místo `Vykaz-draft-299.pdf`, schvalovací email obsahuje reálné číslo
  faktury a snapshoty odpovídají stavu v okamžiku, kdy klient schvaluje.
  Idempotentní — `AutoIssueAndSendService::run()` allocate přeskočí,
  pokud už VS existuje.
- **Archivace odeslaného výkazu do PDF historie faktury** — `Vykaz-XYZ.pdf`
  poslaný klientovi ke schválení (`RequestApprovalAction`) i v upomínkách
  (`cron-send-approval-reminders.php`) se teď archivuje s flagem
  `was_sent=true` a seznamem příjemců. V UI historie PDF se zobrazí jako
  „Žádost o schválení výkazu" / „Upomínka schválení výkazu" → klient.
- **Rozšířený `incrementMonthInString()` pro klonování faktur** — kromě
  původního `M/YYYY` rozpozná i `YYYY-MM`, `YYYY/MM`, `MM.YYYY`,
  `MM-YYYY`. Padding: ISO formát (`YYYY-MM`) paduje vždy
  (`2025-12` → `2026-01`), month-first formáty padují jen když uživatel
  napsal leading zero (`12/2025` → `1/2026`, `01-2026` → `02-2026`).
  Plné datumy (`2026-05-15`, `20.5.2026`) jsou chráněné lookaroundy
  a neinkrementují se. Krytí 9 nových unit testů.

### Changed

- **„Přenést do faktury" na výkazu víceprací** — detekce prázdné
  placeholder položky v `pushWrToInvoiceItem()` ignoruje cenu.
  `blankItem()` na nové faktuře předvyplňuje cenu z `project.hourly_rate`,
  takže původní podmínka `price=0` placeholder nezachytila a vytvořila
  se duplicitní položka. Po opravě se placeholder nahradí daty z výkazu.
- **Veřejná schvalovací stránka (`ApprovalPublic.vue`)** — odstraněn
  per-řádkový sloupec „Celkem" v tabulce výkazu, řádky ukazují jen
  Popis / Datum / Hodin / Sazba. Sumarizace zůstává v patičce. Zvětšené
  šířky číselných sloupců + `whitespace-nowrap` — částka s `CZK` se
  nezalomí na 2 řádky.
- **`InvoicePdfRenderer::invalidate()`** dostala 3. parametr
  `bool $archive = true`. Při `archive=false` se cached PDF jen
  `unlink()`ne bez záznamu v `invoice_pdfs`. Použito v
  `allocateVarsymbolAndSnapshots()` — draft preview PDF před alokací VS
  je pomocný cache, ne odeslaný doklad, archivace by tvořila šum.

## [2.0.1] — 2026-05-06

### Fixed

- **Vytvoření prvního dodavatele po deferred-supplier setupu** —
  `POST /api/suppliers` selhával s `Vytvoření supplier selhalo: V DB neexistuje
  žádná currency`, pokud uživatel při setup wizardu odložil vytvoření
  dodavatele. Currencies tabulka má `supplier_id` FK, takže bez supplieru je
  prázdná, a `createSupplier` nemohl najít bootstrap placeholder pro cyklický
  FK `supplier.default_currency_id ↔ currencies.supplier_id`. Fallback na
  `SET FOREIGN_KEY_CHECKS = 0` (stejný trik, který už používá
  `SetupAction::insertSupplier`).

## [2.0.0] — 2026-05-06

Hlavní release s novými adminovskými workflow nad účetními doklady, plně
konfigurovatelnou číselnou řadou per dodavatel, ručním overridem čísel
a uživatelskými přílohami k mailu.

### Added

- **Volitelné přílohy k dokladu** (migrace 0013) — uživatel nahraje PDF /
  Office / obrázky k faktuře, proformě nebo dobropisu, soubory se přibalí
  k mailu při Odeslat / Test odeslat. Limity 10 MiB / soubor, 20 MiB / fakturu;
  whitelist MIME (PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, ODT/ODS/ODP, TXT/CSV,
  JPG/PNG/GIF/WEBP/HEIC/HEIF, ZIP) s detekcí z obsahu (finfo) a kontrolou
  shody s příponou. Funguje i pro koncepty. Drag-drop UI v detailu faktury.
  K upomínkám / approval mailu se přílohy NEpřibalují.
- **Per-supplier šablony čísla faktury** (migrace 0014) — v Nastavení dodavatele
  → Číslování faktur. 3 šablony per typ (faktura / proforma / dobropis),
  placeholdery `{YYYY}`, `{YY}`, `{MM}`, `{C+}` (variabilní padding).
  NULL = fallback na globální `cfg.varsymbol.templates`. Live preview v UI
  + inline error pokud chybí counter.
- **Reset cyklu číselné řady** — ENUM `year` / `month` / `none`, default
  `month` zachová zpětnou kompatibilitu s legacy CHAR(6) period klíčem.
- **Manuální override čísla v editoru** — pole „Číslo faktury" / „Číslo
  zálohové faktury" / „Číslo dobropisu" v hlavičce konceptu. Prázdné =
  auto-generuje se při Issue, vyplněné = backend použije přesně tu hodnotu
  s duplicate-check per supplier (409 `varsymbol_duplicate`). Po Issue je
  číslo immutable (force=1 nepřepíše).
- **Preview API** `GET /api/invoices/preview-varsymbol` pro live placeholder
  v editoru.
- **Tlačítko Nezaplacené** (admin) — vrátí fakturu ze stavu `paid` zpět do
  `sent` (pokud byla odeslaná) nebo `issued`, vyčistí `paid_at`. 409 pokud
  je faktura spárovaná s aktivní bank tx (uživatel má použít bank unmatch
  flow). Activity log: `invoice.unmark_paid`.
- **Force-delete vystavené faktury** (admin, migrace 0015) — třetí možnost
  ve Storno / Dobropis modalu. ON DELETE CASCADE pro `parent_invoice_id`
  (smazání rodiče cascade odstraní storno/dobropis i jejich items / work
  reports / PDF historii / přílohy). Detailní per-status varování
  (vystavená / odeslaná / zaplacená / stornovaná) s doporučenou alternativou.
  Pre-delete: invalidace cached PDF, **purge fyzických souborů** PDF historie
  + uživatelských příloh z disku. Activity log: `invoice.force_deleted`
  s `cascade_deleted_ids`, `purged_pdf_files`, `purged_attachments`.
- **Type-aware texty v editoru** — H1 a label pole čísla se mění dle typu
  („Upravit dobropis" + „Číslo dobropisu" pro `credit_note`, atd.).
- **Manuál**: nové sekce 10.2.5 (Číslo dokladu — ruční override),
  11.6 (Admin akce nad vystavenou fakturou), 16.5.3 (Číslování faktur).

### Changed

- **DeleteInvoiceAction** — rozšířený o role guard (non-draft jen admin),
  cascade delete dětí, recompute revenue stats po smazání, detailnější
  audit log. Backend i UI mají stejné role pravidlo.
- **CancelInvoiceAction modal** — přejmenování Storno/Dobropis modalu na
  3-volbový (vystavit dobropis / interní storno / **smazat fakturu**).
- **Sekce „Další akce" v detailu** dostupná i pro koncept (Test odeslání +
  Detail klienta), tlačítko „Upravit (admin)" pro draft skryté (nahoře už
  je „Upravit").

## [1.9.1] — 2026-05-05

### Fixed

- **DB migrace 0002–0010 idempotentní** — všechny `ALTER TABLE` / `CREATE TABLE`
  klauzule používají `IF NOT EXISTS` (MariaDB 10.0.2+, MySQL 8.0.29+). Opravuje
  scénář kdy `0001_init.sql` měl konsolidované sloupce `auto_send_reminders`
  z 0008/0009, které pak selhávaly s `1060 Duplicate column name` a přerušily
  další migrace (typicky 0010 `clients.hourly_rate` se neaplikovalo). Fixes [#4](https://github.com/radekhulan/myinvoice/issues/4).
- **Setup wizard validation UX** — povinná pole dodavatele (`company_name`,
  `email`, `street`, `city`, `zip`) označena `*` + `required` + červený border
  + per-field error message z API response. Generická hláška „Validace selhala"
  nahrazena konkrétním seznamem chybějících polí. ARES lookup zobrazí warning
  „doplň e-mail ručně" (ARES e-mail nevrací). Fixes [#3](https://github.com/radekhulan/myinvoice/issues/3).

### Added

- **`cmd/docker-update.{sh,ps1}`** — update skripty pro běžící Docker stack.
  Auto-detekce mode (source build vs registry pull), restart stacku, čekání
  na DB health, automatické spuštění migrací.

## [1.9.0] — 2026-05-05

### Added

- **Neplátce DPH — adaptivní UI a PDF.** Když je dodavatel neplátce
  (`Nastavení → Dodavatel → není plátce DPH`), editor faktury, detail i PDF
  vykreslují fakturu **bez DPH sloupců, bez RC checkboxu a bez sumace DPH**:
  - Editor: skrytý sloupec „DPH %" v tabulce položek (desktop i mobile),
    skrytá sumace DPH, skrytý RC checkbox; nové položky se interně ukládají
    s 0% sazbou (`CZ-0` Osvobozeno).
  - Detail: stejné gating — místo „S DPH" sloupce se ukáže „Celkem".
  - PDF: tabulka položek má 5 sloupců (Popis, Mn., Jed., Cena/j, Celkem)
    místo 7; sumace zobrazí jen `Celkem` bez rozpisu sazeb.
  - Live totals i serverový výpočet vynucují 0 % VAT pro neplátce.
- **Manuál — kapitola „Fakturujeme — daňový průvodce"** ([§ 6](manual/06_Fakturujeme.md)).
  Praktický průvodce: plátce vs. neplátce DPH, sazby (`CZ-21/12/0/RC`),
  reverse charge (kdy + jak), zahraniční fakturace + OSS limitace
  (workaround pro SK 23 %), explicit hranice scope aplikace, doporučení
  konzultace s účetní.
- **`tools/renumberManual.php`** — skript pro přečíslování `manual/*.md`.
  Sekvenčně přejmenuje soubory, přepíše H1/H2/§-refy v textu, cross-linky
  (path + label + anchor) a aktualizuje `INDEX.md`, `manual/README.md`
  a root `README.md`. Default dry-run, `--apply` pro commit.

### Changed

- **VIES parser CZ/SK adres** — drop trailing country line („Slovensko",
  „Česká republika" …), podpora SK PSČ formátu `82108` (5 číslic bez mezery),
  strip suffixu „— mestská časť …" z města. Self-repair starších cached
  záznamů s `parsed:null`.
- **VIES doplnění klienta** — když parser adresy selže, vyplní se aspoň
  jméno firmy a země z VIES (dříve gate `result.parsed` blokoval i tato pole).
- **Editor faktury — Reverse Charge default sazba.** Při zaškrtnutí RC
  checkboxu (nebo při výběru klienta s RC) se všem položkám nastaví sazba
  `CZ-RC` (0 % Reverse charge) místo `CZ-21`. Edit-mode loaded faktur
  zůstává nedotčen.
- **RC checkbox visibility** — viditelný jen když má vybraný klient v profilu
  `reverse_charge: true` (nebo když není ještě zvolený klient).
- **Manuál přečíslován** — kapitola „Fakturujeme" jako 6, ostatní posunuté
  (`07_Klienti`, …, `18_Bezpecnost`, FAQ zůstává `99`); sjednocená řada bez
  vsuvek `5a_` a `13a_`.
- **`/auth/me`** — vrací `is_vat_payer` v seznamu suppliers (frontend store
  potřebuje pro UI gating).

### Fixed

- **Manuál § 18.2 (2FA) — odstraněna nepravdivá pasáž** o 8 záložních
  jednorázových kódech. Recovery codes nejsou implementované; postup při
  ztrátě telefonu je SQL `UPDATE users SET totp_enabled=0, totp_secret=NULL`.
  Zmíněný `api/bin/2fa-disable.php` script také neexistuje, FAQ § 99.1
  upraveno odpovídajícím způsobem.

## [1.8.0] — 2026-05-04

### Added

- **Upomínky — per-supplier + per-klient přepínač** automatického odesílání.
  Globální cron upomínek (po splatnosti / před splatností) lze nyní vypnout
  na úrovni dodavatele i jednotlivého klienta. Manuální odeslání zůstává
  vždy dostupné.
- **Klient — výchozí hodinová sazba** se ukládá na klientovi a
  předvyplňuje se při vytváření nové zakázky i při přidávání řádku
  výkazu víceprací do faktury.

### Changed

- **VIES ověření CZ DIČ** používá ARES místo VIES (rychlejší, spolehlivější),
  cache TTL zkrácena na 3 hodiny.
- **Editor faktury** — při změně klienta/zakázky se osvěží sazba (DPH i
  hodinová) u prázdné položky a u řádku výkazu víceprací, takže nově
  zadávané položky vždy reflektují aktuální nastavení.

### Fixed

- Předvyplnění hodinové sazby v editoru faktury nerespektovalo default
  z klienta — opraveno.

## [1.7.0] — 2026-05-04

### Added

- **Plošný mobilní redesign tabulek** — pod `md:` breakpointem (<768 px) se každá
  list-tabulka skryje a zobrazí jako stack karet; nad `md:` zůstává původní
  tabulkový layout beze změny. Pokrývá:
  - **List views** — `/invoices` (s zachováním měsíčních skupin),
    `/clients`, `/projects`, `/bank` (statementy).
  - **Detail nested views** — `ClientDetail` → Zakázky + Faktury,
    `ProjectDetail` → Faktury, `InvoiceDetail` → Položky + Výkaz víceprací.
  - **Edit forms** — `InvoiceEditor` → Položky + Výkaz víceprací jako stack
    karet s jedním inputem na řádek (popis, množství/jednotka, cena/DPH,
    sazba/celkem), tap targets ≥ 40 px, `inputmode="decimal"` na číslech
    pro mobilní num klávesnici.
  - **Dashboard widgety** — „Po splatnosti", „Nezaplacené", „Top klienti"
    jako kompaktní list-rows (klient + amount + dny po splatnosti badge,
    share bar inline).
  - **Bank/StatementDetail transakce** — kartové view s amount nahoře,
    status badge, full-width tlačítka **Spárovat / Ignorovat / Zrušit
    spárování** (klíčový workflow byl předtím schovaný za horizontálním
    scrollem a nedostupný z mobilu).
  - **Admin views** — `Users` (s 2FA / Upravit / Deaktivovat tlačítky),
    `Approvals` (jako tap-card na detail faktury), `ActivityLog`,
    `EmailTemplates`, `Codebooks` (Měny / Sazby DPH / Země).
- **`<SearchableSelect>` komponenta** — `web/src/components/ui/SearchableSelect.vue`,
  generic Vue 3 SFC. Combobox pattern (input + dropdown) místo native
  `<select>`. Substring search napříč `label` + volitelným `secondary`
  polem (např. firma + IČ jako secondary). Klávesy ↑↓ Enter Esc, click
  mimo zavře, clearable × tlačítko, ARIA role=combobox/listbox/option.
  Nasazeno v: filter klienta na `/invoices` a `/projects`, výběr klienta
  i zakázky v `InvoiceEditor` (s zachováním `onClientChange` /
  `onProjectChange` callbacků).
- **CSS helper `.table-sticky-first`** v `web/src/styles/main.css` — pro
  tabulky, které na mobilu zůstávají (nemají kartové view). První sloupec
  drží `position: sticky; left: 0`, takže při horizontálním scrollu vlevo
  vidíte identifikátor řádku. Background dědí z `<tr>`, takže hover/status
  barvy fungují; default `white` je nastaven přes `:where()` se specificitou 0,
  aby Tailwind utility (`bg-warning-50`, `hover:bg-neutral-50`, …) na `<tr>`
  stále vyhrály.

### Changed

- **Tabulkové wrappery napříč aplikací** — `overflow-hidden` na karetních
  obalech tabulek nahrazeno za vnitřní `overflow-x-auto` div. Důvod: pod
  `md:` se některé tabulky (např. `InvoiceList` 703 px na 444 px wrapperu)
  s `overflow-hidden` natvrdo ořezávaly, část sloupců (K ÚHRADĚ, STAV) byla
  kompletně nedostupná. Stránky bez `overflow-hidden` zase rozkládaly
  horizontální scroll na celý viewport (854 px doc na 492 px viewport).
  Nový pattern: scroll uzavřený dovnitř karty, layout stránky beze změny.
- **Detail page headers responsivní** — `ClientDetail`, `ProjectDetail`,
  `InvoiceDetail` přepnuty z `flex items-start justify-between` na
  `flex flex-col md:flex-row md:justify-between`. Title + breadcrumb /
  badges nahoře, akční tlačítka (Upravit / Archivovat / Klonovat / PDF /
  Odeslat …) wrap do gridu pod nimi. Žádné kolize titlu s tlačítky na
  malých displayech.


### Added

- **Importy vystavených faktur z Pohoda XML / ISDOC** — nový endpoint
  `POST /api/admin/import` (admin/účetní). Podporuje single soubor `.xml`
  nebo `.isdoc`, případně `.zip` s libovolným počtem těchto souborů uvnitř.
  Per fakturu:
  - **Supplier match** — IČ dodavatele ze souboru musí odpovídat aktuálnímu
    `X-Supplier-Id` scope; jinak se soubor přeskočí.
  - **Klient** — lookup po `(supplier_id, ic)`; pokud neexistuje, fakturační
    adresa se preferenčně tahá z ARES (`AresClient::lookup`), fallback na
    adresu z XML. Vznikne nový `clients` row.
  - **Zakázka** — pokud má faktura `project_number` (ISDOC `OrderReference/ID`
    nebo Pohoda `numberOrder`), najde nebo vytvoří zakázku s tím číslem.
    Když chybí, ale klient má napříč importovaným balíkem >1 unikátních
    e-mailů, vytvoří se per-(klient,e-mail) zakázka s názvem `{Firma} – {email}`.
    Jinak `project_id = NULL`.
  - **Stav** — pokud je `due_date` starší než 30 dní → `paid` (`paid_at` =
    `tax_date` nebo `issue_date`); jinak `issued`. UI to popisuje uživateli
    v info banneru na stránce.
  - **Duplicity** — kontrola po `(supplier_id, varsymbol)`; existující
    se přeskakují s důvodem v reportu.
  - **Snapshoty** — čerstvé z aktuálních supplier/client/bank dat.
- **Frontend stránka `Systém → Importy`** — drag & drop upload, žlutý
  banner o povinnosti existujícího dodavatele, modrý banner o pravidle
  30 dní, tabulka výsledků s odkazem na vytvořené faktury, badge
  `paid` / `issued` a štítky `+ klient` / `+ zakázka`.
- **Manuál** — nová kapitola 14 `13a_Importy.md`.
- **i18n** — sekce `imports.*` (cs + en).

### Changed

- **Exporty zapisují číslo zakázky / smlouvy** — `PohodaXmlExporter` přidává
  `<inv:numberOrder>{project_number}</inv:numberOrder>` do `invoiceHeader`,
  `IsdocExporter` přidává `<OrderReference><ID>{project_number}</ID></OrderReference>`
  a `<ContractReference><ID>{contract_number}</ID></ContractReference>` před
  `AccountingSupplierParty`. Round-trip přes naše vlastní exporty teď
  zachovává linkování na zakázku, a importy z jiných systémů, které tyto
  reference vyplňují, se pokusí přiřadit fakturu k zakázce s odpovídajícím
  číslem (existující najdou, jinak vytvoří).
- **`InvoicePdfRenderer::render(forceRegenerate=true)`** — kromě cache PDF
  obnoví i `supplier_snapshot` / `client_snapshot` / `bank_snapshot` v DB
  z aktuálních live dat. Bez toho se změny v supplier/client tabulce
  (např. toggle `is_vat_payer`) na `issued+` faktury nepropisovaly.
- **PDF šablona faktury** — pro neplátce DPH se ve metadatech místo řádku
  `DUZP` zobrazí `DPH: Není plátce DPH`, sumace skrývá `Základ X %` /
  `DPH X %` / `Celkem bez DPH` / `DPH celkem` (zůstává jen `Celkem`).
  Hlavičkový title bez „— daňový doklad" pro neplátce. Pro proformu
  (i pro plátce DPH) totéž — title jen `Zálohová faktura`, bez DUZP, bez
  rozpisu základů daně.

## [1.5.0] — 2026-05-05

### Added

- **Daňový doklad k zaplacené záloze — automaticky i ručně.**
  Zaplacení zálohové faktury (proforma) teď vede k vystavení **konceptu
  finální faktury** s parent-child vazbou (`parent_invoice_id`),
  zkopírovanými položkami a vyplněným odečtem zálohy
  (`advance_paid_amount = proforma.total_with_vat`). Caller pak fakturu
  jen zkontroluje a vystaví standardním tlačítkem „Vystavit". Tři vstupní
  body:
  - **Tlačítko „Vystavit fakturu k záloze"** v detailu proformy ve stavu
    `paid` — `POST /api/invoices/{id}/issue-final` redirectne do editoru.
  - **Auto-match bankovní transakce** v `StatementMatcher`. Filtr rozšířen
    z `invoice_type='invoice'` na `IN ('invoice','proforma')`. Po
    `auto_exact` na proformě v jedné transakci: `paid` + spárovat TX +
    vytvořit final draft. Audit `proforma.final_issued` s `trigger='bank_match_auto'`.
  - **Manual-match bankovní transakce** v `BankStatementAction::manualMatch`.
    Stejný flow, response navíc obsahuje `final_draft_id`.
- **Sdílená služba `Service/Invoice/FinalFromProformaCreator`** —
  pure logika tvorby draftu, **idempotentní** (opakované volání nebo
  unmatch+rematch nevytvoří duplikát, vrátí id existujícího child draftu),
  **bezpečná na vnořené transakce** (`inTransaction()` detekce,
  vlastní commit jen když ji sama otevřela).
- **PDF poznámka u proformy** — automaticky pod položkami (před totals,
  ve stejném stylu jako reverse-charge note): „Nejedná se o daňový doklad,
  ten bude vystaven po připsání platby." / „This is not a tax document.
  The tax document will be issued after payment is received."
- i18n: `invoice.issue_final`, `invoice.issue_final_confirm`,
  `invoice.issue_final_failed`, `invoice.actions.proforma_final_issued`
  (CS + EN). `note_above_items` na vytvořeném draftu se ukládá
  v jazyce proformy (CS / EN switch dle `proforma.language`).

### Changed

- **DUZP skryto na zálohové faktuře.** Detail faktury (`InvoiceDetail.vue`)
  i PDF (`invoice.twig`) — pro `invoice_type='proforma'` se DUZP ani
  v hlavičce datumové karty, ani v meta-grid PDF nezobrazuje.
  Web UI: hlavička karty je teď „Vystavení / Splatnost" místo
  „Vystavení / DUZP / Splatnost" pro proformy.
- **`IssueFinalFromProformaAction` zrefaktorován** — deleguje na
  `FinalFromProformaCreator`, ponechává jen HTTP validaci
  (`SupplierGuard::owns`, `status='paid'`, `invoice_type='proforma'`)
  a activity log s `trigger='manual'`.

### Fixed

- **PDF rendering selhával na fakturách s odečtem zálohy** —
  `Cannot find TTF TrueType font file "DejaVuSansMono-BoldOblique.ttf"`.
  Skript `cleanup-mpdf-fonts.php` ponechává jen Regular + Bold variantu
  DejaVu Sans Mono kvůli velikosti repa, ale CSS na `.advance` řádku
  v `totals-table` aplikoval `font-style: italic` na celý řádek včetně
  numerické buňky `td.tot-num` (mono+bold), což po kombinaci s italic
  vyžadovalo BoldOblique mono. Italic teď platí jen na popisek
  („Odečet zálohy"), číselná buňka zůstane regular bold mono. Projevilo se
  až po přidání tlačítka „Vystavit fakturu k záloze" — daňový doklad
  k záloze je první případ, kde `advance_paid_amount > 0`.

## [1.4.0] — 2026-05-05

### Added

- **Faktury v cizí měně (EUR / USD / …) — automatický přepočet do CZK.**
  Při uložení EUR faktury si systém stáhne **denní devizový kurz z ČNB**
  pro `issue_date` a uloží na fakturu (`invoices.exchange_rate` +
  `exchange_rate_date`). Kurz se pak používá pro přepočet **základů DPH
  a DPH** do CZK v detailu, PDF i exportech. Položky se nepřepočítávají
  (per spec). Zaokrouhlování HALF_UP per VAT skupina (přes bcmath kvůli
  float precision pro `*.x5` hodnoty).
- **Cache + day-back fallback.** Tabulka `exchange_rates` cachuje
  všechny kurzy z feedu (jeden HTTP call zaplní celý den). Pokud kurz
  pro daný den není dostupný (víkend, svátek, pozdě večer), zkusí
  až 7 dní zpět. Když ČNB nedostupné a žádný cache záznam neexistuje,
  použije se **last-known kurz** s warning toastem v UI.
- **Lazy backfill.** Starší faktury bez kurzu (legacy data) ho automaticky
  doplní při příštím otevření detailu / PDF — `ExchangeRateApplier::ensureRate`.
- **Editace kurzu uživatelem.** Pod polem „Splatnost" v editoru
  (jen pro non-CZK) je editovatelný input kurzu. Manuálně nastavená
  hodnota má prioritu před auto-fetch z ČNB. Kurz se po prvním nastavení
  automaticky **nemění** — refetch jen při změně `currency` nebo
  `issue_date` na draftu; vystavené faktury (force-edit) kurz nikdy
  nepřepisují.
- **CZK přepočet v PDF.** Samostatná tabulka „Přepočet do CZK" pod
  hlavním sumářem se světle šedým podbarvením + drobná řádka kurzu
  pod hlavním celkem. Per-VAT-rate breakdown v CZK.
- **CZK přepočet v ISDOC 6.0.2.** `LocalCurrencyCode=CZK` (účetní měna
  dodavatele), `CurrencyCode=EUR` (faktur. měna), `CurrRate=24.360000`,
  `RefCurrRate=1`. Účetní soft přepočet dopočítá z `CurrRate`.
- **CZK přepočet v Pohoda XML.** Pro non-CZK faktury obsahuje summary
  oba bloky: `inv:homeCurrency` v CZK (z `czk_recap`) a `inv:foreignCurrency`
  s měnou + kurzem + EUR totals. Položky používají `inv:foreignCurrency`.
- **VAT 0 % rozlišení v editoru.** Dropdown sazeb DPH dříve zobrazoval
  „0 %" pro Osvobozeno i Reverse charge — teď `0 % (osvob.)` resp.
  `0 % (RC)` (locale-aware).
- **SEPA EPC QR pro koncepty bez VS.** Faktury v EUR (a dalších non-CZK
  měnách) v draft stavu nyní mají QR kód i bez variabilního symbolu —
  SEPA EPC ho jako identifikátor nepoužívá (jen v poznámce). CZK SPAYD
  stále VS vyžaduje (povinné pole standardu).
- 13 nových PHPUnit testů: `CzkRecapTest` (5) + `CnbExchangeRateClientTest`
  (8) — parser, day-back fallback, normalizace `množství` (JPY/100), CRLF
  line endings, malformed input. Total **132 testů, 245 assertions**.

### Changed

- **Memory rule pro i18n rozšířený o backend.** Pravidlo „all multilanguage
  by default" teď pokrývá i Twig šablony (`t('cs','en')` helper) a
  `I18n\ErrorCatalog::MAP` pro API hlášky.
- **Manuál bumped na v1.4** (2026-05-05). Nové sekce: § 9.4.2 (faktura
  v cizí měně + přepočet), § 10.2.1 (CZK recap v PDF), § 10.3 (SEPA QR
  pro drafts), § 13.5 (kurz CZK v ISDOC + Pohoda XML exportech).

### Fixed

- **GPC parser: Air Bank výpisy s diakritikou v názvu účtu** ([#1]). Pole
  fixed-width hlavičky (074) se parsovala až po `iconv CP1250→UTF-8`, takže
  vícebajtové znaky (`í`, `ý` v `Hlavní podnikatelský`) posunuly všechny
  offsety za polem názvu o 2 bajty — `statement_date` vyšel jako null a
  insert do `bank_statements` failoval s `Integrity constraint violation`.
  Parser teď extrahuje pole z **raw CP1250 bajtů** (single-byte) a UTF-8
  konverzi aplikuje až na konkrétní textová pole. Přidán defenzivní fallback:
  pokud `statement_date` přesto vyjde null, použije se `old_balance_date`
  místo SQL crashe.

[#1]: https://github.com/radekhulan/myinvoice/issues/1

## [1.3.0] — 2026-05-04

### Added

- **Zrušení spárování bankovní transakce.** Tlačítko „Zrušit spárování" v
  detailu výpisu pro stavy `auto_exact / auto_partial / manual / ignored`.
  Konzervativně: fakturu vrátí z `paid` na `issued` jen pokud `paid_at`
  odpovídá datu této transakce a žádná jiná transakce už není spárována
  (chrání ručně označené úhrady). Endpoint
  `POST /api/bank-transactions/{id}/unmatch`, audit `bank.tx_unmatch`.
- **Rychlý filtr na měsíc** v seznamu faktur (ve zvoleném roce). Aktivní
  jen pokud je vybraný rok a není custom datum-rozsah. Funguje i v CSV
  exportu (`filter[month]=N`).

### Changed

- **Graf „Obrat po měsících" → posledních 12 měsíců (rolling).** Místo
  „letošní vs. minulý rok dle kalendářního roku" teď bar zobrazuje
  posledních 12 měsíců a porovnávací linie stejných 12 měsíců o rok
  dříve. X-osa formát `MM/YYYY`. Tooltip ukazuje pár současného a
  minulého měsíce.
- **YoY procento na dashboardu (`change_pct`) je YTD-vs-YTD.** Předtím
  porovnávalo letošní YTD vs. **celý** minulý rok, takže nedokončený rok
  vypadal výrazně hůř. Teď se porovnává minulý rok jen do stejné
  kalendářní pozice (`<= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)`); v
  tooltipu ukázané oba kontexty (YTD i celý rok).
- **Proformy se nepočítají do obratu nikde.** Dashboard
  (`issued_count_ytd`), detail klienta (`revenue_by_year`,
  `revenue_by_month`), `project_revenue_cache`, `client_revenue_cache`
  i `ProjectStatsAction` (top zakázky, totals) — všechny filtrují na
  `invoice_type IN ('invoice', 'credit_note')`. Proforma není daňový
  doklad, neměla by ovlivňovat metriky obratu. Cache se přepočítá přes
  `php api/bin/recompute-stats.php`.
- **Pagination invoices** zvětšen z 20 na 50 řádků na stránku
  (`pagination.invoices_per_page`).

## [1.2.0] — 2026-05-03

### Added

- **Approval token expiration.** Schvalovací odkaz vyprší za N dní (config
  `approval.token_ttl_days`, default 30). Předtím token nikdy neexpiroval —
  bezpečnostní upgrade. Detail faktury ukazuje `Platnost odkazu do …` a po
  vypršení badge „Vypršel" + nabídku „Odeslat znovu" (regenerace tokenu).
- **Reminder cron pro neschválené výkazy.** Nový skript
  `api/bin/cron-send-approval-reminders.php` (volatelný denně) najde
  faktury s `approval_status='requested'` starší než N dní a pošle stejný
  e-mail jako původní žádost, jen s flagem reminder (jiný subject + úvodní
  upozornění). Konfigurace `approval.reminder_after_days`, `max_reminders`
  (default 5 dní, max 3 upomínky), `cc_supplier_on_reminder` (BCC dodavateli
  pro audit). Audit log entry: `invoice.approval_reminder_sent`.
- **Volitelný komentář při schválení.** Veřejná schvalovací stránka má teď
  textareu „Komentář ke schválení (volitelné)" v review mode + admin
  „Změnit stav → Schválen" také. Komentář sdílí existující sloupec
  `approval_rejection_reason` (žádná DB migrace), v detailu faktury
  zobrazený s vhodným labelem podle stavu (důvod zamítnutí / komentář).
- **Admin „Approval inbox"** (`/admin/approvals`, admin-only). Globální
  tabulka všech schvalování s filtry (Vyžádán / Schválen / Zamítnut / Vše),
  toggle „Jen po 5 dnech bez reakce", počty per stav, sloupce: faktura,
  klient, zakázka, K úhradě, stav (badge včetně „Vypršel"), datum žádosti
  + „před X dny", počet upomínek, komentář/důvod. Položka v admin menu.
- **Migrace 0003** — `invoices.approval_token_expires_at`,
  `approval_reminder_at`, `approval_reminder_count` + index pro cron query.

### Changed

- `RequestApprovalAction` čerpá TTL tokenu z `cfg.approval.token_ttl_days`
  místo natvrdo bez expiry.
- `findByApprovalToken()` filtruje expired tokeny — public stránka pak
  vrátí stejný `token_invalid_or_expired` jako pro neexistující.

## [1.1.0] — 2026-05-03

### Added

- **Work-report approval workflow** (M8). Customers can approve a work
  report via emailed link before the related invoice is issued.
  - Project flag `requires_work_report_approval` (Project edit form,
    detail badge).
  - Public token-based approval page at `/approval/{token}` (CAPTCHA-protected,
    no login required).
  - Standalone work-report PDF (`Vykaz-XYZ.pdf`) generated for the approval
    email — full invoice PDF only after approval.
  - `invoice_approval` email template (cs/en, html+txt) with a prominent
    "Approve work report" CTA.
  - `IssueInvoiceAction` blocks issue when project requires approval **and**
    the invoice has a work report — invoices on the same project without
    a work report still issue normally.
  - On approval (public or admin override), `AutoIssueAndSendService` issues
    the invoice and sends it through the standard `invoice_send` flow.
  - Admin-only "Change status" modal in invoice detail (manual override).
  - Audit-log entries for `approval_requested`, `approval_approved`,
    `approval_rejected`, `approval_reset`.
  - Migration `0002_work_report_approval.sql` (project flag + invoice
    approval columns + unique token index).
  - Manual chapters 1, 7.6 and 9.7 with screenshots; README updated.
- **"Issue invoice" button** on project detail (only for active projects);
  pre-fills client + project in the invoice editor.
- **PHP runtime errors routed to `log/php-errors.log`** instead of the
  system php_errors.log. `display_errors` follows `app.env` (dev=on,
  prod=off).
- **Manual: light fixed sidebar redesign** with high-contrast headers,
  accent group bars and a primary "Back to admin" button.
- **i18n coverage** for invoice detail/editor (force-edit warning + popup,
  bank not set, items table headers, work-report buttons), CS+EN.

### Changed

- **Toast unification** across admin pages (Codebooks, Settings,
  InvoiceDetail, ClientDetail, ProjectDetail) — replaced page-local flash
  divs and native `alert()` with the global `useToast` composable so
  notifications are visible regardless of scroll position.
- **Empty work-report rows** silently skipped on the frontend so totals
  stay consistent with what is persisted; backend still validates
  defensively with row-level human-readable error messages.
- **`pushWrToInvoiceItem`** now reuses the empty placeholder row from
  `blankItem()` instead of appending a duplicate.
- **Confirm dialog before save** when the work report is out of sync with
  the corresponding invoice item (different hours/rate, or report exists
  but no matching item description).
- **Manual chapters 7 and 9** rewritten/extended to cover the approval
  workflow, with two new screenshots (`09_schvalit_vykaz_prace.webp`,
  refreshed `09_vykaz_vicepraci.webp`).

### Fixed

- **PDF cache invalidated after issue** (manual `IssueInvoiceAction` and
  automatic `AutoIssueAndSendService`). Without this the renderer would
  return the stale draft PDF (wrong varsymbol, missing 2nd-page work
  report) when a PDF preview existed before issue.

### Build / DevOps

- **`production.cmd` deploy speed-up** (variant B): `api/vendor` is
  renamed to `api/vendor.dev.bak` before `composer install --no-dev`,
  then restored by an instant rename instead of a second
  `composer install`. Saves ~30–60 s per deploy. Safety guard at script
  start aborts if a stale `vendor.dev.bak` is found.
  *(`production.cmd` is gitignored — change is local-only.)*

## [1.0.0] — 2026-05-02

### Initial public release

First public release on GitHub. Highlights:

- **Invoicing.** 4 document types (invoice, proforma, credit note,
  internal cancellation), draft → issued → paid lifecycle with immutable
  snapshots, work reports as page 2 of the PDF, bulk actions (reissue,
  send, mark paid, reminder).
- **Payments.** QR codes in PDF (SPAYD for CZK, SEPA EPC for EUR), GPC
  bank-statement import (KB / FIO / ČSOB / RB / ČS) with SHA256 dedupe
  and auto-matching by VS + amount.
- **Clients & projects.** ARES + VIES lookup, projects 1:N under a
  client, per-project billing emails, reverse charge per client.
- **Multi-supplier.** One installation can invoice for any number of
  suppliers (companies / IČs); isolated data, per-supplier varsymbol
  series, currencies, ARES details, logo, SMTP `From:` and `Reply-To:`,
  Pohoda codes.
- **Exports.** PDF ZIP per month, ISDOC 6.0.2, Pohoda XML (Stormware
  data package).
- **Email.** Symfony Mailer + Twig templates editable in admin UI
  (cs/en, html+txt), DKIM signing.
- **Security.** TOTP 2FA, IP allowlist (IPv4 + IPv6 + CIDR),
  Cloudflare Turnstile CAPTCHA, brute-force protection (Redis or MariaDB
  MEMORY fallback), CSRF + Origin check, peppered bcrypt passwords,
  RBAC (admin / accountant / readonly), activity log of all mutations.
- **Dashboard.** KPI tiles per active currency, top clients, monthly
  revenue chart, overdue / unpaid invoice list.
- **CZ + EN UI** and invoice templates.
- **Docker** (3-min quick start) + native install.
- **17-chapter user manual** (`/manual`) generated from Markdown.
- **MIT license**, security policy.

[1.2.0]: https://github.com/radekhulan/myinvoice/releases/tag/v1.2.0
[1.1.0]: https://github.com/radekhulan/myinvoice/releases/tag/v1.1.0
[1.0.0]: https://github.com/radekhulan/myinvoice/releases/tag/v1.0.0
