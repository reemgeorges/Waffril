#!/usr/bin/env bash
# Restore a backup made by backup.sh into the LOCAL Docker MySQL.
# DESTRUCTIVE for the target database: it is replaced by the backup's
# content. Asks you to type the database name to confirm. Never use it
# against a shared, staging or production server.
#   ./scripts/db/restore.sh backups/waffrli-20261105-101500.sql.gz
source "$(dirname "$0")/_common.sh"
file="${1:-}"
[[ -f "$file" ]] || { echo "Usage: $0 <backup.sql.gz>" >&2; exit 2; }
gzip -t "$file" || { echo "Not a valid gzip backup." >&2; exit 1; }
db="$( { gzip -dc "$file" 2>/dev/null || true; } | grep -m1 -oE '^CREATE DATABASE .*`[A-Za-z0-9_]+`' | grep -oE '`[A-Za-z0-9_]+`' | tr -d '`')"
[[ -n "$db" ]] || { echo "Could not find the database name in the backup." >&2; exit 1; }
restorable_db "$db" || { echo "Refused: '$db' is not a restorable local database (allowed: '$DB_NAME' from .env, *_test, *_testing, waffrli_demo_build)." >&2; exit 3; }
echo "This REPLACES the local Docker database '$db' with $file."
read -r -p "Type the database name to confirm: " answer
[[ "$answer" == "$db" ]] || { echo "Cancelled."; exit 1; }
docker compose stop queue scheduler >/dev/null 2>&1 || true
# Drop + recreate first so tables that are not in the backup do not survive
# (database-level GRANTs are kept by MySQL across DROP/CREATE DATABASE).
mysql_exec "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>/dev/null"
gzip -dc "$file" | mysql_exec "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --default-character-set=utf8mb4 2>/dev/null"
docker compose start queue scheduler >/dev/null 2>&1 || true
echo "Restored '$db'. Run 'docker compose exec app php artisan migrate --pretend' to see whether newer migrations are pending."
