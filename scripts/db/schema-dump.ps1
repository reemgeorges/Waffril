<#
.SYNOPSIS  Schema-only SQL export (no data) of the LOCAL Docker database to docs\waffrli\database\schema.sql
#>
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")
$line = Get-Content .env | Where-Object { $_ -match '^DB_DATABASE=' } | Select-Object -First 1
$db = if ($line) { ($line -replace '^DB_DATABASE=', '').Trim() } else { 'waffrli' }
docker compose exec -T mysql sh -c "{ echo '-- WAFFRLI schema (structure only, no data): mysqldump --no-data of a freshly migrated database.'; echo '-- Regenerate: ./scripts/db/schema-dump.sh (Docker) after ''php artisan migrate''.'; mysqldump -uroot -p`"`$MYSQL_ROOT_PASSWORD`" --no-data --skip-comments --skip-dump-date --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 '$db' 2>/dev/null | sed -E 's/ AUTO_INCREMENT=[0-9]+//'; } > /tmp/schema.sql"
docker compose cp mysql:/tmp/schema.sql docs/waffrli/database/schema.sql | Out-Null
docker compose exec -T mysql rm -f /tmp/schema.sql | Out-Null
Write-Host 'Wrote docs/waffrli/database/schema.sql'
