# 37. Bankovní účty a e-mailová avíza (IMAP)

**Cesta: `Finance → Bankovní účty`**

Stránka sdružuje čtyři záložky: **Bankovní výpisy** (import GPC a párování
plateb — viz [24. Banka](24_Banka.md)), **Měny a účty**, **Stavy na účtech**
a **Bankovní avíza z e-mailu**. Záložky kromě výpisů vidí jen administrátor.

Tato kapitola popisuje správu **bankovních účtů dodavatele** (pro PDF faktury,
QR platby a GPC výpisy) a **bankovních e-mailových avíz přes IMAP**. Bankovní avízo je
e-mail od banky s údaji o platbě — MyInvoice ho umí pravidelně načítat, vytěžit
z něj VS, částku, měnu, datum a cílový účet a vytvořit z něj bankovní transakci
stejně jako z [výpisu](24_Banka.md).

## 37.1 Bankovní účty

Sekce **Měny + bankovní účty** je čistý seznam účtů dodavatele. Účet zde
nastavuješ stejně jako pro PDF faktury, QR platby a GPC výpisy:

- měna a označení účtu,
- české číslo účtu + kód banky,
- případně IBAN/BIC,
- výchozí účet pro danou měnu,
- aktivní/neaktivní stav.

Nastavení bankovních avíz je oddělené níže, aby se běžné bankovní údaje
nemíchaly s parsery a IMAP účty.

### Stavy na účtech

Záložka **Stavy na účtech** zobrazuje každý bankovní účet samostatně podle
čísla účtu, kódu banky a měny. Aktuální stav vychází z posledního oficiálního
GPC nebo PDF výpisu; pokud je dostupné novější e-mailové avízo s disponibilním
zůstatkem, použije se novější údaj.

Pod tabulkou je pro každý účet samostatný graf měsíčních konečných zůstatků
v jeho vlastní měně. Graf **Celkový vývoj v CZK** zobrazuje jednotlivé účty
i řadu **Celkem**. Cizoměnové účty se pro tento graf přepočítávají kurzem ČNB
ke konci příslušného měsíce.

Pokud mají dva účty stejné číslo před lomítkem, rozlišují se kódem banky.
Starý výpis bez uloženého kódu banky se při více možných bankách do zůstatku
nezapočte, protože jej nelze bezpečně přiřadit.

## 37.2 Mapování bankovních avíz

Sekce **Mapování bankovních avíz** určuje, jak se vytěžený e-mail napojí na
konkrétní bankovní účet dodavatele.

| Sloupec | Význam |
|---|---|
| Bankovní účet | Účet z měn dodavatele, proti kterému se porovnává cílový účet v e-mailu |
| IMAP účet | Konkrétní schránka, ze které se má avízo pro tento účet brát; „Žádný IMAP účet" = výchozí stav bez skenování, „Všechny IMAP účty" = neomezeno |
| Parser | Konkrétní parser provider; „Automatický výběr" = systém zkusí všechny aktivní providery |
| Tolerance | Povolená odchylka částky při párování faktury, např. `1.00` pro ±1 Kč |
| Aktivní | Vypnutý řádek se při scanování nepoužije |

Mapování se vyhodnocuje až po úspěšném vytěžení e-mailu. Pokud e-mail přijde
z jiného IMAP účtu nebo ho zpracoval jiný parser, než je v mapování nastaveno,
řádek se nepoužije.

Nové nebo nenastavené mapování začíná volbou **Žádný IMAP účet**. Takový řádek
se při scanování nepoužije, dokud nezvolíš konkrétní IMAP účet nebo vědomě
nepovolíš variantu **Všechny IMAP účty**.

## 37.3 IMAP účty pro bankovní avíza

Každý dodavatel může mít více IMAP účtů, typicky jeden pro každou banku.

| Pole | Význam |
|---|---|
| Název | Popisek v UI, např. „RB avíza" |
| Host / port / šifrování | Připojení k IMAP serveru |
| Uživatel / heslo | Přístup ke schránce; heslo se ukládá šifrovaně |
| Složka | IMAP složka, např. `INBOX` nebo `INBOX.Banka` |
| Procházet | Ověří připojení a nabídne složky ze serveru |
| Max. zpráv na běh | Kolik nejnovějších e-mailů cron načte při jednom běhu |
| Zpracovat od data | Starší e-maily se ignorují i když spadnou do limitu |
| Přijímat přeposlaná (FW) avíza | Rozpozná banku i z těla e-mailu, když avíza chodí do schránky přeposlaná (odesílatel je tvoje adresa, ne banka) |
| E-mail přeposílatele | Volitelné omezení, od koho smí přeposlaná avíza chodit — adresa (`jan@firma.cz`) nebo doména (`firma.cz`); prázdné = libovolný |
| Po úspěchu | Co udělat se zpracovanou zprávou |

Pokud do schránky chodí avíza **přeposlaná** (např. z firemní schránky na sběrnou
adresu), zapni **Přijímat přeposlaná (FW) avíza**. U přímého avíza poznává banku
podle odesílatele, ale přeposláním se odesílatelem stáváš ty — proto se pak banka
hledá i z těla e-mailu. Volitelně omez **E-mail přeposílatele**, ať se zpracují
jen avíza od tvé adresy. Přeposláním zaniká původní podpis banky (DKIM), takže
ověření autenticity se vztahuje na přeposílatele, ne na banku.

Polling zprávy standardně **neoznačuje jako přečtené**. Systém si úspěšně
zpracované e-maily pamatuje v databázi podle `Message-ID` / UID / fallback
hashe, takže funguje i s účtem, kde aplikace nemůže zprávy přesouvat nebo
označovat. Pokud má účet zápis povolený, můžeš zvolit doplňkovou akci po
úspěchu: neměnit zprávu, přidat flag, přesunout do jiné složky nebo označit
jako přečtené.

## 37.4 Parser provideri

Provider říká, jak poznat e-mail dané banky a jak z něj vytěžit platební údaje.

Typy providerů:

- **Systémový provider** — dodaný aplikací, např. Raiffeisenbank, UniCredit Bank, ČSOB, Česká spořitelna, Fio banka nebo Banka CREDITAS.
- **Regex provider** — vlastní provider dodavatele, konfigurovaný v UI.

Systémový provider se přímo needituje (je společný pro všechny). Když ho chceš
upravit, použij u něj tlačítko **Duplikovat** — vytvoří se editovatelná kopie,
ve které si dolaď vzory a otestuj ji přes **Test parseru**. V mapování účtu pak
přepneš účet z původního providera na svou kopii. Duplikovat lze i vlastní regex
provider.

Detekce e-mailu i vytěžení polí pracují **tolerantně k diakritice**: pokud avízo
dorazí v jiném kódování nebo s rozbitou diakritikou (typicky u přeposlaných
zpráv), vzory `Směr platby` a `Smer platby` se vyhodnotí stejně. Když přesto
nějaký provider zlobí, můžeš si vzory napsat rovnou bez diakritiky.

U regex provideru nastavuješ:

| Pole | Význam |
|---|---|
| Název / kód | Interní identifikace provideru |
| Odesílatel | Whitelist e-mailů, např. `info@rb.cz` |
| Regex předmětu | Volitelný pattern pro subject, např. `Pohyb\s+na\s+účtě` |
| Regex těla | Volitelný pattern, který musí být v těle e-mailu |
| Vytěžená pole | Regexy pro VS, částku, měnu, datum, cílový účet atd. |

Povinná vytěžená pole:

- `variable_symbol`
- `amount`
- `currency`
- `posted_at`
- `recipient_account`

Volitelná pole:

- `counterparty_account`
- `counterparty_name`
- `constant_symbol`
- `message`
- `bank_ref`
- `balance` (disponibilní zůstatek účtu z avíza — zobrazí se v detailu
  měsíčního avízo-výpisu a promítne se do přehledu **Stavy na účtech**)

Regex parser používá první zachycenou skupinu nebo pojmenovanou skupinu se
stejným názvem jako pole. Pro částku umí formáty typu `+1.234,56`, datum např.
`01. 06. 2026 10:15`.

## 37.5 Příklad regex provideru pro Raiffeisenbank

Následující příklad je **anonymizovaný**. Čísla účtů, variabilní symbol, název
protistrany i zpráva jsou fiktivní. Do manuálu nikdy nedávej reálné e-maily
z banky s osobními údaji, zůstatky nebo skutečnými čísly účtů.

Testovací text e-mailu může vypadat např. takto:

```text
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
```

Základní nastavení provideru:

| Pole | Hodnota |
|---|---|
| Název | `Raiffeisenbank regex test` |
| Kód | `raiffeisenbank_regex` |
| Aktivní provider | Ano |
| Odesílatel | `info@rb.cz` |
| Regex předmětu | viz níže |
| Regex těla | `Variabilní\s+symbol` |
| Normalizer config | `{}` |

Regex předmětu:

```text
Pohyb\s+na\s+účtě|Pohyb\s+na\s+ucte
```

Regexy pro vytěžená pole:

| Pole | Regex |
|---|---|
| Datum platby | `Datum\s+a\s+čas\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})` |
| Cílový účet | `Na\s+účet\s*([0-9-]+/[0-9]{4})` |
| Částka | `Částka\s+v\s+měně\s+účtu\s*([+\-]?[0-9 .]+,[0-9]{2})\s*[A-Z]{3}` |
| Měna | `Částka\s+v\s+měně\s+účtu\s*[+\-]?[0-9 .]+,[0-9]{2}\s*([A-Z]{3})` |
| Protiúčet | `Z\s+účtu\s*([0-9-]+/[0-9]{4})` |
| Název protistrany | `Z\s+účtu\s*[0-9-]+/[0-9]{4}\s*([^\n]+?)\s*Variabilní\s+symbol` |
| Variabilní symbol | `Variabilní\s+symbol\s*([0-9]+)` |
| Konstantní symbol | `Konstantní\s+symbol\s*([0-9]+)` |
| Zpráva | `Zpráva\s+pro\s+příjemce\s*(.*?)\s*Disponibilní\s+zůstatek` |
| Reference banky | prázdné |
| Disponibilní zůstatek | `Disponibilní\s+zůstatek(?:\s+po\s+pohybu)?\s*([+\-]?[0-9 .]+,[0-9]{2})` |

> 🛈 Do UI zadávej regex bez krajních oddělovačů (`/.../`). Parser je doplní
> sám.

## 37.6 Test parseru a zpracované e-maily

V sekci **Parser provideri** můžeš vložit testovací e-mail, odesílatele a
předmět. Test ukáže, který provider se použil a jaká pole se vytěžila.

Sekce **Zpracované e-maily** je debug přehled:

- zobrazuje `Message-ID` / fallback hash,
- IMAP účet,
- stav zpracování,
- použitý provider,
- vytěžené platební údaje,
- navázanou transakci nebo fakturu.

Smazání záznamu zde nemaže transakci ani fakturu. Maže jen deduplikační záznam,
takže je možné stejný e-mail znovu zpracovat při dalším scanu. Používej to jen
jako emergency/debug akci.

## 37.7 Cron pro e-mailová avíza

Pro automatické zpracování nastav samostatný cron:

```bash
cmd/cron-bank-email-notices.sh   # každých 30 minut
```

Skript spustí `php api/bin/cron-bank-email-notices.php`, projde aktivní IMAP
účty dodavatele, načte nejnovější zprávy podle limitu a zapíše heartbeat do
plánovaných úloh.
