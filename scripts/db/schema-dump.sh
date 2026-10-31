#!/usr/bin/env bash
# Schema-only SQL export of the LOCAL Docker database (no data) to
# docs/waffrli/database/schema.sql. Deterministic: no AUTO_INCREMENT
# counters, dump dates or server comments, so diffs show real changes.
source "$(dirname "$0")/_common.sh"
out="docs/waffrli/database/schema.sql"
mkdir -p "$(dirname "$out")"
{
  echo "-- WAFFRLI schema (structure only, no data): mysqldump --no-data of a freshly migrated database."
  echo "-- Regenerate: ./scripts/db/schema-dump.sh (Docker) after 'php artisan migrate'."
  mysql_exec "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --no-data --skip-comments --skip-dump-date --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 '$DB_NAME' 2>/dev/null" \
    | sed -E 's/ AUTO_INCREMENT=[0-9]+//'
} > "$out"
grep -q 'CREATE TABLE `migrations`' "$out" || { echo "Schema dump looks incomplete." >&2; exit 1; }
echo "Wrote $out ($(grep -c '^CREATE TABLE' "$out") tables)"
