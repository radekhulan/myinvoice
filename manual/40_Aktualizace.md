# 40. Aktualizace na novou verzi

MyInvoice.cz denně kontroluje GitHub Releases API a v Systém → **Aktualizace**
(jen admin) zobrazí aktuální i poslední dostupnou verzi spolu s release
notes. Aplikaci se updatuje buď z UI (jedním tlačítkem), nebo ručně přes
shell — záleží na typu instalace.

## 40.1 Co všechno se aktualizuje

Aktualizace zahrnuje všechny tři vrstvy aplikace:

- **Backend (PHP)** — `api/vendor/` se přebuilduje (nebo přijde
  představěné v production bundlu), schéma DB se případně migruje
  (`php api/bin/migrate.php`).
- **Frontend (Vue)** — `web/dist/` (Vite produkční build).
- **Manuál** — `manual/generated/*.html` + `manual/manual.pdf`.

Zachovají se: `cfg.php`, `cfg.local.php`, `private/`, `storage/`, `log/` —
tj. všechno, co obsahuje konfiguraci a uživatelská data. Migrace nikdy
nepřepisují existující data, jen přidávají sloupce/tabulky/indexy.

## 40.2 Daily check — jak to funguje

Cron skript `api/bin/cron-version-check.php` se spouští 1× denně, volá
GitHub API a cachuje výsledek do tabulky `app_meta` (klíče
`latest_version`, `latest_release_notes`, `latest_release_url`,
`latest_published_at`, `last_check_at`). UI / footer čte z cache, žádný
blocking síťový call při každém načtení stránky.

### Plánování cronu

| Prostředí | Příklad |
|-----------|---------|
| Linux/cron | `0 6 * * * cd /opt/myinvoice && php api/bin/cron-version-check.php` |
| Docker (host cron) | `0 6 * * * docker compose -f /opt/myinvoice/docker-compose.production.yml exec -T app php api/bin/cron-version-check.php` |
| Windows Scheduler | Daily, akce: `php.exe C:\inetpub\myinvoice\api\bin\cron-version-check.php` |

Pokud cron nenastavíš, kontrola se nikdy nespustí — admin musí kliknout
**„Zkontrolovat teď"** v UI.

## 40.3 Footer aplikace + badge nové verze

V patičce každé stránky vidíš `vX.Y.Z` — to je verze, která teď běží.
Pokud je k dispozici nová verze a jsi přihlášený jako admin, badge
**`v2.5.0`** vedle ní je klikatelný odkaz na **Systém → Aktualizace**.

Neadminové vidí jen verzi bez badge (badge je čistě admin signál — běžný
uživatel s upgradem stejně nic neudělá).

## 40.4 Aktualizace v UI — Docker

V **Systém → Aktualizace** klikni na **„Aktualizovat na vX.Y.Z"**.
Aplikace zapíše flag soubor `upgrade-requested.json` **uvnitř kontejneru**
do `${MYINVOICE_DATA_DIR}/storage/` (default `/data/storage/` od 3.6.0;
ve starších 3-volume instalacích `/var/www/html/storage/`) a UI začne pollovat.
**Vlastní
upgrade ale provádí host-side watcher** — proces běžící mimo container,
který má přístup k `docker compose` na hostu a přes `docker compose exec`
čte/píše do storage volume.

### Test režim (jednorázově, ve foregroundu)

Než nainstaluješ watcher jako daemon, otestuj ho ručně v PowerShell /
bash okně:

```bash
# Linux / macOS
cd /opt/myinvoice
bash cmd/docker-update-watcher.sh
```

```powershell
# Windows — spusť tím PowerShellem, který máš (uprav cd na SVOU instalační cestu)
cd C:\inetpub\myinvoice
pwsh -NoProfile -ExecutionPolicy Bypass -File cmd\docker-update-watcher.ps1
# nemáš-li PowerShell 7, použij místo `pwsh` příkaz `powershell` (Windows PS 5.1)
```

> 🛈 Watcher si vlastní update spouští **tímtéž** PowerShell hostem, pod kterým
> běží (`pwsh` i `powershell`), a cesty řeší z umístění skriptu — funguje tedy
> i z jiného adresáře než `C:\inetpub\myinvoice` a na strojích, kde je jen
> PowerShell 7 (`pwsh`).

Vidíš `[watcher] start, polling storage/upgrade-requested.json inside
container every 30s` — watcher poslouchá. Klikni v UI **„Aktualizovat"**
a do 30 s zachytí flag, spustí `docker-update.{sh,ps1}`, výsledek napíše
do kontejneru. Watcher zastav `Ctrl+C`.

### Instalace watcheru jako daemon (na produkci)

#### Linux — systemd unit

```bash
sudo tee /etc/systemd/system/myinvoice-update-watcher.service <<'EOF'
[Unit]
Description=MyInvoice update watcher
After=docker.service

[Service]
Type=simple
WorkingDirectory=/opt/myinvoice
ExecStart=/opt/myinvoice/cmd/docker-update-watcher.sh
Restart=always
User=root

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now myinvoice-update-watcher
```

Logy: `journalctl -u myinvoice-update-watcher -f`.

#### Windows — Scheduled Task

```powershell
# Uprav cestu k SVÉ instalaci. Máš-li jen Windows PowerShell 5.1, nahraď
# `pwsh.exe` za `powershell.exe`.
schtasks /create /tn "MyInvoice Update Watcher" `
  /tr "pwsh.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myinvoice\cmd\docker-update-watcher.ps1" `
  /sc onstart /ru SYSTEM /rl HIGHEST

# Spusť hned (ne až po restartu)
schtasks /run /tn "MyInvoice Update Watcher"
```

> 🛈 `pwsh.exe` musí být v PATH (PowerShell 7 instalátor ji tam dává). Pokud
> Scheduled Task hlásí, že příkaz nenašel, zadej plnou cestu
> `C:\Program Files\PowerShell\7\pwsh.exe`, nebo použij `powershell.exe` (PS 5.1).

Stav úlohy: `schtasks /query /tn "MyInvoice Update Watcher" /v /fo list`.
Stop: `schtasks /end /tn "MyInvoice Update Watcher"`.

### Co watcher dělá

1. Každých 30 s: `docker compose exec -T app test -f storage/upgrade-requested.json`.
2. Když ho najde → přečte `target_version` přes `cat`, přejmenuje na
   `upgrade-inflight.json` přes `mv` uvnitř kontejneru (zámek proti
   double-triggeru).
3. Spustí na hostu `cmd/docker-update.{sh,ps1}` — ten dělá:
   - `docker compose pull` (registry mode) nebo `git pull && build` (source mode)
   - `docker compose up -d` (restart stacku)
   - `php api/bin/migrate.php` (pending migrace)
4. Po restartu kontejneru počká až 60 s, než bude zase responzivní
   (`docker compose exec true`), pak zapíše výsledek (success / fail)
   přes `cat > storage/upgrade-result.json` zpět do kontejneru.
5. Plný log běhu na host: `/tmp/myinvoice-upgrade-YYYYMMDDTHHMMSSZ.log`
   (Linux) nebo `%TEMP%\myinvoice-upgrade-...log` (Windows).
6. UI v **Systém → Aktualizace** každých 5 s pollne `/api/admin/update/
   status`, který načte `upgrade-result.json` z kontejneru a zobrazí
   „Upgrade úspěšně dokončen" nebo „Upgrade selhal" s message.

### Pokud watcher neběží

UI sice flag soubor zapíše, ale nikdo ho nezpracuje. Stav „Upgrade
probíhá…" po 15 minutách sám vyprší a stránka ukáže „Upgrade nepotvrzen";
jakmile je nasazená cílová verze (nebo novější), hláška zmizí. Spusť na
hostu ručně:

```bash
# Linux / macOS
cd /opt/myinvoice
bash cmd/docker-update.sh
docker compose -f docker-compose.production.yml exec app rm -f storage/upgrade-requested.json
```

```powershell
# Windows
cd C:\inetpub\myinvoice
.\cmd\docker-update.ps1
docker compose -f docker-compose.production.yml exec app rm -f storage/upgrade-requested.json
```

(Pokud nepoužíváš production compose, vynechej `-f docker-compose.production.yml`.)

### Nasazení řízené platformou (Coolify, Portainer, Kubernetes…)

Když novou verzi nasazuje platforma — změnou tagu image a redeployem —
host-side watcher nemá kde běžet a tlačítko **„Aktualizovat"** by jen
zapsalo flag, který nikdo nezpracuje. Spouštění upgradu z aplikace proto
vypni:

```php
// cfg.local.php
'updates' => [
    'in_app_upgrade' => false,
],
```

nebo ENV proměnnou `MYINVOICE_IN_APP_UPGRADE=false` (u ENV-only nasazení
nejjednodušší cesta). Stránka **Systém → Aktualizace** pak dál hlásí novou
verzi a release notes, ale tlačítko „Aktualizovat" nenabízí a místo návodu
k watcheru připomene, že nasazení řídí platforma. Migrace databáze spustí
entrypoint kontejneru sám při startu nové verze.

## 40.5 Migrace na single-volume layout (3.5.x → 3.6.0)

> ⚠️ **Tohle je breaking změna pro existující Docker instalace 3.5.x a starší.**
> Default Compose layout se mění ze 3-volume (`app-log` + `app-storage` + `app-private`)
> na **single-volume** (`app-data:/data` + `MYINVOICE_DATA_DIR=/data`). Migrace
> proběhne **automaticky** při běžném `docker-update.{sh,ps1}` — nemusíš dělat
> nic navíc.

**Proč ta změna:** v 3-volume layoutu byl soubor `cfg.local.php` (per-instance
overrides z setup wizardu — `app.url`, MFA politika) v ephemeral container
filesystému a `docker-update.sh` ho při recreate kontejneru smazal. Důsledek
(reportovaný v [issue #23](https://github.com/radekhulan/myinvoice/issues/23)):
po updatu `Origin` mismatch a všechny mutace v UI dostaly 403. Single-volume
layout drží `cfg.local.php` v perzistentním `/data` volumu, takže image
updaty jsou bezpečné.

### Co dělá `docker-update.{sh,ps1}` na 3.6.0

1. `git pull` (source mode) nebo `docker compose pull` (registry mode).
2. **Detekuje** existující 3-volume volumes (`<project>_app-log`, `_app-storage`,
   `_app-private`) a absenci nového `<project>_app-data` → vypíše prominentní
   banner a automaticky spustí `cmd/docker-migrate-volumes.{sh,ps1}`.
3. Migrace:
   - `docker cp` snapshotne `cfg.local.php` z běžícího 3.5.x kontejneru,
   - `docker compose down` (DB volume `db-data` zůstává),
   - alpine sidecar `cp -a` přepíše `log/`, `storage/`, `private/` ze 3 starých
     volumes do nového `app-data:/data`,
   - obnoví `cfg.local.php` v `/data/cfg.local.php` (přežijí `app.url` a
   nastavení MFA),
   - `docker compose up -d` na novém layoutu.
4. **Staré volumes nemaže** — vypíše `docker volume rm` příkazy. Smaž je
   až po ověření, že nová instalace vidí faktury / uploady / sessions.

### Ruční migrace (pokud nepoužíváš docker-update)

```bash
# Linux / macOS
cd /opt/myinvoice
git pull --ff-only                # přinese nový docker-compose.yml (single-volume)
bash cmd/docker-migrate-volumes.sh  # snapshotne cfg.local.php, zkopíruje data, up -d
```

```powershell
# Windows
cd C:\inetpub\myinvoice
git pull --ff-only
.\cmd\docker-migrate-volumes.ps1
```

Pro registry mode (jen `docker-compose.production.yml`, bez `.git`) si stáhni
nové compose soubory:

```bash
curl -O https://raw.githubusercontent.com/radekhulan/myinvoice/master/docker-compose.production.yml
curl -O https://raw.githubusercontent.com/radekhulan/myinvoice/master/cmd/docker-migrate-volumes.sh
chmod +x docker-migrate-volumes.sh
./docker-migrate-volumes.sh
```

### Idempotence + recovery

Skript je idempotentní — opětovné spuštění detekuje, že staré volumes
už neexistují (nebo že nový volume už obsahuje data) a jen vypíše stav.

Pokud něco selže před `docker volume rm`, **stará data jsou pořád celá**
v `<project>_app-log/storage/private` — ručně je restoreneš přes:

```bash
docker run --rm -v myinvoice_app-storage:/old:ro -v myinvoice_app-data:/new alpine \
  sh -c "cp -a /old/. /new/storage/"
```

## 40.6 Aktualizace v UI — nativní instalace

Nativní deployment (sdílený hosting / VPS bez Dockeru) se aktualizuje
z UI stejně jako Docker — jedním tlačítkem. V **Systém → Aktualizace**
klikni na **„Aktualizovat na vX.Y.Z"**.

Aplikace stáhne **production bundle** z GitHub release
(`myinvoice-X.Y.Z.tar.gz`), ověří jeho SHA-256, nasadí ho přes instalaci
a spustí migrace. **Composer, Node ani pnpm na hostu potřeba nejsou** —
bundle má `api/vendor/`, `web/dist/`, `manual/generated/` i
`manual/manual.pdf` už představěné.

### Co se děje na pozadí

Vlastní práci dělá detached CLI worker `api/bin/native-update.php`
(z UI se spouští automaticky, ručně jde zavolat taky):

```bash
php api/bin/native-update.php --target=X.Y.Z
php api/bin/native-update.php --target=X.Y.Z --preflight   # jen kontrola
```

| Krok | Co dělá |
|------|---------|
| `preflight` | Práva na zápis, volné místo (min. 512 MB), PHP CLI, zlib, možnost přepsat existující soubor |
| `download` | Stažení assetu z release (jen HTTPS, jen hosty GitHubu) |
| `verify` | SHA-256 proti assetu `.sha256`; při neshodě se bundle smaže a končí se |
| `extract` | Rozbalení do `storage/updates/<verze>/stage/` + validace všech cest v archivu |
| `backup` | Kopie souborů, které se budou přepisovat, do `storage/updates/<verze>/backup/` |
| `swap` | Nakopírování bundlu přes instalaci (nic se nemaže) |
| `migrate` | `php api/bin/migrate.php` už novým kódem |
| `finish` | Až teď se přepíše `VERSION`, uklidí se staging a starší zálohy |

Průběh se zapisuje do `storage/upgrade-requested.json` (krok +
heartbeat), výsledek do `storage/upgrade-result.json` a plný log do
`storage/upgrade-<timestamp>.log`. UI všechny tři čte a ukazuje krok
za krokem.

### Co zůstane nedotčené

`cfg.php`, `cfg.local.php`, `cfg.docker.php`, `.env`, `storage/`,
`private/`, `log/`, `tmp/` a `.git/` se nikdy nepřepisují — bundle je
ani neobsahuje a swap je navíc přeskakuje. Soubory, které v novém
bundlu nejsou, se **nemažou** (stejné chování jako ruční `tar -xzf`).

`VERSION` se úmyslně přepisuje jako poslední krok: dokud migrace
neproběhnou, instalace se hlásí starou verzí, takže přerušená
aktualizace nevypadá jako dokončená.

> ⚠️ Aktualizace přepisuje soubory běžící aplikace — requesty
> odbavované přesně v ten moment mohou selhat. Na produkci ji spouštěj
> v klidném okně a **měj aktuální zálohu databáze**; migrace samotné
> se vracet nedají.

> 🛈 Pokud máš `opcache.validate_timestamps=0`, restartuj po aktualizaci
> php-fpm / IIS application pool — jinak poběží stará bytecode cache.
> Preflight na to upozorní sám.

### Bezpečnostní model

Bundle se stahuje po HTTPS jen z hostů GitHubu a kontroluje se jeho
SHA-256. Checksum ale leží ve stejném releasu jako tarball, takže
chrání proti **poškozenému přenosu, ne proti kompromitovanému
repozitáři** — trust root je GitHub účet projektu. Aktualizaci smí
spustit jen administrátor.

### Když automatická cesta nejde

Sdílený hosting často zakazuje spouštění procesů nebo nemá práva na
zápis do rootu. Preflight to pozná dopředu, vypíše konkrétní důvody
a UI nabídne ruční postup se stejným bundlem:

```bash
curl -LO https://github.com/radekhulan/myinvoice/releases/download/vX.Y.Z/myinvoice-X.Y.Z.tar.gz
curl -LO https://github.com/radekhulan/myinvoice/releases/download/vX.Y.Z/myinvoice-X.Y.Z.tar.gz.sha256
sha256sum -c myinvoice-X.Y.Z.tar.gz.sha256
tar -xzf myinvoice-X.Y.Z.tar.gz --strip-components=1 \
  --exclude='cfg.php' --exclude='cfg.local.php' --exclude='cfg.docker.php' \
  --exclude='storage' --exclude='private' --exclude='log'
php api/bin/migrate.php
```

Ve vývoji (instalace je git checkout) preferuj `git checkout vX.Y.Z` —
bundle by ti jinak zašpinil pracovní kopii. Preflight na to upozorní.

## 40.7 Co když upgrade selže

### Docker watcher

Watcher zapíše `storage/upgrade-result.json` se `status: "failed"` a
plným logem do `storage/upgrade-YYYYMMDDTHHMMSSZ.log`. UI ho zobrazí.
Typické příčiny:

- **Image pull selhal** — síť, GHCR rate limit, neplatný tag → spusť
  `docker compose pull` ručně, viz log.
- **Migrace selhala** — schéma kolize, missing column → vraťto na
  předchozí tag (`docker compose pull image:OLD-VERSION && up -d`),
  pak řeš migrace.
- **Stack se nezastavuje** — running queries blokují. Restartuj přes
  `docker compose restart app`.

Container s aplikací se restartoval, ale data v DB volume zůstávají
nedotčena.

### Nativní

Worker zapíše `storage/upgrade-result.json` se `status: "failed"`,
důvodem a cestou k logu `storage/upgrade-<timestamp>.log`. UI to
zobrazí včetně cesty k záloze. Podle kroku, na kterém to spadlo:

- **`download` / `verify` / `extract`** — instalace se vůbec nezměnila,
  nic řešit netřeba. Neshoda SHA-256 znamená poškozené stažení; zkus to
  znovu, případně stáhni bundle ručně.
- **`swap`** — worker sám spustí **rollback** ze zálohy
  `storage/updates/<verze>/backup/` a do logu napíše, kolik souborů
  vrátil. Když rollback část souborů nevrátil (zamčené soubory), obnov
  je odtud ručně:
  ```bash
  cp -r storage/updates/X.Y.Z/backup/. .
  ```
- **`migrate`** — kód je už nasazený, schéma ne. Rollback se
  **záměrně nespouští** (vracet kód pod rozjeté schéma škodí víc).
  Projdi log a dokonči `php api/bin/migrate.php` ručně.

Pokud `migrate.php` selhal, vrátit migraci samotnou nejde — musíš
debugovat konkrétní migraci. Záloha DB je tvoje odpovědnost (kapitola
**§ 16 Exporty**).

Když se worker přestane hlásit (spadl proces), UI po 15 minutách bez
heartbeatu příznak „probíhá" samo zruší a napíše, kde hledat log.

## 40.8 Dohled na nové verze bez UI

Pokud nemáš administrátorský přístup do UI, ale chceš vědět, kdy je
nová verze, můžeš pollovat veřejný endpoint:

```bash
curl -s https://myinvoice.tvuj-server.cz/api/version | jq
```

Vrátí `{ "current": "3.0.0", "latest": "3.1.0", "has_update": true,
"release_url": "https://github.com/.../v3.1.0" }`. Tohle je veřejný
endpoint bez auth, ale stejná data vidí kdokoliv s přístupem k aplikaci
ve footru.
