#!/usr/bin/env sh
set -eu

if [ "${MYINVOICE_SKIP_MIGRATIONS:-0}" != "1" ]; then
  attempts="${MYINVOICE_MIGRATE_ATTEMPTS:-20}"
  delay="${MYINVOICE_MIGRATE_DELAY:-3}"
  i=1
  while :; do
    if php /var/www/html/api/bin/migrate.php; then
      break
    fi
    if [ "$i" -ge "$attempts" ]; then
      echo "Migration failed after $attempts attempts. Aborting startup." >&2
      exit 1
    fi
    echo "Migration attempt $i/$attempts failed. Retrying in ${delay}s..." >&2
    i=$((i + 1))
    sleep "$delay"
  done
fi

exec apache2-foreground
