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

# Several workers: the alert test below makes the server call itself.
PHP_CLI_SERVER_WORKERS=4 php -d output_buffering=0 -S "127.0.0.1:${PORT}" -t public > /dev/null 2>&1 &
SERVER=$!
sleep 1

# Start from an empty station: CI runs this after tests/run.php on the same database.
# shellcheck disable=SC2016 # PHP code, $t must not be expanded by the shell
php -r 'require "src/bootstrap.php"; AISSeaStats\Db::waitReady(30); AISSeaStats\Migrator::run();
  foreach (["setting", "vessel", "stats_hourly", "stats_daily", "vessel_hourly", "vessel_daily", "msgtype_daily",
    "position", "passage", "range_polar", "ingest_log", "zone", "vessel_photo", "photo_lookup", "debug_msg"] as $t) {
      AISSeaStats\Db::pdo()->exec("DELETE FROM $t");
  }'

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

# Declared destinations "FR SML" and "FRSML" are merged into one row, and its vessel list has both.
res=$(printf '{"msgs":[{"mmsi":227000101,"type":5,"shipname":"ALPHA","destination":"FR SML"},{"mmsi":227000102,"type":5,"shipname":"BRAVO","destination":"FRSML"}]}' |
  curl -s -u "aisseastats:${ingest}" --data-binary @- "$BASE/ingest.php")
echo "$res" | grep -q '"ok":true' || fail "ingestion of static messages rejected: $res"
curl -s "$BASE/api.php?q=routes&days=30" | grep -q '"dkey":"FRSML","vessels":2,"destination":"FRSML"' || fail "destinations not merged"
curl -s "$BASE/api.php?q=list&by=dest&value=FRSML&days=30" | grep -o '"mmsi":22700010[12]' | sort -u | wc -l | grep -q 2 || fail "destination list incomplete"
curl -s "$BASE/api.php?q=list&by=flag&value=FR&days=30" | grep -q '"mmsi":227000101' || fail "flag list missing vessel"

# Destination dictionary saved from the admin; meaningless destinations are "unknown".
res=$(printf '{"msgs":[{"mmsi":227000103,"type":5,"shipname":"CHARLIE","destination":"SAINT-MALO"},{"mmsi":227000104,"type":5,"shipname":"DELTA","destination":"0"}]}' |
  curl -s -u "aisseastats:${ingest}" --data-binary @- "$BASE/ingest.php")
echo "$res" | grep -q '"ok":true' || fail "ingestion of destinations rejected: $res"
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | csrf)
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" -d action=dest_aliases \
  --data-urlencode "dest_from[]=Saint-Malo, ST-MALO" --data-urlencode "dest_to[]=FRSML" \
  --data-urlencode "dest_from[]=" --data-urlencode "dest_to[]=" "$BASE/admin.php"
curl -s "$BASE/api.php?q=routes&days=30" | grep -q '"dkey":"FRSML","vessels":3' || fail "destination dictionary not applied"
curl -s "$BASE/api.php?q=list&by=dest&value=%3F&days=30" | grep -q '"mmsi":227000104' || fail "unknown destination list missing vessel"

# Click on a bar of the vessel chart: vessels of one hour, one day, one month.
hour=$(( $(date +%s) / 3600 * 3600 ))
curl -s "$BASE/api.php?q=list&by=hour&t=${hour}" | grep -q '"mmsi":244030470' || fail "hour list missing vessel"
day=$(TZ=Europe/Amsterdam date +%F)
curl -s "$BASE/api.php?q=list&by=day&value=${day}" | grep -q '"mmsi":244030470' || fail "day list missing vessel"
curl -s "$BASE/api.php?q=list&by=month&value=$(echo "$day" | cut -c1-7)" | grep -q '"mmsi":244030470' || fail "month list missing vessel"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api.php?q=list&by=hour&t=123")
[ "$code" = "400" ] || fail "bad hour accepted ($code)"

# Admin photo upload, then served by photo.php and preferred by the photo look-up.
PNG=$(mktemp)
echo "iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGOQi1pFEmIY1TCqYfhqAAChBSIQbP1etQAAAABJRU5ErkJggg==" | base64 -d > "$PNG"
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | csrf)
curl -s -o /dev/null -c "$JAR" -b "$JAR" -F "csrf=$token" -F action=photo_upload -F photo_mmsi=227000101 \
  -F "photo=@${PNG};type=image/png" -F "photo_credit=CI test" "$BASE/admin.php"
rm -f "$PNG"
ctype=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/photo.php?mmsi=227000101")
[ "$ctype" = "image/png" ] || fail "uploaded photo not served ($ctype)"
curl -s "$BASE/api.php?q=photo&mmsi=227000101" | grep -q '"source":"local"' || fail "local photo not used"

# Alerts: saved from the admin, "Send a test" through a webhook (this server's health page).
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | csrf)
out=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" -d action=alerts -d alert_enabled=1 -d alert_after_min=30 \
  --data-urlencode "webhook_url=$BASE/health.php" -d test=1 "$BASE/admin.php")
echo "$out" | grep -q "test message sent" || fail "alert test through webhook failed"
out=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" -d action=alerts --data-urlencode "webhook_url=javascript:x" "$BASE/admin.php")
echo "$out" | grep -q "Invalid alert setting" || fail "invalid webhook URL accepted"
curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | grep -q 'Data (tables)' || fail "database size tile missing"

# Vessel chart: 90 days means 90 daily bars, even for a new station.
curl -s "$BASE/api.php?q=counts&period=day&days=90" | grep -o '"t":"' | wc -l | grep -qx 90 || fail "90-day chart does not have 90 bars"

# Debug capture from the admin, then download.
token=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | csrf)
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$token" -d action=debug_start -d debug_mmsi=227000101 -d debug_hours=1 "$BASE/admin.php"
printf '{"msgs":[{"mmsi":227000101,"type":5,"shipname":"ALPHA","destination":"FRSML"}]}' |
  curl -s -o /dev/null -u "aisseastats:${ingest}" --data-binary @- "$BASE/ingest.php"
curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php?debug_export=1" | grep -q '"shipname":"ALPHA"' || fail "debug capture missing message"
curl -s -c "$JAR" -b "$JAR" "$BASE/admin.php" | grep -q 'Capture running until' || fail "debug capture not shown as running"

echo "smoke test passed"
