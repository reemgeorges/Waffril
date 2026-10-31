# Shared helpers for scripts/db/*.sh (sourced). Local Docker Compose stack only.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
env_value() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '[:space:]'; }
DB_NAME="$(env_value DB_DATABASE)"; DB_NAME="${DB_NAME:-waffrli}"
docker compose ps --status running --services 2>/dev/null | grep -qx mysql || { echo "The mysql service is not running (docker compose up -d mysql)." >&2; exit 1; }
# Credentials never leave the container: the root password is read from the
# mysql container's own environment, not passed on this command line.
mysql_exec() { docker compose exec -T mysql sh -c "$1"; }
