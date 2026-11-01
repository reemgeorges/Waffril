# Shared helpers for scripts/db/*.sh (sourced). Local Docker Compose stack only.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
env_value() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '[:space:]'; }
DB_NAME="$(env_value DB_DATABASE)"; DB_NAME="${DB_NAME:-waffrli}"
# Hard guards: these scripts are for the LOCAL Docker MySQL of a development
# checkout only — never a shared, staging or production database.
APP_ENV_VALUE="$(env_value APP_ENV)"
[[ "$APP_ENV_VALUE" == local || "$APP_ENV_VALUE" == testing ]] || { echo "Refused: APP_ENV is '${APP_ENV_VALUE:-unset}' (local or testing required)." >&2; exit 3; }
DB_HOST_VALUE="$(env_value DB_HOST)"
[[ "$DB_HOST_VALUE" == mysql ]] || { echo "Refused: DB_HOST is '${DB_HOST_VALUE:-unset}'; only the Docker Compose 'mysql' service is supported." >&2; exit 3; }
# Databases a restore may REPLACE: the local dev database named in .env,
# test databases, and the throwaway demo build database.
restorable_db() { [[ "$1" == "$DB_NAME" || "$1" =~ _test(ing)?$ || "$1" == waffrli_demo_build ]]; }
docker compose ps --status running --services 2>/dev/null | grep -qx mysql || { echo "The mysql service is not running (docker compose up -d mysql)." >&2; exit 1; }
# Credentials never leave the container: the root password is read from the
# mysql container's own environment, not passed on this command line.
mysql_exec() { docker compose exec -T mysql sh -c "$1"; }
