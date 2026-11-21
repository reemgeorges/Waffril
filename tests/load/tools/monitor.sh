#!/usr/bin/env bash
# WAFFRLI — read-only resource sampler for load tests (run ON the staging host).
#
#   tests/load/tools/monitor.sh 5 > monitor.csv     # one row every 5 s, Ctrl-C to stop
#
# Reads only: /proc, ss, redis-cli LLEN/LINDEX, mysql SHOW GLOBAL STATUS.
# Credentials: mysql reads ~/.my.cnf (or MYSQL_DEFAULTS_FILE); redis-cli uses
# REDIS_HOST/REDIS_PORT/REDISCLI_AUTH from the environment. Nothing is printed
# except numbers. Queue names: QUEUES="default realtime notifications marketing".
set -u
INTERVAL="${1:-5}"
QUEUES="${QUEUES:-default}"
REDIS_PREFIX="${REDIS_QUEUE_PREFIX:-queues}"
REVERB_PORT="${REVERB_PORT:-8080}"
MYSQL_OPTS=()
[ -n "${MYSQL_DEFAULTS_FILE:-}" ] && MYSQL_OPTS+=("--defaults-file=${MYSQL_DEFAULTS_FILE}")
RCLI=(redis-cli -h "${REDIS_HOST:-127.0.0.1}" -p "${REDIS_PORT:-6379}")

cpu_sample() { awk '/^cpu /{print $2+$3+$4+$6+$7+$8, $5}' /proc/stat; }
mysql_status() {
  mysql "${MYSQL_OPTS[@]}" -N -B -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Innodb_row_lock_current_waits','Innodb_row_lock_waits','Innodb_deadlocks','Slow_queries','Questions')" 2>/dev/null \
    | awk '{v[$1]=$2} END {printf "%s,%s,%s,%s,%s,%s,%s", v["Threads_connected"], v["Threads_running"], v["Innodb_row_lock_current_waits"], v["Innodb_row_lock_waits"], v["Innodb_deadlocks"], v["Slow_queries"], v["Questions"]}'
}

header="time,cpu_pct,load1,mem_used_mb,mem_avail_mb,fpm_procs,reverb_established"
for q in $QUEUES; do header="$header,q_${q}_len,q_${q}_oldest_s"; done
header="$header,mysql_threads_connected,mysql_threads_running,row_lock_current_waits,row_lock_waits_total,deadlocks_total,slow_queries_total,questions_total"
echo "$header"

read -r busy0 idle0 < <(cpu_sample)
while true; do
  sleep "$INTERVAL"
  read -r busy1 idle1 < <(cpu_sample)
  db=$((busy1 - busy0)); di=$((idle1 - idle0)); busy0=$busy1; idle0=$idle1
  cpu=$(awk -v b="$db" -v i="$di" 'BEGIN{ if (b+i>0) printf "%.1f", 100*b/(b+i); else print 0 }')
  load1=$(cut -d' ' -f1 /proc/loadavg)
  read -r used avail < <(free -m | awk '/^Mem:/{print $3, $7}')
  fpm=$(pgrep -c -f 'php-fpm: pool' || true)
  rev=$(ss -Htn state established "( sport = :${REVERB_PORT} )" 2>/dev/null | wc -l)
  row="$(date +%s),$cpu,$load1,$used,$avail,$fpm,$rev"
  now=$(date +%s)
  for q in $QUEUES; do
    len=$("${RCLI[@]}" LLEN "${REDIS_PREFIX}:${q}" 2>/dev/null || echo "")
    oldest=""
    if [ -n "$len" ] && [ "$len" -gt 0 ] 2>/dev/null; then
      created=$("${RCLI[@]}" LINDEX "${REDIS_PREFIX}:${q}" 0 2>/dev/null | grep -o '"createdAt":[0-9]*' | cut -d: -f2)
      [ -n "$created" ] && oldest=$((now - created))
    fi
    row="$row,$len,$oldest"
  done
  echo "$row,$(mysql_status)"
done
