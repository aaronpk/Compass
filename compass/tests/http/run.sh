#!/bin/bash
# Characterization harness. Runs Compass under the given PHP binary with the
# built-in server against tests/http/fixtures/server.php (which stands in for
# Atlas, ping URLs, a Micropub endpoint and an IndieAuth server), a freshly
# loaded MySQL database and a fresh copy of the fixture location data.
# Records every response, every request Compass forwards upstream, and the
# files and rows it writes, so runs can be compared with tests/http/compare.sh.
#
#   tests/http/run.sh <php-binary> <out-dir> [-d ini=value ...]
#
# Needs MySQL access to the compass_test database (credentials from the app's
# .env) and a local redis; it uses redis database 9 and flushes it.
set -u
PHP=$1; OUT=$2; shift 2
HERE=$(cd "$(dirname "$0")" && pwd)
APP=$(cd "$HERE/../.." && pwd)
FIXTURE_PHP=${FIXTURE_PHP:-php8.4}
FIX=http://127.0.0.1:18280
BASE=http://127.0.0.1:18281

rm -rf "$OUT"; mkdir -p "$OUT/responses"
OUT=$(cd "$OUT" && pwd)
export FIXTURE_LOG="$OUT/fixture-requests.log"
: > "$FIXTURE_LOG"

# --- Isolated copy of the app with a test .env --------------------------------
# (Lumen's phpdotenv 1.x lets .env override real environment variables, so the
# copy gets its own .env instead.)
rsync -a --exclude vendor --exclude .env --exclude 'storage/logs/*' --exclude 'storage/framework/*/*' \
  --exclude tests "$APP/" "$OUT/app/"
ln -s "$APP/vendor" "$OUT/app/vendor"
mkdir -p "$OUT/app/storage/logs" "$OUT/app/storage/framework/views" "$OUT/app/storage/framework/cache" "$OUT/app/storage/framework/sessions"
cp -r "$HERE/fixtures/storage" "$OUT/storage"
DBENV=$(grep -E '^DB_(HOST|PORT|USERNAME|PASSWORD)=' "$APP/.env")
cat > "$OUT/app/.env" <<EOF
APP_ENV=testing
APP_DEBUG=false
APP_KEY=0123456789abcdef0123456789abcdef
BASE_URL=$BASE/
STORAGE_DIR=$OUT/storage/
ATLAS_BASE=$FIX/atlas/
DEFAULT_AUTH_ENDPOINT=$FIX/auth
ALLOW_NEW_USERS=false
DB_CONNECTION=mysql
DB_DATABASE=compass_test
$DBENV
SESSION_DRIVER=cookie
SESSION_LIFETIME=500000
QUEUE_DRIVER=sync
CACHE_DRIVER=redis
REDIS_DATABASE=9
EOF

# --- Fresh database and cache -----------------------------------------------------
mysql_test() { mysql -h127.0.0.1 -u"$(grep ^DB_USERNAME= "$APP/.env" | cut -d= -f2)" -p"$(grep ^DB_PASSWORD= "$APP/.env" | cut -d= -f2)" compass_test "$@"; }
mysql_test -e 'DROP TABLE IF EXISTS users, `databases`, database_users, shares, failed_jobs'
mysql_test < "$HERE/fixtures/schema.sql"
mysql_test < "$HERE/fixtures/fixtures.sql"
redis-cli -n 9 flushdb > /dev/null

# --- Servers ------------------------------------------------------------------------
PHPFLAGS=(-d display_errors=0 -d log_errors=1 -d error_reporting=-1 -d "error_log=$OUT/php-errors.log" -d expose_php=0 "$@")
pids=()
trap 'kill "${pids[@]}" 2>/dev/null; wait 2>/dev/null' EXIT
$FIXTURE_PHP -S 127.0.0.1:18280 "$HERE/fixtures/server.php" > "$OUT/fixture-server.log" 2>&1 & pids+=($!)
COMPASS_PUBLIC="$OUT/app/public" $PHP "${PHPFLAGS[@]}" -S 127.0.0.1:18281 -t "$OUT/app/public" "$HERE/router.php" > "$OUT/server.log" 2>&1 & pids+=($!)
for port in 18280 18281; do
  for i in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.1; done
done

pass=0; fail=0
check() { if [ "$2" = "0" ]; then pass=$((pass+1)); else echo "  FAIL $1"; fail=$((fail+1)); fi; }
unslash() { sed 's#\\/#/#g'; }

# Save a response (status line and headers minus Date, then body) and the
# requests Compass sent to the fixture server while handling it
record() {
  local name=$1; shift
  local before; before=$(wc -l < "$FIXTURE_LOG")
  curl -s -D "$OUT/h.tmp" -o "$OUT/b.tmp" "$@"
  { grep -iv '^date:' "$OUT/h.tmp" | tr -d '\r'; cat "$OUT/b.tmp"; } > "$OUT/responses/$name.txt"
  { echo "forwarded:"; sed -n "$((before+1)),\$p" "$FIXTURE_LOG"; } > "$OUT/responses/$name.forwarded.txt"
  rm -f "$OUT/h.tmp" "$OUT/b.tmp"
}
# Snapshot of the rows the app changes, with columns that hold "now" masked
snapshot_db() {
  mysql_test -e "SELECT id, name, micropub_endpoint, micropub_token, ping_urls, timezone, metric, last_location, last_location_date, current_trip FROM \`databases\` ORDER BY id;
    SELECT u.id, u.url, (u.last_login IS NOT NULL) AS has_login FROM users u ORDER BY id;
    SELECT database_id, user_id FROM database_users ORDER BY database_id, user_id;
    SELECT database_id, (expires_at > NOW()) AS active, (expires_at > NOW() + INTERVAL 50 MINUTE AND expires_at < NOW() + INTERVAL 70 MINUTE) AS about_an_hour, LENGTH(token) AS token_length FROM shares ORDER BY id" > "$OUT/responses/$1.txt"
}

# --- Logged-out GETs ------------------------------------------------------------------
python3 -I "$HERE/replay.py" record --base $BASE --requests "$HERE/requests.txt" --out "$OUT/responses/get" --all-headers > /dev/null
cp "$FIXTURE_LOG" "$OUT/responses/get.forwarded.txt"

grep -q '"locations"' "$OUT"/responses/get/*-api-query-token-r-fixture-date-2026-01-15.txt; check "api/query returns locations" $?
grep -q '"linestring"' "$OUT"/responses/get/*-api-query-token-r-fixture-date-2026-01-15-format-linestring.txt; check "api/query linestring" $?
grep -q 'Portland' "$OUT"/responses/get/*-api-last-token-r-fixture-geocode-1.txt; check "api/last geocodes via Atlas" $?
grep -q 'name="me"' "$OUT"/responses/get/0000-GET-.txt; check "logged-out home shows sign-in" $?

# --- Login through the fixture IndieAuth server ---------------------------------------
J=(-b "$OUT/jar" -c "$OUT/jar")
record login-unregistered "${J[@]}" -d "me=https://nobody.example/" $BASE/auth/start
record login-invalid-url "${J[@]}" -d "me=" $BASE/auth/start
record login-start-default-endpoint "${J[@]}" -d "me=$FIX/nolinks/" $BASE/auth/start
record login-bad-state "${J[@]}" "$BASE/auth/callback?code=fixture-code&state=wrong"
record login-start "${J[@]}" -d "me=$FIX/me/" $BASE/auth/start
state=$(grep -i '^location:' "$OUT/responses/login-start.txt" | grep -o 'state=[^&]*' | cut -d= -f2)
record login-callback "${J[@]}" "$BASE/auth/callback?code=fixture-code&state=$state"
grep -qi '^location: /\?$\|^location: http://127.0.0.1:18281/\?$' "$OUT/responses/login-callback.txt"; check "login callback redirects home" $?

# --- Logged-in pages --------------------------------------------------------------------
record dashboard "${J[@]}" $BASE/
record map "${J[@]}" $BASE/map/fixture
record map-range "${J[@]}" "$BASE/map/fixture?from=2026-01-15&to=2026-01-16&tz=Europe/Berlin"
record map-escaping "${J[@]}" "$BASE/map/fixture?from=%22%3E%3Cb%3Ex&tz=Asia/Tokyo"
record map-no-access "${J[@]}" $BASE/map/other
record settings "${J[@]}" $BASE/settings/fixture
record settings-not-creator "${J[@]}" $BASE/settings/other
grep -q 'fixture' "$OUT/responses/dashboard.txt"; check "dashboard lists databases" $?
grep -q 'id="calendar"' "$OUT/responses/map.txt"; check "map page renders calendar" $?
grep -q 'r-fixture' "$OUT/responses/settings.txt"; check "settings shows tokens" $?

# --- Create database ------------------------------------------------------------------
record create-empty "${J[@]}" -d "name=" $BASE/database/create
record create-empty-page "${J[@]}" $BASE/
record create-invalid "${J[@]}" -d "name=bad name!" $BASE/database/create
record create-invalid-page "${J[@]}" $BASE/
record create-duplicate "${J[@]}" -d "name=fixture" $BASE/database/create
record create-duplicate-page "${J[@]}" $BASE/
record create-ok "${J[@]}" -d "name=newdb" $BASE/database/create
record create-ok-page "${J[@]}" $BASE/
grep -q 'newdb' "$OUT/responses/create-ok-page.txt"; check "new database is listed" $?

# --- Settings ----------------------------------------------------------------------------
record settings-add-user "${J[@]}" -d "add_user=https://new-friend.example/" $BASE/settings/fixture
record settings-add-existing-user "${J[@]}" -d "add_user=https://friend.example/" $BASE/settings/fixture
record settings-remove-user "${J[@]}" -d "remove_user=https://friend.example/" $BASE/settings/fixture
record settings-timezone "${J[@]}" -d "timezone=Europe/Berlin" -d "metric=0" $BASE/settings/fixture
record settings-ping-urls "${J[@]}" --data-urlencode "ping_urls=$FIX/ping
$FIX/ping2" $BASE/settings/fixture
record settings-nothing "${J[@]}" -d "unknown=1" $BASE/settings/fixture
record settings-other-db "${J[@]}" -d "timezone=UTC" $BASE/settings/other
record settings-after "${J[@]}" $BASE/settings/fixture
snapshot_db db-after-settings

# --- Micropub authorization for a database ----------------------------------------------
record micropub-start-connected "${J[@]}" -d "me=$FIX/me/" $BASE/settings/fixture/auth/start
mstate=$(grep -i '^location:' "$OUT/responses/micropub-start-connected.txt" | grep -o 'state=[^&]*' | cut -d= -f2)
record micropub-callback-already "${J[@]}" "$BASE/settings/fixture/auth/callback?code=fixture-code&state=$mstate"
record micropub-remove "${J[@]}" $BASE/settings/fixture/auth/remove
record micropub-start "${J[@]}" -d "me=$FIX/me/" $BASE/settings/fixture/auth/start
mstate=$(grep -i '^location:' "$OUT/responses/micropub-start.txt" | grep -o 'state=[^&]*' | cut -d= -f2)
record micropub-callback-bad-state "${J[@]}" "$BASE/settings/fixture/auth/callback?code=fixture-code&state=wrong"
record micropub-callback "${J[@]}" "$BASE/settings/fixture/auth/callback?code=fixture-code&state=$mstate"
record micropub-start-invalid "${J[@]}" -d "me=" $BASE/settings/fixture/auth/start
record micropub-settings "${J[@]}" $BASE/settings/fixture
grep -q 'mp-new-token' "$OUT/responses/micropub-callback.forwarded.txt" || grep -q 'currently posting' "$OUT/responses/micropub-settings.txt"; check "micropub connected" $?

# --- Location input API -------------------------------------------------------------
P() { record "$1" -H 'Content-Type: application/json' --data "$3" "$BASE/api/input?token=$2"; }
P input-no-locations w-fixture '{"foo":1}'
P input-bad-token r-fixture '{"locations":[]}'
P input-points w-fixture '{"locations":[
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.69,45.54]},"properties":{"timestamp":"2026-01-17T10:00:00Z","horizontal_accuracy":5}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.70,45.55]},"properties":{"timestamp":"1768644060","horizontal_accuracy":5}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.71,45.56]},"properties":{"timestamp":"1768644120.250000","horizontal_accuracy":5}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[0,0]},"properties":{"timestamp":"2026-01-17T10:03:00Z"}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.72,45.57]},"properties":{}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.72,45.57]}}
], "trip":{"mode":"walk","start":"2026-01-17T09:55:00Z"}}'
# A timestamp DateTime can't parse throws out of the request (the catch in
# Api::input never matches because Exception isn't imported)
P input-bad-date w-nodata '{"locations":[
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.72,45.57]},"properties":{"timestamp":"2026-01-17T09:00:00Z"}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.72,45.57]},"properties":{"timestamp":"not a date"}}
]}'
P input-duplicates w-fixture '{"locations":[
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.69,45.54]},"properties":{"timestamp":"2026-01-17T10:00:00Z","horizontal_accuracy":5}},
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.73,45.58]},"properties":{"timestamp":"2026-01-17T10:05:00Z","horizontal_accuracy":5}}
], "trip":{"mode":"walk","start":"2026-01-17T09:55:00Z"}}'
P input-trip w-fixture '{"locations":[
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.74,45.59]},"properties":{"timestamp":"2026-01-17T10:10:00Z","horizontal_accuracy":5,"type":"trip","mode":"walk","start":"2026-01-17T09:55:00Z","end":"2026-01-17T10:10:00Z","distance":1234.5,"duration":900,
    "start_location":{"type":"Feature","geometry":{"type":"Point","coordinates":[-122.69,45.54]}},
    "end_location":{"type":"Feature","geometry":{"type":"Point","coordinates":[-122.74,45.59]}}}}
]}'
P input-current w-fixture '{"locations":[], "current":{"type":"Feature","geometry":{"type":"Point","coordinates":[-122.75,45.60]},"properties":{"timestamp":"2026-01-17T11:00:00Z"}}}'
record trip-complete -d "start=2026-01-17 01:55:00" -d "end=2026-01-17 02:10:00" -d "tz=America/Los_Angeles" -d "mode=bicycle" "$BASE/api/trip-complete?token=w-fixture"
record trip-complete-short -d "start=2026-01-17T10:00:00Z" -d "end=2026-01-17T10:00:05Z" -d "mode=walk" "$BASE/api/trip-complete?token=w-fixture"
record trip-complete-bad-token -d "start=x" "$BASE/api/trip-complete?token=nope"
record share -d "duration=3600" "$BASE/api/share?token=w-fixture"
record share-bad-token -d "duration=3600" "$BASE/api/share?token=r-fixture"
snapshot_db db-after-input
for f in $(cd "$OUT/storage" && find . -type f | sort); do echo "== $f"; cat "$OUT/storage/$f"; echo; done > "$OUT/responses/storage-after.txt"
grep -q '"saved":3' "$OUT/responses/input-points.txt"; check "input saved 3 points (0,0 and bad dates skipped)" $?
grep -q '"saved":1' "$OUT/responses/input-duplicates.txt"; check "input dedupes a repeated timestamp" $?
grep -q 'route.json' "$OUT/responses/input-trip.forwarded.txt"; check "trip posted to micropub with route.json" $?
grep -q '"url"' "$OUT/responses/share.txt"; check "share created" $?

# --- Share created through the API ------------------------------------------------------
share_url=$(grep -o '"url":"[^"]*"' "$OUT/responses/share.txt" | cut -d'"' -f4 | sed 's#\\/#/#g')
share_token=${share_url##*/}
record share-new-page "$share_url"
record share-new-current "$BASE/share/current.json?token=$share_token"
record share-new-history "$BASE/share/history.json?token=$share_token"

# --- Logout -------------------------------------------------------------------------------
record logout "${J[@]}" $BASE/auth/logout
record after-logout "${J[@]}" $BASE/

# --- Summaries --------------------------------------------------------------------------
cat "$OUT"/app/storage/logs/*.log > "$OUT/app-log.txt" 2>/dev/null
sed -E 's/^\[[^]]*\] //; s#'"$OUT"'/##g; s#'"$APP"'/##g' "$OUT/php-errors.log" 2>/dev/null | sort | uniq -c | sort -rn > "$OUT/php-errors-summary.txt"
grep -hoE '(local|testing|production)\.(ERROR|CRITICAL|WARNING)[^{]*' "$OUT/app-log.txt" 2>/dev/null | sed -E 's#'"$OUT"'/##g' | sort | uniq -c | sort -rn > "$OUT/app-log-summary.txt"
fatals=$(grep -ciE 'fatal|uncaught' "$OUT/php-errors-summary.txt")
echo "$(basename "$OUT"): $pass checks passed, $fail failed; php error log: $(wc -l < "$OUT/php-errors-summary.txt") distinct, $fatals fatal; app log: $(wc -l < "$OUT/app-log-summary.txt") distinct warnings/errors"
[ "$fail" = "0" ]
