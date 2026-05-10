# Changelog

All notable changes to MyInvoice.cz are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
