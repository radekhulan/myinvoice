# Migrate MyInvoice.cz Docker volumes z 3-volume layoutu (3.1.x a starsi)
# na novy jednovolume layout (3.2.0+).
#
# Pred 3.2.0 mel docker-compose tri named volumes:
#   - app-log     -> /var/www/html/log
#   - app-storage -> /var/www/html/storage
#   - app-private -> /var/www/html/private
#
# Od 3.2.0 je to jediny volume:
#   - app-data    -> /data   (drzi log/, storage/, private/, volitelne cfg.local.php)
#
# Bez migrace by `docker compose up -d` s 3.2.0 image pripojil PRAZDNY
# `app-data` a aplikace by nevidela existujici faktury/uploady/sessions/DKIM.
#
# Skript:
#   1. Detekuje docker compose project name (z dir jmena nebo COMPOSE_PROJECT_NAME).
#   2. Zastavi stack (`docker compose down` - DB volume zustane).
#   3. Detekuje existujici stare volumes.
#   4. Vytvori novy `app-data` volume (pokud neexistuje).
#   5. Spusti docasny alpine kontejner, ktery `cp -a` zkopiruje data.
#   6. Vypise prikaz pro smazani starych volumes (mazani nedela automaticky).
#
# Idempotent — opetovne spusteni detekuje, ze stara data uz jsou v novem volume,
# a jen vypise prikazy pro uklid. Bezpecne — stare volumes nikdy nemaze.
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) { Write-Error "'docker compose' (v2) plugin required" }

# Detect compose project name.
$Project = $env:COMPOSE_PROJECT_NAME
if (-not $Project) {
    $Project = (Split-Path -Leaf $ProjectRoot).ToLower() -replace '[^a-z0-9_-]', ''
}
$OldLog     = "${Project}_app-log"
$OldStorage = "${Project}_app-storage"
$OldPrivate = "${Project}_app-private"
$NewData    = "${Project}_app-data"

# Pick compose file
$ComposeArgs = @()
$prodRunning = & docker compose -f docker-compose.production.yml ps --format json app 2>$null | Select-String '"State":"running"' -Quiet
if ($prodRunning) {
    $ComposeArgs = @('-f', 'docker-compose.production.yml')
} elseif ((Test-Path docker-compose.production.yml) -and (-not (Test-Path docker-compose.yml))) {
    $ComposeArgs = @('-f', 'docker-compose.production.yml')
}

Write-Host "==> Compose project: $Project"
Write-Host "    Old volumes:  $OldLog, $OldStorage, $OldPrivate"
Write-Host "    New volume:   $NewData"
Write-Host ""

# --- 1. detect old volumes -----------------------------------------------
$existing = @()
foreach ($v in @($OldLog, $OldStorage, $OldPrivate)) {
    & docker volume inspect $v *>$null
    if ($LASTEXITCODE -eq 0) { $existing += $v }
}

if ($existing.Count -eq 0) {
    Write-Host "==> Zadny ze starych volumes neexistuje - patrne uz jsi migroval, nebo"
    Write-Host "    je to fresh instalace. Nic k delani."
    exit 0
}

Write-Host "==> Nalezeno $($existing.Count) starych volumes k migraci:"
foreach ($v in $existing) { Write-Host "    - $v" }
Write-Host ""

# --- 2. stop stack -------------------------------------------------------
Write-Host "==> Zastavuji stack (DB volume zustane nedotcen)..."
& docker compose @ComposeArgs down 2>&1 | Out-Host
Write-Host ""

# --- 3. ensure new volume exists -----------------------------------------
& docker volume inspect $NewData *>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "==> Vytvarim novy volume: $NewData"
    & docker volume create $NewData | Out-Null
}

# --- 4. copy data via sidecar alpine container ---------------------------
Write-Host "==> Kopiruji data pres docasny alpine kontejner..."
$mounts = @('-v', "${NewData}:/new")
$copyParts = @()
foreach ($v in $existing) {
    switch ($v) {
        $OldLog     { $mounts += @('-v', "${v}:/old/log:ro");     $copyParts += "mkdir -p /new/log && cp -a /old/log/. /new/log/" }
        $OldStorage { $mounts += @('-v', "${v}:/old/storage:ro"); $copyParts += "mkdir -p /new/storage && cp -a /old/storage/. /new/storage/" }
        $OldPrivate { $mounts += @('-v', "${v}:/old/private:ro"); $copyParts += "mkdir -p /new/private && cp -a /old/private/. /new/private/" }
    }
}
$copyCmd = ($copyParts -join ' && ') + ' && chown -R 33:33 /new && echo OK'

& docker run --rm @mounts alpine sh -c $copyCmd
if ($LASTEXITCODE -ne 0) { Write-Error "Kopirovani selhalo (alpine sidecar exit $LASTEXITCODE)" }
Write-Host "    Hotovo."
Write-Host ""

# --- 5. report -----------------------------------------------------------
Write-Host "============================================================"
Write-Host " Migrace volumes dokoncena."
Write-Host ""
Write-Host " Dalsi kroky:"
Write-Host "   1. Nastartuj stack:    docker compose $($ComposeArgs -join ' ') up -d"
Write-Host "   2. Over, ze aplikace vidi faktury / uploady / sessions."
Write-Host "   3. Po overeni smaz stare volumes (NEVRATNE):"
foreach ($v in $existing) {
    Write-Host "        docker volume rm $v"
}
Write-Host ""
Write-Host " (Skript NEMAZAL stare volumes automaticky - rucne po overeni.)"
Write-Host "============================================================"
