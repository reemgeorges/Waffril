<#
.SYNOPSIS
  WAFFRLI one-command LOCAL setup (Windows 10/11, Docker Desktop + WSL2).

.DESCRIPTION
  Safe and repeatable. It never deletes data, never resets the database and
  never overwrites an existing .env value:
    1. checks Docker Desktop / Compose;
    2. creates .env from .env.docker.example only if .env is missing
       (fills EMPTY secrets with random values; asks first if .env existed);
    3. builds images, starts MySQL + Redis and waits until healthy;
    4. installs PHP (composer) and front-end (npm) dependencies;
    5. generates APP_KEY only if it is empty;
    6. runs migrations on the LOCAL development database;
    7. runs the essential seeders, and the demo seeders after confirmation;
    8. creates the storage link and builds assets;
    9. starts web, queue, scheduler, Reverb and Mailpit;
   10. optionally creates the first Super Admin (interactive password prompt).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\scripts\setup.ps1
  powershell -ExecutionPolicy Bypass -File .\scripts\setup.ps1 -Demo -Yes
  powershell -ExecutionPolicy Bypass -File .\scripts\setup.ps1 -Demo -Yes -Portal
#>
param(
    [switch]$Demo,      # load demo data without asking
    [switch]$NoDemo,    # never load demo data
    [switch]$Yes,       # answer "yes" to "fill empty .env values?"
    [switch]$Portal,    # also start the shared API portal (Hoppscotch, profile "portal")
    [switch]$NoAdmin    # skip the Super Admin prompt
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Green }
function Fail($text) { Write-Host "ERROR: $text" -ForegroundColor Red; exit 1 }
function Compose { & docker compose @args; if ($LASTEXITCODE -ne 0) { Fail "docker compose $args failed" } }
function NewSecret([int]$bytes = 24) {
    $b = New-Object byte[] $bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    return ([BitConverter]::ToString($b) -replace '-', '').ToLower()
}

Step '1/10 Checking prerequisites'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Fail 'Docker is not installed. Install Docker Desktop (WSL2 backend).' }
docker info *> $null; if ($LASTEXITCODE -ne 0) { Fail 'Docker Desktop is not running. Start it and retry.' }
docker compose version *> $null; if ($LASTEXITCODE -ne 0) { Fail 'Docker Compose v2 is required (bundled with Docker Desktop).' }

Step '2/10 Preparing .env'
$created = $false
if (-not (Test-Path .env)) { Copy-Item .env.docker.example .env; $created = $true; Write-Host 'Created .env from .env.docker.example' }
$lines = Get-Content .env
$secrets = [ordered]@{
    'DB_PASSWORD' = (NewSecret 16); 'DB_ROOT_PASSWORD' = (NewSecret 16)
    'REVERB_APP_ID' = ('' + (Get-Random -Minimum 100000 -Maximum 999999)); 'REVERB_APP_KEY' = (NewSecret 10)
    'REVERB_APP_SECRET' = (NewSecret 20); 'WAFFRLI_DEV_OTP_PEEK_TOKEN' = (NewSecret 16)
    'HOPPSCOTCH_DB_PASSWORD' = (NewSecret 16); 'HOPPSCOTCH_JWT_SECRET' = (NewSecret 24)
    'HOPPSCOTCH_SESSION_SECRET' = (NewSecret 24); 'HOPPSCOTCH_DATA_ENCRYPTION_KEY' = (NewSecret 16)
}
function EnvValue($lines, $key) {
    $line = $lines | Where-Object { $_ -match "^$key=" } | Select-Object -First 1
    if ($null -eq $line) { return $null }
    return ($line -replace "^$key=", '').Trim()
}
$empty = @($secrets.Keys | Where-Object { [string]::IsNullOrEmpty((EnvValue $lines $_)) })
if ($empty.Count -gt 0) {
    $ok = $created -or $Yes
    if (-not $ok) { $ok = (Read-Host "Fill empty .env values ($($empty -join ', ')) with random secrets? [y/N]") -match '^[yY]' }
    if (-not $ok) { Fail 'Cannot continue without database and Reverb secrets in .env.' }
    foreach ($k in $empty) {
        if ($null -ne (EnvValue $lines $k)) { $lines = $lines -replace "^$k=.*$", "$k=$($secrets[$k])" } else { $lines += "$k=$($secrets[$k])" }
    }
    # UTF-8 without BOM on both Windows PowerShell 5.1 and PowerShell 7.
    [IO.File]::WriteAllLines((Join-Path (Get-Location) '.env'), [string[]]$lines, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "Filled: $($empty -join ', ')"
} else { Write-Host 'Existing .env kept unchanged.' }

Step '3/10 Building images and starting MySQL + Redis'
Compose build
Compose up -d mysql redis
Write-Host -NoNewline 'Waiting for MySQL'
for ($i = 0; $i -lt 60; $i++) {
    $state = docker inspect -f '{{.State.Health.Status}}' (docker compose ps -q mysql) 2>$null
    if ($state -eq 'healthy') { break }; Write-Host -NoNewline '.'; Start-Sleep 3
}
if ($state -ne 'healthy') { Fail 'MySQL did not become healthy. Run: docker compose logs mysql' }
Write-Host ' ready'

Step '4/10 Installing dependencies (composer, npm)'
Compose run --rm --no-deps app composer install --no-interaction --prefer-dist
if (Test-Path node_modules/.package-lock.json) { Write-Host "node_modules present (kept; run 'npm ci' in the node service to refresh)." }
else { Compose run --rm --no-deps node sh -c 'npm ci --no-audit --no-fund' }

Step '5/10 Application key'
# Generated here (not by `artisan key:generate` in the container), so the
# container never has to write .env.
$envLines = Get-Content .env
if ([string]::IsNullOrEmpty((EnvValue $envLines 'APP_KEY'))) {
    $kb = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($kb)
    $key = 'base64:' + [Convert]::ToBase64String($kb)
    if ($null -ne (EnvValue $envLines 'APP_KEY')) { $envLines = $envLines -replace '^APP_KEY=.*$', "APP_KEY=$key" } else { $envLines += "APP_KEY=$key" }
    [IO.File]::WriteAllLines((Join-Path (Get-Location) '.env'), [string[]]$envLines, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host 'APP_KEY generated.'
} else { Write-Host 'APP_KEY already set (kept).' }

Step '6/10 Migrations (local development database)'
Compose run --rm app php artisan migrate

Step '7/10 Seeders'
Compose run --rm app php artisan db:seed --class=WaffrliEssentialSeeder
$loadDemo = $Demo -or ((-not $NoDemo) -and ((Read-Host 'Load DEMO data (fictional stores, customers, drivers, orders)? [Y/n]') -notmatch '^[nN]'))
if ($loadDemo) { Compose run --rm app php artisan db:seed --class=WaffrliDemoSeeder }

Step '8/10 Storage link and assets'
# Absolute link (/var/www/html/...): every container mounts the project there.
# (--relative would need symfony/filesystem, which is not installed.)
if (Test-Path public/storage) { Write-Host 'public/storage exists (kept).' }
else { Compose run --rm --no-deps app php artisan storage:link }
Compose run --rm --no-deps node sh -c 'npm run build && npm run build:admin-theme && npm run build:api-docs'

Step '9/10 Starting all services'
Compose up -d
Compose ps

if ($Portal) {
    Step "9b Shared API portal (Hoppscotch, profile 'portal')"
    Compose --profile portal up -d
    # One-time onboarding from inside the Compose network (no Node needed on Windows).
    Compose run --rm --no-deps -e HOPPSCOTCH_URL=http://hoppscotch node node scripts/portal/onboard.mjs
}

Step '10/10 Super Admin'
if ((-not $NoAdmin) -and ((Read-Host 'Create a Super Admin now (you will type the password)? [Y/n]') -notmatch '^[nN]')) {
    docker compose exec app php artisan waffrli:create-super-admin
}

$port = EnvValue (Get-Content .env) 'APP_PORT'
if (-not $port) { $port = '8080' }
Write-Host "`nWAFFRLI is running:" -ForegroundColor Green
Write-Host "  API            http://localhost:$port/api/v1"
Write-Host "  Admin (Filament) http://localhost:$port/admin"
Write-Host "  API docs       http://localhost:$port/docs"
Write-Host "  Reverb (ws)    ws://localhost:8085"
Write-Host "  Mailpit        http://localhost:8025"
if ($Portal) { Write-Host "  API portal     http://localhost:3000 (sign-in links arrive in Mailpit)" }
Write-Host "  MySQL          127.0.0.1:33060 (user/password in .env)"
