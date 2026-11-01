#!/usr/bin/env bash
# Back up the LOCAL Docker database to backups/<db>-<timestamp>.sql.gz
# (consistent snapshot: --single-transaction; routines and triggers included).
#   ./scripts/db/backup.sh            # the dev database from .env (DB_DATABASE)
#   ./scripts/db/backup.sh waffrli_testing
source "$(dirname "$0")/_common.sh"
db="${1:-$DB_NAME}"
[[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid database name." >&2; exit 2; }
mkdir -p backups
file="backups/${db}-$(date +%Y%m%d-%H%M%S).sql.gz"
mysql_exec "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --databases '$db' 2>/dev/null" | gzip > "$file"
[[ -s "$file" ]] && gzip -t "$file" || { rm -f "$file"; echo "Backup failed." >&2; exit 1; }
echo "Backup written: $file ($(du -h "$file" | cut -f1))"
