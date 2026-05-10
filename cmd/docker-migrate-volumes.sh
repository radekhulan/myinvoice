#!/usr/bin/env bash
# Migrate MyInvoice.cz Docker volumes z 3-volume layoutu (3.1.x a starší)
# na nový jednovolume layout (3.2.0+).
#
# Před 3.2.0 měl docker-compose tři named volumes:
#   - app-log     -> /var/www/html/log
#   - app-storage -> /var/www/html/storage
#   - app-private -> /var/www/html/private
#
# Od 3.2.0 je to jediný volume:
#   - app-data    -> /data   (drží log/, storage/, private/, volitelně cfg.local.php)
#
# Bez migrace by `docker compose up -d` s 3.2.0 image připojil PRÁZDNÝ
# `app-data` a aplikace by neviděla existující faktury/uploady/sessions/DKIM.
#
# Skript:
#   1. Detekuje docker compose project name (z dir jména nebo COMPOSE_PROJECT_NAME).
#   2. Zastaví stack (`docker compose down` — DB volume zůstane).
#   3. Detekuje existující staré volumes.
#   4. Vytvoří nový `app-data` volume (pokud neexistuje).
#   5. Spustí dočasný alpine kontejner, který `cp -a` zkopíruje data.
#   6. Vypíše příkaz pro smazání starých volumes (mazání nedělá automaticky).
#
# Idempotent — opětovné spuštění detekuje, že stará data už jsou v novém volume,
# a jen vypíše příkazy pro úklid. Bezpečné — staré volumes nikdy nemaže.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi

# Detect compose project name (prefix used for named volumes).
PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "$PROJECT_ROOT" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]_-')}"
OLD_LOG="${PROJECT}_app-log"
OLD_STORAGE="${PROJECT}_app-storage"
OLD_PRIVATE="${PROJECT}_app-private"
NEW_DATA="${PROJECT}_app-data"

# Pick compose file (production preferred if running)
COMPOSE_ARGS=""
if docker compose -f docker-compose.production.yml ps --format json app 2>/dev/null | grep -q '"State":"running"'; then
  COMPOSE_ARGS="-f docker-compose.production.yml"
elif [[ -f docker-compose.production.yml ]] && [[ ! -f docker-compose.yml ]]; then
  COMPOSE_ARGS="-f docker-compose.production.yml"
fi
DC=(docker compose)
[[ -n "$COMPOSE_ARGS" ]] && DC+=($COMPOSE_ARGS)

echo "==> Compose project: ${PROJECT}"
echo "    Old volumes:  ${OLD_LOG}, ${OLD_STORAGE}, ${OLD_PRIVATE}"
echo "    New volume:   ${NEW_DATA}"
echo ""

# --- 1. detect old volumes -----------------------------------------------
existing=()
for v in "$OLD_LOG" "$OLD_STORAGE" "$OLD_PRIVATE"; do
  if docker volume inspect "$v" >/dev/null 2>&1; then
    existing+=("$v")
  fi
done

if [[ ${#existing[@]} -eq 0 ]]; then
  echo "==> Žádný ze starých volumes neexistuje — patrně už jsi migroval, nebo"
  echo "    je to fresh instalace. Nic k dělání."
  exit 0
fi

echo "==> Nalezeno ${#existing[@]} starých volumes k migraci:"
for v in "${existing[@]}"; do echo "    - $v"; done
echo ""

# --- 2. stop stack -------------------------------------------------------
echo "==> Zastavuji stack (DB volume zůstane nedotčen)…"
"${DC[@]}" down || true
echo ""

# --- 3. ensure new volume exists -----------------------------------------
if ! docker volume inspect "$NEW_DATA" >/dev/null 2>&1; then
  echo "==> Vytvářím nový volume: ${NEW_DATA}"
  docker volume create "$NEW_DATA" >/dev/null
fi

# --- 4. copy data via sidecar alpine container ---------------------------
echo "==> Kopíruji data přes dočasný alpine kontejner…"
COPY_CMDS=""
MOUNTS=()
MOUNTS+=(-v "${NEW_DATA}:/new")
for v in "${existing[@]}"; do
  case "$v" in
    "$OLD_LOG")     MOUNTS+=(-v "${v}:/old/log:ro");     COPY_CMDS+="mkdir -p /new/log && cp -a /old/log/. /new/log/ && ";;
    "$OLD_STORAGE") MOUNTS+=(-v "${v}:/old/storage:ro"); COPY_CMDS+="mkdir -p /new/storage && cp -a /old/storage/. /new/storage/ && ";;
    "$OLD_PRIVATE") MOUNTS+=(-v "${v}:/old/private:ro"); COPY_CMDS+="mkdir -p /new/private && cp -a /old/private/. /new/private/ && ";;
  esac
done
COPY_CMDS+="echo OK"

# www-data v PHP image má UID/GID 33 — sjednotíme owner po kopii.
docker run --rm "${MOUNTS[@]}" alpine sh -c "$COPY_CMDS && chown -R 33:33 /new"
echo "    Hotovo."
echo ""

# --- 5. report -----------------------------------------------------------
echo "============================================================"
echo " Migrace volumes dokončena."
echo ""
echo " Další kroky:"
echo "   1. Nastartuj stack:    ${DC[*]} up -d"
echo "   2. Ověř, že aplikace vidí faktury / uploady / sessions."
echo "   3. Po ověření smaž staré volumes (NEVRATNÉ):"
for v in "${existing[@]}"; do
  echo "        docker volume rm ${v}"
done
echo ""
echo " (Skript NEMAZAL staré volumes automaticky — ručně po ověření.)"
echo "============================================================"
