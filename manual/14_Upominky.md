# 14. Upomínky po splatnosti

Když klient nezaplatil včas, můžeš mu poslat **upomínku** — speciální e-mail
s textem typu „Vaše faktura č. XXX byla splatná YY dní zpět, prosíme o úhradu".

Upomínky lze posílat **3 způsoby**:

1. **Manuálně** z detailu jedné faktury (tlačítko)
2. **Hromadně** z [Seznamu faktur](09_Faktury.md) (bulk action)
3. **Automaticky** z cronu (`cron-send-reminders.php`)

## 14.1 Předpoklady

Aby šla upomínka odeslat, faktura musí:

- Být typu `Faktura` (ne proforma, dobropis ani storno)
- Být ve stavu `issued`, `sent` nebo `reminded`
- Být **po splatnosti** (`due_date < dnes`)
- Mít k dispozici klientův e-mail (hlavní + případné fakturační)
- Mít zapnutý přepínač **Posílat automatické upomínky** (viz § 14.7) — týká se
  jen automatického cronu; ruční i hromadné odeslání funguje vždy.

## 14.2 Manuální upomínka

Otevři [Detail faktury](12_Faktura_PDF.md) → tlačítko **Upomínka**.

![Tlačítko upomínka](img/12_upominka_btn.webp)

Po kliknutí:

- E-mail jde na: `klient.hlavni_email + zakazka.fakturacni_emaily[]`
- Šablona: `invoice_reminder` (CZ / EN podle jazyka klienta)
- Status faktury → `reminded`
- `last_reminder_at` = teď
- `reminder_count` += 1

Activity log: `invoice.reminded` s počtem dní po splatnosti.

### 14.2.1 Test upomínky

Vedle **Upomínka** je tlačítko **Test upomínky** — pošle stejný e-mail jen na
**tvůj** e-mail (admina, kterého jsi přihlášen). Užitečné pro:

- Vyzkoušení šablony před odesláním klientovi
- Ověření, že SMTP funguje
- Náhled, jak vypadá HTML verze e-mailu v tvém klientu

## 14.3 Hromadná upomínka

Z **Faktury → filtr „Po splatnosti"** zaškrtni více faktur → bulk action
**Upomínka (N)**.

![Hromadná upomínka](img/12_upominka_bulk.webp)

Server:

1. Pro každou fakturu zkontroluje, že splňuje předpoklady (§ 14.1)
2. Cooldown — pokud byla upomínka poslána před **<14 dny**, faktura se
   přeskočí
3. Pošle e-mail
4. Update statusu

Hláška o výsledku: `Odesláno: 8, přeskočeno (cooldown): 2, chyb: 0`.

## 14.4 Cron — automatické upomínky

Pro pravidelné upomínání nastav cron:

```bash
cmd/cron-send-reminders.sh    # 1× denně, doporučeně 09:00 Po–Pá
```

Skript `php api/bin/cron-send-reminders.php` má parametry:

| Parametr | Default | Význam |
|---|---|---|
| `--days=N` | (per dodavatel) | Faktura musí být po splatnosti alespoň N dní. Bez parametru se čte **práh nastavený u dodavatele** (§ 14.7, default 3); `--days` ho pro daný běh přebije. |
| `--cooldown=N` | `7` | Min. počet dní mezi dvěma upomínkami stejné faktury |
| `--dry-run` | — | Jen vypíše, co by udělal, **bez odeslání** |

### 14.4.1 Doporučené nastavení

```cron
# Po-Pá v 9:00 — upomínat faktury 5+ dní po splatnosti, max 1× za 14 dní
0 9 * * 1-5  /var/www/myinvoice.cz/cmd/cron-send-reminders.sh --days=5 --cooldown=14
```

> 💡 `--days=5` je rozumný „grace period" — klient mohl mít dovolenou,
> bankovní poplatek, nebo sis ty zapomněl naimportovat výpis.

### 14.4.2 Dry-run pro test

Před produkčním nasazením:

```bash
php api/bin/cron-send-reminders.php --days=5 --dry-run
```

Vypíše:

```
[dry-run] Faktura #2604012 (ACME s.r.o., 12 dní po splatnosti) — by se odeslala na 3 adresy
[dry-run] Faktura #2604015 (Studio Fialka, 7 dní po splatnosti) — by se odeslala na 1 adresu
[dry-run] Faktura #2604008 — přeskočena (poslední upomínka před 4 dny < cooldown 14)
[dry-run] CELKEM: 2 by se odeslaly, 1 přeskočena.
```

## 14.5 Šablona upomínky

Šablona je v **Systém → E-mail šablony → invoice_reminder**.

![Editor šablony upomínky](img/12_sablona.webp)

Můžeš editovat:

- **Předmět** — `{{ varsymbol }}` placeholder pro VS faktury
- **HTML tělo** — Twig template
- **Plain text tělo** — fallback pro klienty bez HTML

### 14.5.1 Dostupné placeholders

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol faktury |
| `{{ amount }}` | Částka k úhradě, formátovaná |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Datum splatnosti |
| `{{ days_overdue }}` | Počet dní po splatnosti |
| `{{ client_name }}` | Jméno klienta |
| `{{ supplier_name }}` | Jméno dodavatele |
| `{{ payment_link }}` | (volitelné) odkaz na platební bránu |
| `{{ reminder_count }}` | Počet již odeslaných upomínek (1 = první, 2 = druhá, …) |

### 14.5.2 Multi-jazyčnost

Pro každou šablonu jsou **4 varianty**:

- `cs.html` (CZ HTML)
- `cs.txt` (CZ plain)
- `en.html` (EN HTML)
- `en.txt` (EN plain)

Vybere se podle `klient.language`.

## 14.6 Tipy

- **Cooldown 14 dní** je rozumný — kratší by byl agresivní, delší se obchází.
- **Eskalace tónu** — pomocí `{{ reminder_count }}` můžeš v šabloně použít
  Twig logiku: `{% if reminder_count >= 3 %}poslední výzva{% endif %}`.
- **Cron nepouštěj v sobotu/neděli** — klient nečte e-maily, vyřeší to až
  v pondělí, ale na statistikách to vypadá divně. Cron expression `1-5`
  (Po–Pá) je standard.
- **Po druhé upomínce zvaž osobní telefonát** — automatika neřeší vztahy.
  E-mailová upomínka je jen formalita.
- **Test upomínky** = vždy před produkčním cronem. Nešťastné je posílat
  klientovi rozbitý HTML.

## 14.7 Vypnutí upomínek u konkrétní faktury a práh dní

Automatické upomínky lze řídit na třech úrovních; cron pošle upomínku, jen když
**všechny tři** dovolí (zapnuto u dodavatele **i** u klienta **i** u faktury):

| Úroveň | Kde | Význam |
|---|---|---|
| Dodavatel | Nastavení → dodavatel | Globální vypnutí pro celého dodavatele |
| Klient | Detail klienta | Vypnutí pro všechny faktury daného klienta |
| **Faktura** | Editor faktury | Vypnutí pro jedinou konkrétní fakturu |

### 14.7.1 Přepínač na faktuře

V [editoru faktury](11_Faktura_editor.md) je v pravém boxu **Datumy**, hned pod
polem *Splatnost*, přepínač **Posílat automatické upomínky** (výchozí: zapnuto).
Když ho vypneš, cron tuto fakturu přeskočí, i kdyby měl dodavatel i klient
upomínky zapnuté. **Ruční i hromadné** odeslání upomínky funguje vždy. U dobropisů
se přepínač nezobrazuje (dobropisy se neupomínají).

### 14.7.2 Práh „po kolika dnech po splatnosti"

V **Nastavení → dodavatel** nastavíš, **po kolika dnech po splatnosti** se má
poslat první automatická upomínka. Na výběr jsou předvolby **3 dny / týden /
měsíc** nebo **vlastní** počet dní (1–365). Hodnota je per dodavatel; cron ji čte
automaticky. Parametr `--days=N` (§ 14.4) ji pro daný běh přebije — hodí se pro
mimořádný / ruční běh.

## 14.8 Kontrola, co se opravdu odeslalo (a co ne)

V **Systém → Odeslané e-maily** je přehled **všech** e-mailů, které aplikace
rozeslala — odeslání faktur, upomínky, schvalovací upomínky, poděkování za
úhradu, připomínky konceptů i testovací odeslání. Automatické (cron) odeslání
jsou připsána „Systému".

Přehled ukazuje **i neúspěšná odeslání**: když odeslání selže (nedostupný SMTP,
odmítnutý příjemce, chyba při generování PDF), zapíše se červený řádek se stavem
**Neodesláno** a textem chyby. Filtr **stavu** (Vše / Odesláno / Neodesláno) a
zkratka **Neodesláno: N** umožní rychle najít, co je potřeba poslat znovu.

> ⚠️ „Odesláno" znamená, že e-mail **převzal SMTP server** — nezaručuje doručení
> do schránky (odmítnutí mailserverem příjemce / spam filtr aplikace netrackuje).
> Pokud cron upomínku **přeskočí** kvůli předpokladům z § 14.1 (např. klient nemá
> e-mail), nejde o „selhání odeslání" a v přehledu se jako chyba neobjeví.
