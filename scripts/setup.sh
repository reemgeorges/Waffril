#!/usr/bin/env bash
# WAFFRLI one-command LOCAL setup for WSL2 / Linux / macOS (same steps as
# scripts/setup.ps1). Safe and repeatable: never deletes data, never resets
# the database, never overwrites an existing .env value.
#
#   ./scripts/setup.sh                 # interactive
#   ./scripts/setup.sh --demo --yes    # load demo data, fill empty secrets
#   ./scripts/setup.sh --no-demo --no-admin
set -euo pipefail
cd "$(dirname "$0")/.."

DEMO=ask; YES=0; ADMIN=ask
for arg in "$@"; do
  case "$arg" in
    --demo) DEMO=yes ;; --no-demo) DEMO=no ;; --yes) YES=1 ;; --no-admin) ADMIN=no ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[32m==> %s\033[0m\n' "$1"; }
fail() { printf '\033[31mERROR: %s\033[0m\n' "$1" >&2; exit 1; }
secret() { od -An -N"${1:-24}" -tx1 /dev/urandom | tr -d ' \n'; }
env_value() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '[:space:]'; }
ask() { local answer; read -r -p "$1 " answer || true; [[ "${answer:-$2}" =~ ^[yY] ]]; }

step "1/10 Checking prerequisites"
command -v docker >/dev/null || fail "Docker is not installed (Docker Desktop with WSL2 integration, or Docker Engine)."
docker info >/dev/null 2>&1 || fail "Docker is not running."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required."

step "2/10 Preparing .env"
created=0
if [[ ! -f .env ]]; then cp .env.docker.example .env; created=1; echo "Created .env from .env.docker.example"; fi
declare -A secrets=(
  [DB_PASSWORD]="$(secret 16)" [DB_ROOT_PASSWORD]="$(secret 16)"
  [REVERB_APP_ID]="$((RANDOM % 900000 + 100000))" [REVERB_APP_KEY]="$(secret 10)"
  [REVERB_APP_SECRET]="$(secret 20)" [WAFFRLI_DEV_OTP_PEEK_TOKEN]="$(secret 16)"
)
empty=()
for key in DB_PASSWORD DB_ROOT_PASSWORD REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET WAFFRLI_DEV_OTP_PEEK_TOKEN; do
  [[ -z "$(env_value "$key")" ]] && empty+=("$key")
done
if (( ${#empty[@]} )); then
  if (( created || YES )) || ask "Fill empty .env values (${empty[*]}) with random secrets? [y/N]" n; then
    for key in "${empty[@]}"; do
      if grep -qE "^$key=" .env; then sed -i.bak "s|^$key=.*|$key=${secrets[$key]}|" .env && rm -f .env.bak
      else echo "$key=${secrets[$key]}" >> .env; fi
    done
    echo "Filled: ${empty[*]}"
  else
    fail "Cannot continue without database and Reverb secrets in .env."
  fi
else
  echo "Existing .env kept unchanged."
fi

step "3/10 Building images and starting MySQL + Redis"
docker compose build
docker compose up -d mysql redis
printf 'Waiting for MySQL'
for _ in $(seq 1 60); do
  [[ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q mysql)" 2>/dev/null)" == healthy ]] && break
  printf '.'; sleep 3
done
[[ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q mysql)")" == healthy ]] || fail "MySQL is not healthy: docker compose logs mysql"
echo " ready"

step "4/10 Installing dependencies (composer, npm)"
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
if [[ -f node_modules/.package-lock.json ]]; then echo "node_modules present (kept; run 'npm ci' in the node service to refresh)."
else docker compose run --rm --no-deps node sh -c 'npm ci --no-audit --no-fund'; fi

step "5/10 Application key"
if [[ -z "$(env_value APP_KEY)" ]]; then docker compose run --rm --no-deps app php artisan key:generate; else echo "APP_KEY already set (kept)."; fi

step "6/10 Migrations (local development database)"
docker compose run --rm app php artisan migrate

step "7/10 Seeders"
docker compose run --rm app php artisan db:seed --class=WaffrliEssentialSeeder
if [[ "$DEMO" == yes ]] || { [[ "$DEMO" == ask ]] && ask "Load DEMO data (fictional stores, customers, drivers, orders)? [Y/n]" y; }; then
  docker compose run --rm app php artisan db:seed --class=WaffrliDemoSeeder
fi

step "8/10 Storage link and assets"
docker compose run --rm --no-deps app php artisan storage:link --relative || true
docker compose run --rm --no-deps node sh -c 'npm run build && npm run build:admin-theme'

step "9/10 Starting all services"
docker compose up -d
docker compose ps

step "10/10 Super Admin"
if [[ "$ADMIN" == ask ]] && ask "Create a Super Admin now (you will type the password)? [Y/n]" y; then
  docker compose exec app php artisan waffrli:create-super-admin
fi

port="$(env_value APP_PORT)"; port="${port:-8080}"
cat <<EOF

WAFFRLI is running:
  API              http://localhost:${port}/api/v1
  Admin (Filament) http://localhost:${port}/admin
  API docs         http://localhost:${port}/docs
  Reverb (ws)      ws://localhost:$(env_value REVERB_PUBLIC_PORT || echo 8085)
  Mailpit          http://localhost:8025
  MySQL            127.0.0.1:$(env_value FORWARD_DB_PORT || echo 33060) (credentials in .env)
EOF
