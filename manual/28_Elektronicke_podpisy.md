# 28. Elektronické podpisy

Sekce **Systém -> Elektronické podpisy** slouží ke správě certifikátů,
podpisových profilů a pravidel, který profil se použije pro konkrétní výstup.
Aktuálně se podepisují PDF výstupy a vybrané odchozí e-maily.

PDF výstupy:

- vydaná faktura,
- samostatný výkaz práce.

Když je výkaz práce vložený jako další stránka PDF faktury, podpis výstupu
**Vydaná faktura** pokrývá celé výsledné PDF včetně této stránky. Výstup
**Výkaz práce** se používá pro samostatně generované PDF výkazu.

S/MIME e-mailové výstupy:

- e-mail s fakturou,
- e-mail s upomínkou,
- e-mail s připomínkou zálohy,
- e-mail s poděkováním za úhradu,
- e-mail se schválením výkazu,
- e-mail s připomínkou pravidelné faktury.

PDF používá PAdES podpis. Bez časového razítka jde o úroveň **PAdES-B**, s
nastaveným TSA serverem o **PAdES-T**. Odchozí e-mail se podepisuje jako
**S/MIME** zpráva. S/MIME podpis potvrzuje odesílatele a integritu zprávy,
ale e-mail nešifruje.

## 28.1 Základní pojmy

| Pojem | Význam |
|---|---|
| Podpisový profil | Pojmenované nastavení podpisu. Obsahuje vlastnictví, použití, backend, volitelnou PDF/TSA konfiguraci a jeden společný certifikát profilu. |
| Profil dodavatele | Profil vlastněný dodavatelem. Spravuje ho admin a může se použít jako centrální firemní podpis. |
| Můj profil | Profil vlastněný konkrétním uživatelem. Použije se jen tam, kde konfigurace výstupu počítá s přihlášeným uživatelem. |
| Konfigurace podpisů | Admin nastavení, které určuje, zda se PDF nebo e-mailový výstup podepisuje a odkud se bere podpisový profil. |
| Mapování podpisových profilů | Uživatelské výchozí profily pro výstupy, kde admin zvolil strategii **Přihlášený uživatel**. |

## 28.2 Oprávnění

| Role | Co může |
|---|---|
| **admin** | Spravuje profily dodavatele, může spravovat i profily uživatelů, nastavuje konfiguraci podpisů a povoluje uživatelské profily. |
| **accountant** | Po povolení adminem může spravovat pouze vlastní podpisové profily a vlastní výchozí mapování. V detailu dokladu může změnit výběr profilu, ale konkrétní profil dodavatele může vybrat jen admin. |
| **readonly** | Může číst a stahovat doklady podle běžných oprávnění, ale nemůže měnit podpisové profily, mapování ani per-dokladový výběr podpisu. |

Admin povolí uživatelské profily přepínačem **Povolit uživatelům správu
vlastních podpisových profilů**. Pokud není zapnutý, účetní v menu sekci
elektronických podpisů nevidí.

## 28.3 Založení podpisového profilu

1. Otevři **Systém -> Elektronické podpisy**.
2. V sekci **Podpisové profily** klikni **Nový profil**.
3. Vyplň **Název** a **Kód**. Kód je technický identifikátor profilu a musí být
   unikátní v rámci dodavatele.
4. Vyber **Vlastník profilu**:
   - **Profil dodavatele** pro centrální firemní podpis,
   - **Můj profil** pro podpis konkrétního přihlášeného uživatele,
   - **Jiný uživatel** jen pro admina, pokud profil zakládá za konkrétního
     uživatele.
5. V části **Použití** vyber, k čemu se profil smí použít:
   - **PDF** pro podpis faktur a výkazů práce,
   - **S/MIME e-mail** pro podpis odchozích e-mailů,
   - obě volby, pokud stejný certifikát používáš pro PDF i e-mail.
6. Backend profilu ponech `native`. E-mailové výstupy používají S/MIME backend
   interně podle typu výstupu.
7. Nech profil aktivní, pokud se má dát použít při podepisování.
8. Ulož profil.

## 28.4 Certifikát P12/PFX

Ke každému profilu se nahrává jeden společný certifikát. Stejný P12/PFX soubor
se může použít pro PDF podpis i pro S/MIME podpis e-mailu, pokud profil povoluje
obě použití.

1. Otevři editaci profilu.
2. V části **Certifikát profilu** klikni **Vybrat soubor**.
3. Vyber soubor ve formátu **P12/PFX** s privátním klíčem.
4. Zadej **Heslo k certifikátu**. Aplikace ho použije pro kontrolu souboru a
   podle zvolené politiky ho buď uloží šifrovaně, nebo jen ověří.
5. Vyber politiku hesla.
6. Klikni **Uložit profil** nebo **Nahrát certifikát** podle toho, jestli profil
   teprve vytváříš, nebo upravuješ.

Po nahrání se zobrazí metadata certifikátu: subject, e-mail v certifikátu,
platnost, politika hesla a SHA-256 fingerprint. Soubor certifikátu se ukládá do
interního storage aplikace. V produkci je vhodné mít `MYINVOICE_DATA_DIR`
nastavený mimo webový root.

Certifikát bez privátního klíče nestačí. Pokud import selže s chybou špatného
hesla nebo neplatného PKCS#12, zkontroluj, že P12/PFX opravdu obsahuje privátní
klíč a že zadáváš správnou passphrase.

Pro S/MIME podpis je prakticky důležité, aby certifikát obsahoval e-mailovou
adresu používanou jako odesílatel nebo aby ho příjemcův klient uměl přiřadit k
odesílateli. Aplikace podpis vytvoří, ale důvěryhodnost a shoda identity se
vyhodnocuje až v e-mailovém klientovi příjemce.

## 28.5 Politika hesla k certifikátu

| Politika | Kdy použít | Chování |
|---|---|---|
| **Uložit šifrovaně** (`encrypted_store`) | Běžný produkční režim a background joby. | Heslo se uloží v DB šifrovaně pomocí aplikačního klíče. Běžný uživatel ho nevidí a API ho nikdy nevrací. |
| **Passphrase file** (`passphrase_file`) | Když nechceš heslo ukládat do DB, ale aplikace musí podepisovat i bez interaktivního vstupu. | V profilu se uloží jen ID hesla. Skutečné heslo se čte ze serverového souboru nastaveného v konfiguraci. |
| **Ptát se při použití** (`prompt_on_use`) | Interaktivní podpis na vyžádání. | V této iteraci není pro runtime podpisy podporováno. Pro PDF i S/MIME zvol šifrované uložení nebo passphrase file. |

### Passphrase file

Cestu k souboru nastav správce v konfiguraci:

```php
'signing' => [
    'passphrase_file' => '/var/lib/myinvoice/signing-passphrases.json',
],
```

Kvůli zpětné kompatibilitě se bere i `pdf_signing.passphrase_file`.

Soubor může být JSON:

```json
{
  "profiles": {
    "owner_john": { "passphrase": "heslo-k-p12" },
    "owner_novak": { "passphrase": "jine-heslo" }
  }
}
```

Nebo INI:

```ini
[owner_john]
passphrase=heslo-k-p12

[owner_novak]
passphrase=jine-heslo
```

Do pole **ID hesla v passphrase file** v profilu zadej například
`owner_john`. Soubor musí být čitelný procesem aplikace a neměl by být
součástí webového rootu ani gitu.

## 28.6 TSA a důvod podpisu

V profilu je volitelná část **PDF nastavení profilu**. Tato nastavení platí jen
pro PDF podpisy:

| Pole | Význam |
|---|---|
| **Použít časové razítko** | Zapne PAdES-T. Po zapnutí je povinná TSA URL. |
| **TSA URL** | RFC 3161 endpoint časové autority, například URL služby poskytovatele časových razítek. |
| **TSA jméno / heslo** | HTTP Basic auth, pokud ho TSA server vyžaduje. |
| **Důvod podpisu** | Textový důvod v PDF podpisu. Když zůstane prázdný, použije se výchozí text podle typu dokumentu: `Faktura` nebo `Výkaz práce`. |

Bez TSA se dokument podepíše jako PAdES-B. Pokud je TSA nastavená a dostupná,
přidá se důvěryhodné časové razítko a výsledkem je PAdES-T.

S/MIME podpis odchozího e-mailu v této implementaci TSA nepoužívá.

## 28.7 Konfigurace podpisů pro výstupy

Sekci **Konfigurace podpisů** vidí admin. Každý řádek nastavuje jeden typ
výstupu: buď PDF, nebo S/MIME e-mail.

| Sloupec | Význam |
|---|---|
| **Výstup** | Například **Vydaná faktura**, **Výkaz práce** nebo **E-mail s fakturou**. Badge **PDF** nebo **S/MIME** říká, jaký typ podpisu se použije. |
| **Podepisovat** | Zapne nebo vypne podpis pro daný výstup aktuálního dodavatele. |
| **Výběr profilu** | Určuje, odkud se vezme podpisový profil. |
| **Profil** | Konkrétní profil dodavatele, pokud výstup používá strategii **Profil dodavatele**. |
| **Fallback uživatele** | Co se má stát, když je zvolen **Přihlášený uživatel**, ale uživatel nemá použitelný vlastní profil. |
| **Při chybě** | Co se má stát, když podpis selže nebo není nakonfigurovaný. |

### Výběr profilu

| Hodnota | Chování |
|---|---|
| **Profil dodavatele** | Použije se konkrétní aktivní profil dodavatele z pole **Profil**. Uživatelské profily se pro tento výstup nepoužijí. |
| **Přihlášený uživatel** | Aplikace použije výchozí profil přihlášeného uživatele pro daný výstup. Pokud ho nenajde, použije se **Fallback uživatele**. |

U automatických/background operací nemusí existovat přihlášený uživatel. Pokud
je výstup nastavený na **Přihlášený uživatel**, je proto důležité nastavit
rozumný fallback.

### Fallback uživatele

| Hodnota | Chování |
|---|---|
| **Profil dodavatele** | Pokud uživatel nemá vlastní profil, použije se profil dodavatele z řádku konfigurace. |
| **Vrátit nepodepsané** | Výstup pokračuje bez podpisu a událost se zapíše do logu. U PDF se vydá nepodepsané PDF, u e-mailu odejde nepodepsaná zpráva. |
| **Zastavit s chybou** | Export nebo odeslání selže s chybou. |

### Při chybě

| Hodnota | Chování |
|---|---|
| **Vrátit nepodepsané** (`fallback_unsigned`) | Při chybě podpisu výstup pokračuje bez podpisu a zapíše se auditní událost. Hodí se tam, kde je důležitější dostupnost dokladu nebo e-mailu než tvrdé vynucení podpisu. |
| **Zastavit s chybou** (`fail_closed`) | Při chybě podpisu export nebo odeslání selže. Hodí se tam, kde podpis musí být povinný. |
| **Přeskočit bez konfigurace** (`skip_when_unconfigured`) | Pokud chybí použitelný profil, podpis se přeskočí. |

Tlačítko **Otestovat** v řádku konfigurace vytvoří dočasné PDF a zkusí ho
podepsat podle stejného mapování. Zobrazuje se jen u PDF výstupů. Výsledek
zobrazí stav, použitý profil a vlastníka certifikátu (CN), pokud ho backend
zjistí.

S/MIME podpis otestuješ odesláním testovacího e-mailu pro příslušný typ zprávy
a ověřením podpisu v e-mailovém klientovi.

## 28.8 Mapování podpisových profilů uživatele

Sekce **Mapování podpisových profilů** slouží pro osobní výchozí profily
přihlášeného uživatele.

Použije se jen tehdy, když admin v **Konfiguraci podpisů** nastavil daný výstup
na **Přihlášený uživatel**. Pokud je výstup nastavený na **Profil dodavatele**,
uživatelský profil se ignoruje a UI na to upozorní.

Pro každý výstup vyber vlastní aktivní profil, který podporuje stejné použití
jako výstup. Pro PDF výstupy musí profil podporovat použití **PDF**, pro
e-mailové výstupy použití **S/MIME e-mail**.

## 28.9 Výběr podpisu na konkrétním dokladu

Na detailu faktury je pro uživatele s právem zápisu sekce
**Elektronický podpis dokumentu**. Umožňuje přepsat výchozí konfiguraci pro
konkrétní doklad.

| Hodnota | Chování |
|---|---|
| **Dědit** | Použije se globální konfigurace z **Konfigurace podpisů**. |
| **Přihlášený uživatel** | Pro tento doklad se použije výchozí podpisový profil uživatele, který PDF generuje nebo odesílá. |
| **Profil dodavatele** | Pro tento doklad se použije profil dodavatele. Admin může vybrat konkrétní profil, účetní nechává profil zdědit z konfigurace. |

Změna výběru u faktury invaliduje PDF cache, aby se další stažení nebo odeslání
vygenerovalo s aktuálním podpisem. U uživatelských profilů cache závisí na tom,
který uživatel PDF generuje, takže stejný doklad může být podepsaný jiným
profilem podle přihlášeného uživatele.

Per-dokladový výběr se týká PDF dokladů. Odchozí e-maily se řídí mapováním
e-mailových výstupů v **Konfiguraci podpisů**.

## 28.10 Podepisování odchozích e-mailů

S/MIME podpis se aplikuje při sestavení e-mailu těsně před odesláním přes SMTP.
Podepisuje se výsledná MIME zpráva včetně HTML/textového těla a příloh, takže
příjemce může v běžném e-mailovém klientovi ověřit, že zpráva nebyla cestou
změněna.

Podporované e-mailové výstupy:

| Výstup v UI | Interní šablona |
|---|---|
| E-mail s fakturou | `invoice_send` |
| E-mail s upomínkou | `invoice_reminder` |
| E-mail s připomínkou zálohy | `proforma_reminder` |
| E-mail s poděkováním za úhradu | `invoice_payment_thanks` |
| E-mail se schválením výkazu | `invoice_approval` |
| E-mail s připomínkou pravidelné faktury | `recurring_draft_reminder` |

Nastavení funguje stejně jako u PDF výstupů:

- admin v **Konfiguraci podpisů** zapne podpis pro konkrétní e-mailový výstup,
- zvolí **Profil dodavatele** nebo **Přihlášený uživatel**,
- při strategii **Přihlášený uživatel** si uživatel nastaví vlastní výchozí
  profil v **Mapování podpisových profilů**,
- při chybě se použije politika **Vrátit nepodepsané** nebo
  **Zastavit s chybou**.

S/MIME podpis e-mail nešifruje. Obsah zprávy zůstává čitelný stejně jako u
běžného e-mailu, jen je opatřen elektronickým podpisem.

## 28.11 Ověření podepsaného PDF

Po stažení můžeš PDF ověřit v běžné PDF čtečce nebo na serveru například přes
`pdfsig`:

```bash
pdfsig Faktura-2606009.pdf
```

U platného podpisu uvidíš stav podpisu jako validní. Pokud výstup hlásí, že
vydavatel certifikátu je neznámý, znamená to obvykle chybějící důvěryhodný
certifikační řetězec v prostředí ověřovatele. Samotný kryptografický podpis
může být přesto validní.

## 28.12 Audit a řešení problémů

Správa i použití podpisů se zapisuje do activity logu. Typické události:

| Událost | Význam |
|---|---|
| `signing.profile_created` / `signing.profile_updated` / `signing.profile_deleted` | Změna podpisového profilu. |
| `signing.credential_uploaded` / `signing.credential_deleted` / `signing.credential_passphrase_updated` | Změna certifikátu nebo politiky hesla. |
| `signing.output_settings_updated` | Změna konfigurace podpisů pro výstup. |
| `signing.user_default_updated` | Změna osobního mapování profilu. |
| `signing.document_selection_updated` | Změna výběru podpisu na konkrétním dokladu. |
| `signing.pdf_signed` | PDF bylo úspěšně podepsáno. |
| `signing.failed` | Podepisování selhalo. Podle politiky se buď vrátilo nepodepsané PDF, nebo operace skončila chybou. |
| `signing.skipped` | Podepisování bylo přeskočeno, například kvůli vypnutému výstupu nebo chybějící konfiguraci. |
| `signing.email_signed` | Odchozí e-mail byl úspěšně podepsán S/MIME. |
| `signing.email_failed` | S/MIME podpis e-mailu selhal. |
| `signing.email_skipped` | S/MIME podpis e-mailu byl přeskočen, například kvůli chybějící konfiguraci. |

Časté problémy:

| Problém | Co zkontrolovat |
|---|---|
| PDF se vygenerovalo bez podpisu | Zkontroluj **Konfiguraci podpisů**, aktivní profil, nahraný certifikát a politiku **Při chybě**. Při `fallback_unsigned` se nepodepsané PDF vydá záměrně. |
| Export skončil chybou `PDF podpis není nakonfigurovaný` | Výstup je nastavený na tvrdé selhání a chybí použitelný profil nebo certifikát. |
| Certifikát nejde nahrát | Ověř P12/PFX, heslo, privátní klíč a expiraci certifikátu. |
| Background job nepodepisuje uživatelským profilem | Background job nemá přihlášeného uživatele. Pro tyto scénáře použij fallback na profil dodavatele nebo passphrase file. |
| Po změně konfigurace se stále vrací staré PDF | Zkontroluj PDF historii a cache. Změna konfigurace podpisů faktury cache invaliduje, ale starší archivované verze zůstávají jako auditní záznam. |
| E-mail odešel bez S/MIME podpisu | Zkontroluj, že je zapnutý konkrétní e-mailový výstup, profil podporuje **S/MIME e-mail** a politika chyby není nastavená na tichý fallback. |
| E-mailový klient podpisu nevěří | Zkontroluj e-mail v certifikátu, důvěryhodnost certifikační autority a to, zda po podpisu zprávu neupravuje SMTP brána nebo antispam. |

## 28.13 Poznámka k REST API

Podpisové endpointy jsou interní administrační endpointy používané SPA
aplikací (`/api/settings/...` a `/api/documents/.../signature-selection`).
Nejsou součástí veřejného `/api/v1` subsetu a nejsou popsané v
`api/openapi.yaml`.

Veřejné endpointy pro stažení nebo odeslání PDF vrací dokument podle aktuální
konfigurace podpisů, ale samotná správa podpisových profilů zatím není veřejné
API pro externí integrace.
