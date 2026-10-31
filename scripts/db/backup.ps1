<#
.SYNOPSIS  Back up the LOCAL Docker database to backups\<db>-<timestamp>.sql.gz
.EXAMPLE   powershell -ExecutionPolicy Bypass -File .\scripts\db\backup.ps1
.EXAMPLE   powershell -ExecutionPolicy Bypass -File .\scripts\db\backup.ps1 -Database waffrli_testing
#>
param([string]$Database)
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..\..")
if (-not $Database) {
    $line = Get-Content .env | Where-Object { $_ -match '^DB_DATABASE=' } | Select-Object -First 1
    $Database = if ($line) { ($line -replace '^DB_DATABASE=', '').Trim() } else { 'waffrli' }
}
if ($Database -notmatch '^[A-Za-z0-9_]+$') { throw 'Invalid database name.' }
New-Item -ItemType Directory -Force backups | Out-Null
$file = "backups/$Database-$(Get-Date -Format yyyyMMdd-HHmmss).sql.gz"
# Compressed INSIDE the container (binary-safe; PowerShell pipes are text). The
# root password stays in the mysql container's environment.
docker compose exec -T mysql sh -c "mysqldump -uroot -p`"`$MYSQL_ROOT_PASSWORD`" --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --databases '$Database' 2>/dev/null | gzip > /tmp/backup.sql.gz && test -s /tmp/backup.sql.gz"
if ($LASTEXITCODE -ne 0) { throw 'Backup failed.' }
docker compose cp mysql:/tmp/backup.sql.gz $file | Out-Null
docker compose exec -T mysql rm -f /tmp/backup.sql.gz | Out-Null
Write-Host "Backup written: $file ($([math]::Round((Get-Item $file).Length / 1KB)) KB)"
