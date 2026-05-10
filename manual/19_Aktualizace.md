# 19. Aktualizace na novou verzi

MyInvoice.cz denně kontroluje GitHub Releases API a v Systém → **Aktualizace**
(jen admin) zobrazí aktuální i poslední dostupnou verzi spolu s release
notes. Aplikaci se updatuje buď z UI (jedním tlačítkem), nebo ručně přes
shell — záleží na typu instalace.

## 19.1 Co všechno se aktualizuje

Aktualizace zahrnuje všechny tři vrstvy aplikace:

- **Backend (PHP)** — `api/vendor/` se přebuilduje, schéma DB se případně
  migruje (`php api/bin/migrate.php`).
- **Frontend (Vue)** — `web/dist/` (Vite produkční build).
- **Manuál** — `manual/generated/*.html` + `manual/manual.pdf`.

Zachovají se: `cfg.php`, `cfg.local.php`, `private/`, `storage/`, `log/` —
tj. všechno, co obsahuje konfiguraci a uživatelská data. Migrace nikdy
nepřepisují existující data, jen přidávají sloupce/tabulky/indexy.

## 19.2 Daily check — jak to funguje

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

## 19.3 Footer aplikace + badge nové verze

V patičce každé stránky vidíš `vX.Y.Z` — to je verze, která teď běží.
Pokud je k dispozici nová verze a jsi přihlášený jako admin, badge
**`v2.5.0`** vedle ní je klikatelný odkaz na **Systém → Aktualizace**.

Neadminové vidí jen verzi bez badge (badge je čistě admin signál — běžný
uživatel s upgradem stejně nic neudělá).

## 19.4 Aktualizace v UI — Docker

V **Systém → Aktualizace** klikni na **„Aktualizovat na vX.Y.Z"**.
Aplikace zapíše flag soubor `storage/upgrade-requested.json` **uvnitř
kontejneru** (Docker named volume `app-data:/data`, fyzicky pak v
`/data/storage/upgrade-requested.json`) a UI začne pollovat. **Vlastní
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
# Windows
cd C:\inetpub\myinvoice
powershell -NoProfile -ExecutionPolicy Bypass -File cmd\docker-update-watcher.ps1
```

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
schtasks /create /tn "MyInvoice Update Watcher" `
  /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myinvoice\cmd\docker-update-watcher.ps1" `
  /sc onstart /ru SYSTEM /rl HIGHEST

# Spusť hned (ne až po restartu)
schtasks /run /tn "MyInvoice Update Watcher"
```

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

UI sice flag soubor zapíše, ale nikdo ho nezpracuje (UI zůstane věčně
ve stavu „Upgrade probíhá…"). Spusť na hostu ručně:

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

## 19.5 Upgrade na 3.2.0 — migrace Docker volumes

**Toto se týká jen Docker uživatelů přecházejících z 3.1.x (a starších) na
3.2.0+.** Native (nedockerový) deployment migraci nepotřebuje.

Od 3.2.0 image používá jediný persistent volume `app-data:/data` místo tří
samostatných (`app-log`, `app-storage`, `app-private`). Pokud uděláš
`docker compose pull && up -d` s novou image bez migrace, **Docker připojí
prázdný `app-data`** a aplikace nebude vidět existující faktury, uploady,
sessions ani DKIM klíče. Stará data v původních volumes nejsou ztracená,
jen nejsou připojena.

### Postup migrace

```bash
# Linux / macOS
cd /opt/myinvoice
git pull --ff-only                          # nebo `curl -O` aktuální docker-compose.yml a docker-migrate-volumes.sh
bash cmd/docker-migrate-volumes.sh
docker compose up -d
```

```powershell
# Windows
cd C:\inetpub\myinvoice
git pull --ff-only
.\cmd\docker-migrate-volumes.ps1
docker compose up -d
```

Skript:

1. Detekuje compose project name a existující staré volumes
   (`<project>_app-log`, `<project>_app-storage`, `<project>_app-private`).
2. Zastaví stack přes `docker compose down` (DB volume `db-data` zůstane
   nedotčen).
3. Vytvoří nový `<project>_app-data` volume (pokud ještě neexistuje).
4. Spustí dočasný `alpine` sidecar s mountem starých i nového volume a
   přes `cp -a` zkopíruje obsah — výsledek je `/data/log`, `/data/storage`,
   `/data/private` v novém volume.
5. Sjednotí ownership na `www-data` (UID/GID 33).
6. **Staré volumes nemaže** — vypíše ti `docker volume rm` příkazy, abys je
   spustil ručně po ověření, že nová instalace funguje.

Po `docker compose up -d` se přihlas, ověř, že vidíš historii faktur a
upload souborů, a pak pusť výpis `docker volume rm …` ze závěru migrace
pro úklid starých volumes.

### Idempotence + recovery

Skript je idempotentní — opětovné spuštění detekuje, že staré volumes
už neexistují (nebo že nový volume už obsahuje data) a jen vypíše stav.

Pokud něco selže před `docker volume rm`, **stará data jsou pořád celá**
v `<project>_app-log/storage/private` — ručně je restoreneš přes:

```bash
docker run --rm -v myinvoice_app-storage:/old:ro -v myinvoice_app-data:/new alpine \
  sh -c "cp -a /old/. /new/storage/"
```

## 19.6 Aktualizace v UI — nativní instalace

Pro nativní deployment (sdílený hosting / VPS bez Dockeru) UI v této
verzi (v3.0.0) zatím **neimplementuje** automatický download release
tarballu — pouze ti ukáže copy-paste příkazy:

```bash
git fetch --tags
git checkout vX.Y.Z
cd api && composer install --no-dev && cd ..
cd web && pnpm install && pnpm build && cd ..
php tools/generateManualHtml.php
php tools/exportManualToPdf.php
php api/bin/migrate.php
```

Vyžaduje na hostu **PHP CLI + Composer + Node + pnpm**. Pokud Composer/
Node nemáš (typicky sdílený hosting), je nejjednodušší cesta:

1. Stáhni **production bundle** z release page:
   `https://github.com/radekhulan/myinvoice/releases/tag/vX.Y.Z` →
   asset `myinvoice-X.Y.Z.tar.gz`. Tarball má všechno potřebné už
   vyrobené (vendor, web/dist, manual). SHA-256 checksum je v
   `myinvoice-X.Y.Z.tar.gz.sha256`.
2. Rozbal přes web rozhraní hostingu nebo SSH:
   ```bash
   tar -xzf myinvoice-X.Y.Z.tar.gz --strip-components=1 \
     --exclude='cfg.php' --exclude='cfg.local.php' \
     --exclude='storage' --exclude='private' --exclude='log'
   ```
3. Spusť migraci přes hosting cron / SSH:
   `php api/bin/migrate.php`

> 🛈 Phase 2 (plánováno na příští minor release) doplní automatický
> download bundle + extrakci přímo z UI tlačítka, takže krok 1+2 odpadne.

## 19.7 Co když upgrade selže

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

Když selže `composer install` nebo `pnpm build`, soubory v `api/vendor/`
nebo `web/dist/` mohou být v inkonzistentním stavu. Recovery:

```bash
git checkout vPREDCHOZI-VERZE
cd api && composer install --no-dev && cd ..
cd web && pnpm install && pnpm build && cd ..
```

Pokud `migrate.php` selhal, vrátit se nejde — musíš debugovat konkrétní
migraci. Záloha DB je tvoje odpovědnost (kapitola **§ 14 Exporty**).

## 19.8 Dohled na nové verze bez UI

Pokud nemáš administrátorský přístup do UI, ale chceš vědět, kdy je
nová verze, můžeš pollovat veřejný endpoint:

```bash
curl -s https://myinvoice.tvuj-server.cz/api/version | jq
```

Vrátí `{ "current": "3.0.0", "latest": "3.1.0", "has_update": true,
"release_url": "https://github.com/.../v3.1.0" }`. Tohle je veřejný
endpoint bez auth, ale stejná data vidí kdokoliv s přístupem k aplikaci
ve footru.
