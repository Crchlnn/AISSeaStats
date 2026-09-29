#!/bin/sh
# Smoke test of the web forms, run by CI against a real database.
# Uses PHP's built-in server with output_buffering=0, like the Docker image,
# so a session started after output (no cookie, CSRF failure) is caught.
set -eu

cd "$(dirname "$0")/.."
PORT=${PORT:-8099}
BASE="http://127.0.0.1:${PORT}"
JAR=$(mktemp)
trap 'kill "$SERVER" 2>/dev/null || true; rm -f "$JAR"' EXIT

php -d output_buffering=0 -S "127.0.0.1:${PORT}" -t public > /dev/null 2>&1 &
SERVER=$!
sleep 1

php -r 'require "src/bootstrap.php"; AISSeaStats\Db::waitReady(30); AISSeaStats\Migrator::run();
  AISSeaStats\Db::pdo()->exec("DELETE FROM setting");'

csrf() { grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
fail() { echo "FAIL  $*"; exit 1; }

token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/setup.php" | csrf)
[ -n "$token" ] || fail "setup form has no CSRF token"

out=$(curl -s -c "$JAR" -b "$JAR" \
  --data-urlencode "csrf=$token" --data-urlencode "station_name=CI" \
  --data-urlencode "station_lat=51.9225" --data-urlencode "station_lon=4.4792" \
  --data-urlencode "timezone=Europe/Amsterdam" --data-urlencode "lang=en" \
  --data-urlencode "password=ci-password-123" --data-urlencode "password2=ci-password-123" \
  "$BASE/setup.php")
echo "$out" | grep -q "Setup complete" || fail "setup wizard did not complete"
ingest=$(echo "$out" | grep -o '<pre class="cmd">[^<]*' | head -1 | sed 's/<pre class="cmd">//')
[ -n "$ingest" ] || fail "no ingestion token shown"

rm -f "$JAR"
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | csrf)
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" -d action=login \
  --data-urlencode "password=ci-password-123" "$BASE/admin.php"
curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | grep -q 'value="logout"' || fail "admin login failed"

res=$(printf '{"msgs":[{"mmsi":244030470,"type":1,"lat":51.95,"lon":4.2,"speed":7}]}' | gzip -c |
  curl -s -u "aisseastats:${ingest}" -H "Content-Encoding: gzip" --data-binary @- "$BASE/ingest.php")
echo "$res" | grep -q '"ok":true' || fail "ingestion rejected: $res"

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api.php?q=summary")
[ "$code" = "200" ] || fail "api summary returned $code"

echo "smoke test passed"
