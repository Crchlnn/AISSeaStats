#!/bin/sh
# End-to-end test of the real Docker stack (db + app + worker), run by the CI "stack" job (1.2.0-beta.1).
# The stack must already be started with docker compose; COMPOSE_FILES lists the -f options used for it.
#   COMPOSE_FILES="-f docker-compose.yml" sh tests/stack.sh
set -eu

cd "$(dirname "$0")/.."
BASE=${BASE:-http://127.0.0.1:8095}
JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT

fail() { echo "FAIL  $*"; exit 1; }
ok() { echo "ok    $*"; }
# shellcheck disable=SC2086 # COMPOSE_FILES holds several words on purpose
dc() { docker compose ${COMPOSE_FILES:--f docker-compose.yml} "$@"; }
csrf() { grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

# The web container answers once migrations are done (health check: web server, PHP and database).
i=0
until curl -fs -o /dev/null "$BASE/health.php"; do
  i=$((i + 1))
  [ $i -lt 90 ] || { dc ps; fail "app not healthy after 3 minutes"; }
  sleep 2
done
ok "app healthy"

# Processes: web servers and PHP workers as www-data, background worker as www-data.
[ "$(dc exec -T worker id -u)" = "82" ] || fail "worker does not run as www-data"
dc exec -T app ps -o user,args | grep -q '^www-data.*php-fpm: pool' || fail "no PHP-FPM worker running as www-data"
dc exec -T app ps -o user,args | grep -q '^www-data.*lighttpd' || fail "lighttpd does not run as www-data"
ok "processes run as www-data"

# PHP extensions in the real image.
# shellcheck disable=SC2016 # PHP code, $e must not be expanded by the shell
dc exec -T app php -r 'foreach (["mbstring","pdo_mysql","json","zlib","Zend OPcache"] as $e) { if (!extension_loaded($e)) { echo $e; exit(1); } }' \
  || fail "PHP extension missing in the image"
ok "PHP extensions"

# Setup wizard with the one-time code from the container.
code=$(dc exec -T app php bin/setup-code.php --quiet | tr -d '\r')
echo "$code" | grep -Eq '^[A-Z2-9]{4}-[A-Z2-9]{4}$' || fail "no setup code: $code"
dc logs app | grep -q "setup code: $code" || fail "setup code not shown in the container logs"
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/setup.php" | csrf)
out=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" --data-urlencode "setup_code=$code" \
  --data-urlencode "station_name=CI stack" --data-urlencode "station_lat=51.9225" --data-urlencode "station_lon=4.4792" \
  --data-urlencode "timezone=Europe/Amsterdam" --data-urlencode "lang=en" \
  --data-urlencode "password=ci-stack-pass" --data-urlencode "password2=ci-stack-pass" "$BASE/setup.php")
echo "$out" | grep -q "Setup complete" || fail "setup wizard did not complete"
ingest=$(echo "$out" | grep -o '<pre class="cmd">[^<]*' | head -1 | sed 's/<pre class="cmd">//')
[ -n "$ingest" ] || fail "no ingestion token shown"
ok "setup wizard"

# Ingestion (gzip, Basic auth) and the public API.
res=$(printf '{"msgs":[{"mmsi":244030470,"type":1,"lat":51.95,"lon":4.2,"speed":7}]}' | gzip -c |
  curl -s -u "aisseastats:${ingest}" -H "Content-Encoding: gzip" --data-binary @- "$BASE/ingest.php")
echo "$res" | grep -q '"ok":true' || fail "ingestion rejected: $res"
curl -s "$BASE/api.php?q=summary" | grep -q '"active_now":1' || fail "vessel not counted"
for q in records signal constellation "constellation&mode=ports" "remarkable&tag=military"; do
  curl -fs "$BASE/api.php?q=$q" > /dev/null || fail "API $q"
done
ok "ingestion and API"

# Admin login and page.
rm -f "$JAR"
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | csrf)
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" -d action=login --data-urlencode "password=ci-stack-pass" "$BASE/admin.php"
curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | grep -q 'value="logout"' || fail "admin login failed"
ok "admin login"

# The background worker runs its jobs (heartbeat written every minute).
i=0
while :; do
  hb=$(dc exec -T app php -r 'require "/app/src/bootstrap.php"; echo (int) AISSeaStats\Settings::get("worker_heartbeat");' | tr -d '\r')
  [ "${hb:-0}" -gt $(( $(date +%s) - 120 )) ] && break
  i=$((i + 1))
  [ $i -lt 45 ] || { dc logs worker; fail "worker heartbeat missing"; }
  sleep 2
done
ok "worker heartbeat"

# Restart: data kept, migrations not applied twice.
dc restart app > /dev/null
i=0
until curl -fs -o /dev/null "$BASE/health.php"; do
  i=$((i + 1)); [ $i -lt 60 ] || fail "app not healthy after restart"; sleep 2
done
curl -s "$BASE/api.php?q=summary" | grep -q '"all":1' || fail "data lost after restart"
ok "restart keeps the data"

echo "stack test passed"
