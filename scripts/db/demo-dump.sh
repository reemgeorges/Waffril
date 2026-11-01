#!/usr/bin/env bash
# Build a SYNTHETIC demo database dump (fictional data only) without touching
# your dev database: creates the throwaway database `waffrli_demo_build`,
# migrates it, runs the essential + demo seeders, dumps it to
# backups/waffrli-demo-<date>.sql.gz and drops the throwaway database.
# The dump can be restored with restore.sh (it restores as waffrli_demo_build;
# point DB_DATABASE at it, or import it into another local database).
source "$(dirname "$0")/_common.sh"
build=waffrli_demo_build
user="$(env_value DB_USERNAME)"; user="${user:-waffrli}"
cleanup() { mysql_exec "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`$build\`' 2>/dev/null" || true; }
trap cleanup EXIT
mysql_exec "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`$build\`; CREATE DATABASE \`$build\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$build\`.* TO \`$user\`@\`%\`;' 2>/dev/null"
run() { docker compose exec -T -e DB_DATABASE="$build" app php artisan "$@"; }
run migrate --no-interaction
run db:seed --class=WaffrliEssentialSeeder --no-interaction
run db:seed --class=WaffrliDemoSeeder --no-interaction
mkdir -p backups
file="backups/waffrli-demo-$(date +%Y%m%d).sql.gz"
mysql_exec "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --databases '$build' 2>/dev/null" | gzip > "$file"
gzip -t "$file"
echo "Synthetic demo dump: $file ($(du -h "$file" | cut -f1)). Every person, phone and e-mail in it is fictional (*.demo.waffrli.test)."
