# 19. AI extrakce přijatých faktur

[Přijaté faktury](17_Prijate_faktury.md) lze importovat z PDF pomocí AI extrakce
(Anthropic Claude). Tato kapitola popisuje **kontrolu výsledků** extrakce a
automatiky, které doklad daňově připraví.

Při AI extrakci z PDF se po importu automaticky spustí **sanity check**: sečtou
se řádky bez DPH a porovnají s celkovým základem daně, který AI přečetla z PDF
„K úhradě". Pokud se hodnoty liší o víc než 2 %, faktura získá flag **„Ke
kontrole"** a uživatel by měl řádky před zaúčtováním ověřit.

### Indikátory v UI

- **Žluté zvýraznění řádku** + ikona ⚠ vedle čísla faktury v seznamu přijatých
  faktur (`/purchase-invoices`).
- **Filtr „Ke kontrole"** v topbaru seznamu — zobrazí jen faktury, kde je flag
  aktivní.
- **Žlutý warning banner** v detailu i editoru faktury s diagnostickým textem
  (např. *„součet řádků bez DPH (XX) je vyšší než AI-vrácený základ daně bez
  DPH (YY) — rozdíl Z %"*).

### Jak zrušit warning

- Tlačítko **Beru na vědomí** v banneru — pošle POST
  `/api/purchase-invoices/{id}/dismiss-extraction-warning` a flag se smaže.
- **Automaticky** při přechodu z draftu na další stav (received / booked /
  paid) — uživatel posunul stav = ověřil data.

### Auto-upgrade modelu

Pokud levnější model (Haiku 4.5) vrátí slabý výsledek (vendor se shoduje s
tenantem nebo součet řádků se výrazně liší od totalu), extractor automaticky
zkusí znovu se silnějším modelem (Sonnet 4.6, ~4× dráž za extract). Pokud máš
Sonnet/Opus jako default, retry se přeskočí.

### Katastrofální mismatch — placeholder

Když ani silnější model nezvládne rozparsovat řádky (typicky komplexní
multi-column servisní faktury) a součet řádků se liší od totalu o víc než
50 %, extractor:

1. Zachová **popisy řádků** z AI extraktu (jsou obvykle správně)
2. Vynuluje jejich **qty a unit_price** (0)
3. Přidá první řádek **KOREKCE** s AI totalem z „K úhradě", aby seděl celkový
   součet faktury

Uživatel pak postupně doplní qty/cenu k jednotlivým řádkům a nakonec smaže
korekční řádek.

### Backfill existujících faktur

CLI skript `php api/bin/recheck-ai-extracted-invoices.php` projde přijaté
faktury s PDF přílohou, re-spustí AI extrakci a porovná AI total s aktuálním
DB totalem. Při rozdílu nad práh (default 2 %) zapíše varování:

```
php api/bin/recheck-ai-extracted-invoices.php                    # dry-run
php api/bin/recheck-ai-extracted-invoices.php --apply            # zápis
php api/bin/recheck-ai-extracted-invoices.php --supplier-id=1
php api/bin/recheck-ai-extracted-invoices.php --threshold=0.05
```

### Dodavatel neplátce DPH

Při AI importu se ověří **plátcovství dodavatele** (ARES/VIES, případně signál
z dokladu „DIČ: Neplátce DPH"). U neplátce se automaticky nastaví **Bez nároku
na odpočet**, vynulují sazby a doplní varování — aby se neoprávněný odpočet
nedostal do přiznání. Detail viz [§ 17.2.4](17_Prijate_faktury.md#1724-danova-uznatelnost-a-narok-na-odpocet).

### Reverse charge ze zahraničí — automatika

Když extraktor detekuje **reverse charge** (zahraniční dodavatel + všechny řádky
bez DPH), doklad automaticky daňově připraví:

- AI klasifikuje **povahu plnění** (zboží / služba) přímo z dokladu (VIN a vozidlo
  → zboží; SaaS, licence, API → služba).
- Položky dostanou **tuzemskou sazbu 21 %** a klasifikační kód: **23** (zboží
  z EU → ř. 3 + ř. 43, KH A.2), **24** (služba), **25** (zboží ze 3. země).
  Částka k úhradě se nemění — daň zůstává na dokladu nulová, samovyměří se až
  ve výkazech.
- O tom, zda jde o **EU, nebo 3. zemi**, rozhoduje přednostně **prefix DIČ**
  dodavatele, teprve potom adresa sídla: firma s mimoevropskou adresou fakturující
  přes EU registraci k DPH (typicky Anthropic či GitHub s irským `IE…` DIČ) se
  správně klasifikuje jako plnění z EU (služba → **24e**, ř. 5). Non-Union OSS
  prefix `EU…` členským státem není a zůstává 3. zemí.
- U **pořízení zboží z EU** se dopočítá zákonné **DUZP dle § 25** (15. den
  měsíce po dodání, pokud doklad nebyl vystaven dříve) a k němu se naváže
  **kurz ČNB** — pozdě vystavená faktura tak spadne do správného DPH období.
- Do dokladu se zapíše **informační varování** s rekapitulací, co se nastavilo
  — zkontroluj hlavně zboží vs. služba a případně změň kód (23 ↔ 24).

Detail daňové logiky viz [§ 17.2.6](17_Prijate_faktury.md#1726-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba).
