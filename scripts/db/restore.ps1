<#
.SYNOPSIS  Restore a backup made by backup.ps1/backup.sh into the LOCAL Docker MySQL.
.DESCRIPTION
  DESTRUCTIVE for the target database: it is dropped and recreated from the
  backup. You must type the database name to confirm. Never use it against a
  shared, staging or production server.
.EXAMPLE   powershell -ExecutionPolicy Bypass -File .\scripts\db\restore.ps1 backups\waffrli-20261105-101500.sql.gz
#>
param([Parameter(Mandatory = $true)][string]$File)
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")
function EnvValue($key) { $l = Get-Content .env | Where-Object { $_ -match "^$key=" } | Select-Object -First 1; if ($l) { ($l -replace "^$key=", '').Trim() } else { '' } }
# Hard guards: LOCAL Docker MySQL of a development checkout only.
$appEnv = EnvValue 'APP_ENV'; if ($appEnv -notin @('local', 'testing')) { throw "Refused: APP_ENV is '$appEnv' (local or testing required)." }
if ((EnvValue 'DB_HOST') -ne 'mysql') { throw "Refused: DB_HOST must be the Docker Compose 'mysql' service." }
$devDb = EnvValue 'DB_DATABASE'; if (-not $devDb) { $devDb = 'waffrli' }
if (-not (Test-Path $File)) { throw "Backup not found: $File" }
docker compose cp $File mysql:/tmp/restore.sql.gz | Out-Null
$db = (docker compose exec -T mysql sh -c "gzip -dc /tmp/restore.sql.gz | grep -m1 -oE '^CREATE DATABASE .*``[A-Za-z0-9_]+``' | grep -oE '``[A-Za-z0-9_]+``' | tr -d '``'").Trim()
if (-not $db) { docker compose exec -T mysql rm -f /tmp/restore.sql.gz | Out-Null; throw 'Could not find the database name in the backup.' }
if (-not ($db -eq $devDb -or $db -match '_test(ing)?$' -or $db -eq 'waffrli_demo_build')) {
    docker compose exec -T mysql rm -f /tmp/restore.sql.gz | Out-Null
    throw "Refused: '$db' is not a restorable local database (allowed: '$devDb' from .env, *_test, *_testing, waffrli_demo_build)."
}
Write-Host "This REPLACES the local Docker database '$db' with $File." -ForegroundColor Yellow
if ((Read-Host 'Type the database name to confirm') -ne $db) { docker compose exec -T mysql rm -f /tmp/restore.sql.gz | Out-Null; Write-Host 'Cancelled.'; exit 1 }
docker compose stop queue scheduler | Out-Null
docker compose exec -T mysql sh -c "mysql -uroot -p`"`$MYSQL_ROOT_PASSWORD`" -e 'DROP DATABASE IF EXISTS ``$db``; CREATE DATABASE ``$db`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>/dev/null && gzip -dc /tmp/restore.sql.gz | mysql -uroot -p`"`$MYSQL_ROOT_PASSWORD`" --default-character-set=utf8mb4 2>/dev/null"
$ok = $LASTEXITCODE -eq 0
docker compose exec -T mysql rm -f /tmp/restore.sql.gz | Out-Null
docker compose start queue scheduler | Out-Null
if (-not $ok) { throw 'Restore failed.' }
Write-Host "Restored '$db'."
