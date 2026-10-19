#!/bin/sh
# Daily PostgreSQL backup of the production server (run by bw-backup.timer).
# Dumps the database, proves the dump restores into a throwaway PostgreSQL, then prunes old backups (prune.py).
set -eu

ROOT=/opt/business-watchdog
DIR="$ROOT/backups"
HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE/.."

env_value() { sed -n "s/^$1=//p" "$ROOT/.env" | tail -1 | tr -d '"'; }
DB="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"

mkdir -p "$DIR"
chmod 700 "$DIR"
STAMP="$(date -u +%Y-%m-%dT%H%M%SZ)"
FILE="$DIR/db-$STAMP.dump"

docker compose exec -T postgres pg_dump --format=custom --no-owner -U "$DB_USER" -d "$DB" > "$FILE.partial"
mv "$FILE.partial" "$FILE"
chmod 600 "$FILE"

CHECK="bw-restore-check-$$"
trap 'docker rm -f "$CHECK" >/dev/null 2>&1 || true' EXIT
docker run -d --name "$CHECK" -e POSTGRES_PASSWORD=restore-check postgres:18 >/dev/null
i=0
until docker exec "$CHECK" pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; do
    i=$((i + 1)); [ $i -gt 60 ] && { echo "restore check database did not start" >&2; exit 1; }; sleep 1
done
docker cp "$FILE" "$CHECK:/tmp/db.dump"
docker exec "$CHECK" createdb -U postgres restored
docker exec "$CHECK" pg_restore -U postgres -d restored --no-owner --exit-on-error /tmp/db.dump
TABLES="$(docker exec "$CHECK" psql -U postgres -d restored -tAc "select count(*) from information_schema.tables where table_schema = 'public'")"
MIGRATIONS="$(docker exec "$CHECK" psql -U postgres -d restored -tAc "select count(*) from migrations")"
[ "$TABLES" -gt 0 ] && [ "$MIGRATIONS" -gt 0 ] || { echo "restored database looks empty" >&2; exit 1; }

echo "$STAMP $(du -h "$FILE" | cut -f1) tables=$TABLES migrations=$MIGRATIONS" > "$DIR/last-success"
echo "backup ok: $FILE ($(du -h "$FILE" | cut -f1), $TABLES tables restored)"
python3 "$HERE/prune.py" "$DIR"
