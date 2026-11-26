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
# COMPASS_APP points the harness at another checkout, e.g. a worktree of the old code
APP=$(cd "${COMPASS_APP:-$HERE/../..}" && pwd)
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
# Not "testing": Laravel ignores PHP deprecations entirely under unit tests
APP_ENV=harness
APP_DEBUG=false
APP_KEY=0123456789abcdef0123456789abcdef
BASE_URL=$BASE/
STORAGE_DIR=$OUT/storage/
ATLAS_BASE=$FIX/atlas/
ALLOW_NEW_USERS=false
DB_CONNECTION=mysql
DB_DATABASE=compass_test
$DBENV
SESSION_DRIVER=cookie
SESSION_LIFETIME=500000
QUEUE_DRIVER=sync
CACHE_DRIVER=redis
NCHAN_PUBLISH_URL=$FIX/pub
REDIS_DATABASE=9
LOG_DEPRECATIONS_CHANNEL=single
EOF

# --- Fresh database and cache -----------------------------------------------------
mysql_test() { mysql -h127.0.0.1 -u"$(grep ^DB_USERNAME= "$APP/.env" | cut -d= -f2)" -p"$(grep ^DB_PASSWORD= "$APP/.env" | cut -d= -f2)" compass_test "$@"; }
mysql_test -e 'DROP TABLE IF EXISTS users, `databases`, database_users, shares, failed_jobs, geo_cities, geo_countries, geo_cells, geo_tz_cells, stats_shards, stats_segments, stats_days, stats_day_modes, stats_day_places, stats_day_cells, geofences, geofence_events, geofence_webhooks, geofence_deliveries, geofence_dismissed, geo_admin1'
mysql_test < "$HERE/fixtures/schema.sql"
mysql_test < "$HERE/fixtures/schema-stats.sql"
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
record login-start-no-endpoint "${J[@]}" -d "me=$FIX/nolinks/" $BASE/auth/start
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
record settings-add-user-padded "${J[@]}" --data-urlencode "add_user= https://padded.example/ " $BASE/settings/fixture
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
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.69,45.54]},"properties":{"timestamp":"2026-01-17T10:00:00Z","horizontal_accuracy":5,"wifi":"","device_id":" padded id ","motion":[]}},
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
# What the stats pipeline derived from the points posted above. The queue is
# "sync" here, so the job that /api/input queues runs inside the first request
# that saves a point; later requests only mark their day as needing another pass.
mysql_test -e "SELECT database_id, shard_date, version, (dirty_at IS NOT NULL) AS dirty, (processed_at IS NOT NULL) AS processed, error, points_total, points_used FROM stats_shards ORDER BY database_id, shard_date;
    SELECT database_id, shard_date, seq, type, inferred, mode, mode_source, motion, start_at, end_at, tz, local_date, duration_s, distance_m, points, country, city_id, countries, clipped_start, clipped_end FROM stats_segments ORDER BY database_id, shard_date, seq;
    SELECT * FROM stats_days ORDER BY database_id, utc_date, local_date;
    SELECT * FROM stats_day_modes ORDER BY database_id, utc_date, local_date, mode;
    SELECT * FROM stats_day_places ORDER BY database_id, utc_date, local_date, country, city_id;
    SELECT * FROM stats_day_cells ORDER BY database_id, utc_date, local_date, lat_i, lng_i;
    SELECT id, (stats_job_at IS NOT NULL) AS stats_job_queued FROM \`databases\` ORDER BY id" > "$OUT/responses/stats-after-input.txt"
grep -q "^1	2026-01-17	" "$OUT/responses/stats-after-input.txt"; check "input registers its day file for stats" $?
# Cache keys written by the dedupe check, and how long they live (to the nearest hour, so a second or two of running time does not show)
for k in $(redis-cli -n 9 --scan | sort); do echo "$k ttl_hours=$(( ($(redis-cli -n 9 ttl "$k") + 1800) / 3600 ))"; done > "$OUT/responses/redis-keys.txt"
for f in $(cd "$OUT/storage" && find . -type f | sort); do echo "== $f"; cat "$OUT/storage/$f"; echo; done > "$OUT/responses/storage-after.txt"
grep -q '"saved":3' "$OUT/responses/input-points.txt"; check "input saved 3 points (0,0 and bad dates skipped)" $?
grep -q '"saved":1' "$OUT/responses/input-duplicates.txt"; check "input dedupes a repeated timestamp" $?
grep -q 'route.json' "$OUT/responses/input-trip.forwarded.txt"; check "trip posted to micropub with route.json" $?
grep -q '"url"' "$OUT/responses/share.txt"; check "share created" $?

# --- Stats pages, now that the points posted above have been processed --------------------
record stats-year "${J[@]}" $BASE/stats/fixture
record stats-year-empty "${J[@]}" $BASE/stats/fixture/2020
record stats-no-access "${J[@]}" $BASE/stats/other
record stats-api "$BASE/api/stats?token=r-fixture"
record world "${J[@]}" $BASE/stats/fixture/world
record world-api "$BASE/api/countries?token=r-fixture"
record world-api-year "$BASE/api/countries?token=r-fixture&year=2020"
record world-embed-enable "${J[@]}" -d "embed=enable" $BASE/stats/fixture/embed
record world-embed-not-owner "${J[@]}" -d "embed=enable" $BASE/stats/other/embed
embed_token=$(mysql_test -N -e "SELECT embed_token FROM \`databases\` WHERE name='fixture'")
record world-embed-page "$BASE/embed/world/$embed_token"
record world-embed-json "$BASE/embed/world/$embed_token.json"
record world-embed-json-stops "$BASE/embed/world/$embed_token.json?view=stops"
record world-with-embed "${J[@]}" $BASE/stats/fixture/world
record world-embed-disable "${J[@]}" -d "embed=disable" $BASE/stats/fixture/embed
record world-embed-after-disable "$BASE/embed/world/$embed_token"
grep -q '"distance_m": 2715' "$OUT/responses/stats-api.txt"; check "stats API has the distance of the posted points" $?
grep -q 'Total distance' "$OUT/responses/stats-year.txt"; check "stats page renders" $?
grep -q '"code": "US"' "$OUT/responses/world-embed-json.txt"; check "embedded world map lists the country" $?
# Hexagons, per grid, are [column, row, days, days stopped] for the owner and [column, row] in the public embed
tr -d ' \n' < "$OUT/responses/world-api.txt" | grep -qE '"hexes":\{"90":\[\[[0-9]+,-?[0-9]+,[0-9]+,[0-9]+\]'; check "world API has hexagons with day counts" $?
tr -d ' \n' < "$OUT/responses/world-embed-json.txt" | grep -qE '"hexes":\{"90":\[\[[0-9]+,-?[0-9]+\]\],"180"'; check "embedded world map has hexagons without day counts" $?
grep -q ' 404' "$OUT/responses/world-embed-after-disable.txt"; check "embed stops working when turned off" $?

# --- Share created through the API ------------------------------------------------------
share_url=$(grep -o '"url":"[^"]*"' "$OUT/responses/share.txt" | cut -d'"' -f4 | sed 's#\\/#/#g')
share_token=${share_url##*/}
record share-new-page "$share_url"
record share-new-current "$BASE/share/current.json?token=$share_token"
record share-new-history "$BASE/share/history.json?token=$share_token"
record share-default-duration "$BASE/api/share?token=w-fixture" -X POST
record share-open-ended -d "duration=0" -d "label=from the device" "$BASE/api/share?token=w-fixture"

# --- Managing shares in the web app ---------------------------------------------------------
record shares-page "${J[@]}" $BASE/shares/fixture
record shares-create "${J[@]}" -d "duration=14400" --data-urlencode "label=ride to the <coast>" $BASE/shares/fixture
record shares-create-open "${J[@]}" -d "duration=0" $BASE/shares/fixture
record shares-not-member "${J[@]}" -d "duration=3600" $BASE/shares/other
stop_id=$(mysql_test -N -e "SELECT id FROM shares WHERE label='ride to the <coast>'")
stop_token=$(mysql_test -N -e "SELECT token FROM shares WHERE id=$stop_id")
record shares-stop "${J[@]}" -X POST $BASE/shares/fixture/$stop_id/stop
record shares-stop-other-database "${J[@]}" -X POST $BASE/shares/fixture/3/stop
record shares-page-after "${J[@]}" $BASE/shares/fixture
record share-stopped-page "$BASE/s/$stop_token"
mysql_test -e "SELECT id, database_id, label, (expires_at IS NULL) AS open_ended, (revoked_at IS NOT NULL) AS stopped,
    (expires_at > NOW() + INTERVAL 230 MINUTE AND expires_at < NOW() + INTERVAL 250 MINUTE) AS about_four_hours,
    (expires_at > NOW() + INTERVAL 50 MINUTE AND expires_at < NOW() + INTERVAL 70 MINUTE) AS about_an_hour FROM shares ORDER BY id" > "$OUT/responses/db-after-shares.txt"
grep -q 'ride to the &lt;coast&gt;' "$OUT/responses/shares-page-after.txt"; check "sharing page lists the new link, escaped" $?
grep -q ' 200' "$OUT/responses/share-stopped-page.txt" && grep -q 'expired' "$OUT/responses/share-stopped-page.txt"; check "a stopped share shows the expired page" $?

# --- A live point ---------------------------------------------------------------------------
# Posted with the current time, so it falls inside the shares created above.
# Kept to the end: it creates a day file named after today, and everything
# recorded for comparison above must not depend on the date. The time is
# masked in what is saved here.
now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
mask_now() { sed -i -E "s/$now/NOW/g; s/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}\+00:00/TIME/g" "$@"; }
P input-live w-fixture '{"locations":[
  {"type":"Feature","geometry":{"type":"Point","coordinates":[-122.61,45.51]},"properties":{"timestamp":"'$now'","horizontal_accuracy":8,"speed":3.5,"battery_level":0.42,"wifi":"home network","device_id":"phone"}}
]}'
record share-live-current "$BASE/share/current.json?token=$share_token"
record share-live-history "$BASE/share/history.json?token=$share_token"
record share-old-history "$BASE/share/history.json?token=shareactive"
mask_now "$OUT/responses/input-live.forwarded.txt" "$OUT/responses/share-live-current.txt" "$OUT/responses/share-live-history.txt" "$OUT/responses/share-old-history.txt"
grep -q '\[\[-122.61,45.51\]\]' "$OUT/responses/share-live-history.txt"; check "share history has the point posted after the share was created" $?
# (The database's own ping URLs do get the whole record; that is not a share.)
! { cat "$OUT/responses/share-live-current.txt"; grep '"uri":"/pub/' "$OUT/responses/input-live.forwarded.txt"; } | grep -q 'battery\|wifi\|device_id'; check "share viewers are not given battery, wifi or device id" $?
grep -q '"speed":3.5' "$OUT/responses/share-live-current.txt"; check "share viewers get speed and accuracy" $?
[ "$(grep -c '"uri":"/pub/' "$OUT/responses/input-live.forwarded.txt")" = "6" ]; check "the new point is published to each of the six active shares" $?
! grep -q "/pub/sharerevoked\|/pub/shareexpired\|/pub/$stop_token" "$OUT/responses/input-live.forwarded.txt"; check "nothing is published to ended shares" $?

# --- Places (geofences) ---------------------------------------------------------------
# Times and ids differ on every run; the signature is checked and then masked
mask_places() { sed -i -E "s/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]{8}Z/TIME/g; s/sha256=[0-9a-f]{64}/sha256=SIG/g; s/[A-Z][a-z]{2} [0-9]{1,2}, [0-9]{1,2}:[0-9]{2} [ap]m/WHEN/g; s/(id=\"webhook-secret\" readonly value=\")[A-Za-z0-9]{40}/\1SECRET/" "$@"; }
PL() { local name=$1; shift; record "$name" "${J[@]}" "$@"; }
sql() { mysql_test -N -e "$1"; }
record places-logged-out $BASE/places/fixture
PL places-no-access $BASE/places/other
PL places-empty $BASE/places/fixture
grep -q 'None yet' "$OUT/responses/places-empty.txt"; check "places page renders with no places" $?
PL places-empty-again $BASE/places/fixture
[ "$(sql 'SELECT GROUP_CONCAT(CONCAT(name, ":", place_level) ORDER BY id) FROM geofences WHERE kind = "place"')" = "City:city,Region:region,Country:country" ] && grep -q 'Where you are' "$OUT/responses/places-empty.txt"; check "city, region and country are always there, made once" $?
grep -q '>Places</a>' "$OUT/responses/map.txt"; check "the menu has Places" $?

PL places-create-no-position -d "kind=circle&name=Nowhere&radius=150" $BASE/places/fixture
PL places-create-bad-geojson --data-urlencode "kind=polygon" --data-urlencode 'geojson={"type":"Point","coordinates":[0,0]}' $BASE/places/fixture
PL places-after-mistakes $BASE/places/fixture
grep -q 'needs to contain a Polygon or MultiPolygon' "$OUT/responses/places-after-mistakes.txt"; check "a mistake in the form is explained" $?
[ "$(sql 'SELECT COUNT(*) FROM geofences WHERE kind != "place"')" = "0" ]; check "nothing is saved from a form with a mistake" $?

# Places are made on their own, with no web hook
PL places-create-home --data-urlencode "kind=circle" --data-urlencode "name=Home <b>" -d "lat=45.6&lng=-122.7&radius=150" $BASE/places/fixture
PL places-create-tiny -d "kind=circle&name=Tiny&lat=45.6&lng=-122.7&radius=10" $BASE/places/fixture
PL places-create-block --data-urlencode "kind=polygon" --data-urlencode "name=Block" \
  --data-urlencode 'geojson={"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[-123.13,49.27],[-123.11,49.27],[-123.11,49.29],[-123.13,49.29]]]}}' $BASE/places/fixture
PL places-create-area -d "kind=place&place_level=country&name=Another" $BASE/places/fixture
PL places-create-other-db -d "kind=circle&name=Sneaky&lat=45.6&lng=-122.7&radius=150" $BASE/places/other
[ "$(sql 'SELECT GROUP_CONCAT(CONCAT(name, ":", kind, ":", COALESCE(radius, "")) ORDER BY id) FROM geofences WHERE kind != "place"')" = "Home <b>:circle:150,Tiny:circle:50,Block:polygon:" ] && [ "$(sql 'SELECT COUNT(*) FROM geofences WHERE kind = "place"')" = "3" ]; check "places are saved; a tiny radius is raised to 50 m; none for a database you can't see; no extra areas" $?
home=$(sql 'SELECT id FROM geofences WHERE name LIKE "Home%"'); block=$(sql 'SELECT id FROM geofences WHERE name = "Block"'); country=$(sql 'SELECT id FROM geofences WHERE place_level = "country"')
grep -qi "^location: .*/places/fixture/$home\$" "$OUT/responses/places-create-home.txt"; check "adding a place opens its page" $?
[ "$(sql 'SELECT geofence_cursor IS NOT NULL FROM `databases` WHERE name = "fixture"')$(sql 'SELECT COUNT(*) FROM geofence_webhooks')" = "10" ]; check "the cursor is started, and no web hook is made" $?

# Web hooks are added to a place one at a time, each for its own events
PL place-page-new $BASE/places/fixture/$home
PL place-page-other-db $BASE/places/other/$home
grep -q 'Home &lt;b&gt;' "$OUT/responses/place-page-new.txt" && ! grep -q 'Home <b>' "$OUT/responses/place-page-new.txt" && grep -q 'id="webhook-secret"' "$OUT/responses/place-page-new.txt"; check "a place has its own page, with its name escaped and the signing secret" $?
secret=$(sql 'SELECT webhook_secret FROM `databases` WHERE name = "fixture"')
[ ${#secret} = 40 ]; check "the signing secret is made when a place's page is first opened" $?
hook() { local name=$1 place=$2; shift 2; PL "$name" "$@" $BASE/places/fixture/$place/hooks; }
hook hook-bad-url $home -d "on_enter=1&max_age=" --data-urlencode "url=ftp://example.com/x"
hook hook-no-events $home -d "max_age=" --data-urlencode "url=$FIX/hook?f=nothing"
PL place-page-after-mistakes $BASE/places/fixture/$home
grep -q 'Choose at least one event' "$OUT/responses/place-page-after-mistakes.txt" && [ "$(sql 'SELECT COUNT(*) FROM geofence_webhooks')" = "0" ]; check "a web hook needs a URL and at least one event" $?
hook hook-arrive $home -d "on_enter=1&max_age=" --data-urlencode "url=$FIX/hook?f=arrive"
hook hook-leave $home -d "on_exit=1&max_age=900" --data-urlencode "url=$FIX/hook?f=leave"
hook hook-fresh $home -d "on_enter=1&on_exit=1&max_age=300" --data-urlencode "url=$FIX/hook?f=fresh"
hook hook-cross $home -d "on_cross=1&max_age=" --data-urlencode "url=$FIX/hook?f=cross"
hook hook-block $block -d "on_enter=1&on_exit=1&max_age=" --data-urlencode "url=$FIX/hook-fail"
hook hook-country $country -d "max_age=" --data-urlencode "url=$FIX/hook?f=country"
PL hook-other-db -d "on_enter=1" --data-urlencode "url=$FIX/hook?f=sneaky" $BASE/places/other/$home/hooks
record hook-logged-out -d "on_enter=1" --data-urlencode "url=$FIX/hook?f=sneaky" $BASE/places/fixture/$home/hooks
[ "$(sql 'SELECT GROUP_CONCAT(CONCAT(geofence_id = '$home', on_enter, on_exit, on_cross, ":", COALESCE(max_age, "any")) ORDER BY id) FROM geofence_webhooks')" = "1100:any,1010:900,1110:300,1001:any,0110:any,0000:any" ]; check "one place can have several web hooks, each for its own events" $?

# A device that was offline for two hours delivers its points late
mysql_test -e "UPDATE \`databases\` SET geofence_cursor = '{\"seen\": $(( $(date +%s) - 7200 ))}' WHERE name = 'fixture'"
point() { echo '{"type":"Feature","geometry":{"type":"Point","coordinates":['$2','$3']},"properties":{"timestamp":"'$1'","horizontal_accuracy":10}}'; }
base=$(date +%s)
ago() { date -u -d "@$(( base - $1 ))" +%Y-%m-%dT%H:%M:%SZ; }
P geofence-late w-fixture '{"locations":['"$(point $(ago 3600) -122.5 45.4)"','"$(point $(ago 3000) -122.7 45.6)"','"$(point $(ago 2990) -122.7 45.6)"']}'
hooks() { grep -c "\"uri\":\"/hook$2\"" "$OUT/responses/$1.forwarded.txt"; }
allhooks() { grep -c '"uri":"/hook' "$OUT/responses/$1.forwarded.txt"; }
deliveries() { sql "SELECT COALESCE(GROUP_CONCAT(CONCAT(SUBSTRING_INDEX(w.url, '/', -1), ':', e.type, ':', d.status, ':', e.delayed) ORDER BY d.id), '') FROM geofence_deliveries d JOIN geofence_events e ON e.id = d.event_id JOIN geofence_webhooks w ON w.id = d.webhook_id WHERE e.geofence_id = $1"; }
[ "$(allhooks geofence-late)" = "1" ] && grep -q '"uri":"/hook?f=arrive".*\\"event\\":\\"enter\\".*\\"delayed\\":true' "$OUT/responses/geofence-late.forwarded.txt"; check "a late arrival goes only to the web hook for arriving that allows late events, marked delayed" $?
[ "$(deliveries $home)" = "hook?f=arrive:enter:sent:1,hook?f=fresh:enter:skipped:1" ]; check "the web hook with a five minute limit is recorded as skipped; the one for leaving isn't involved" $?
[ "$(sql 'SELECT GROUP_CONCAT(CONCAT(f.name, ":", e.type) ORDER BY e.id) FROM geofence_events e JOIN geofences f ON f.id = e.geofence_id')" = "Home <b>:enter,Tiny:enter" ]; check "a place with no web hook still records the arrival" $?
# The signature is the HMAC of exactly the bytes that were sent
python3 -I - "$OUT/responses/geofence-late.forwarded.txt" "$secret" <<'PY'
import sys, json, hmac, hashlib
ok = False
for line in open(sys.argv[1]):
    if not line.startswith('{'): continue
    r = json.loads(line)
    if r['uri'].startswith('/hook'):
        want = 'sha256=' + hmac.new(sys.argv[2].encode(), r['body'].encode(), hashlib.sha256).hexdigest()
        ok = r['headers'].get('Compass-Signature') == want and 'Authorization' not in r['headers'] and r['headers'].get('Compass-Event') == 'enter'
        if not ok: break
sys.exit(0 if ok else 1)
PY
check "the web hook is signed with the database's secret and carries no token" $?
! grep '"uri":"/hook' "$OUT/responses/geofence-late.forwarded.txt" | grep -q 'r-fixture\|w-fixture'; check "the web hook doesn't give away the read or write token" $?

# More old points, a few seconds off so they are stored as new ones: older than the newest already seen, so nothing happens
P geofence-late-again w-fixture '{"locations":['"$(point $(ago 3605) -122.5 45.4)"','"$(point $(ago 3005) -122.7 45.6)"']}'
grep -q '"saved":2' "$OUT/responses/geofence-late-again.txt"; check "the older points are stored" $?
[ "$(allhooks geofence-late-again)" = "0" ] && [ "$(sql 'SELECT COUNT(*) FROM geofence_events')" = "2" ]; check "points older than the newest already seen change nothing" $?

# (A second or two ahead of the clock, so the point can't share a timestamp with one posted earlier in this run)
# Now: in another country, inside the Block shape, whose receiver is broken
P geofence-live w-fixture '{"locations":['"$(point $(ago -2) -123.12 49.28)"']}'
[ "$(hooks geofence-live '?f=leave')" = "1" ] && [ "$(hooks geofence-live '?f=fresh')" = "1" ] && [ "$(hooks geofence-live '?f=arrive')" = "0" ] && [ "$(hooks geofence-live '?f=cross')" = "0" ]; check "leaving goes to the web hooks for leaving and not to the one for arriving" $?
grep '"uri":"/hook?f=leave"' "$OUT/responses/geofence-live.forwarded.txt" | grep -q '\\"event\\":\\"exit\\".*\\"delayed\\":false.*Home <b>'; check "the exit names the place and is not delayed" $?
grep '"uri":"/hook?f=country"' "$OUT/responses/geofence-live.forwarded.txt" | grep -q '\\"event\\":\\"change\\".*\\"from\\":{\\"code\\":\\"US\\".*\\"to\\":{\\"code\\":\\"CA\\"'; check "crossing a border sends a country change" $?
[ "$(hooks geofence-live '-fail')" = "1" ]; check "arriving inside a GeoJSON shape calls its web hook" $?
P geofence-leave-block w-fixture '{"locations":['"$(point $(ago -90) -123.2 49.35)"']}'
[ "$(hooks geofence-leave-block '-fail')" = "1" ] && [ "$(deliveries $block)" = "hook-fail:enter:failed:0,hook-fail:exit:failed:0" ] && [ "$(sql 'SELECT GROUP_CONCAT(CONCAT(response_code, ":", attempts)) FROM geofence_deliveries WHERE status = "failed"')" = "500:1,500:1" ]; check "a receiver that answers 500 is recorded as failed" $?

record geofence-api "$BASE/api/geofences?token=r-fixture"
record geofence-api-events "$BASE/api/geofence-events?token=r-fixture"
record geofence-api-bad-token "$BASE/api/geofences?token=w-fixture"
grep -q '"name":"Home <b>","kind":"circle","lat":45.6,"lng":-122.7,"radius":150,"state":"outside"' "$OUT/responses/geofence-api.txt" && grep -q '"level":"country","value":"CA"' "$OUT/responses/geofence-api.txt"; check "api/geofences gives each place and its state" $?
[ "$(grep -o '"event":"[a-z]*"' "$OUT/responses/geofence-api-events.txt" | cut -d'"' -f4 | sort | uniq -c | tr -s ' \n' ' ')" = " 1 change 3 enter 3 exit " ] && grep -q '"web_hooks":\["sent","skipped"\]' "$OUT/responses/geofence-api-events.txt"; check "api/geofence-events lists what happened and how each web hook went" $?

PL places-page $BASE/places/fixture
PL place-page $BASE/places/fixture/$home
PL place-page-country $BASE/places/fixture/$country
grep -q 'Home &lt;b&gt;' "$OUT/responses/places-page.txt" && ! grep -q 'Home <b>' "$OUT/responses/places-page.txt" && grep -q '4 web hooks' "$OUT/responses/places-page.txt"; check "the places page lists each place with its web hooks counted" $?
grep -q 'Failed, answered 500' "$OUT/responses/places-page.txt" && grep -q 'Too late, not sent' "$OUT/responses/places-page.txt" && grep -q 'United States → Canada\|US → CA' "$OUT/responses/places-page.txt"; check "the places page shows what happened and how the web hooks went" $?
grep -q 'When I arrive or leave' "$OUT/responses/place-page.txt" && grep -q 'When I pass through' "$OUT/responses/place-page.txt" && ! grep -q 'hook-fail' "$OUT/responses/place-page.txt"; check "a place's page lists its own web hooks and no other place's" $?
grep -q 'When the country changes' "$OUT/responses/place-page-country.txt" && ! grep -q 'name="on_enter"\|Delete place\|places-map' "$OUT/responses/place-page-country.txt"; check "an area has one kind of event, and nothing to draw, rename or delete" $?
PL area-update -d "name=Renamed" $BASE/places/fixture/$country
PL area-delete -X POST $BASE/places/fixture/$country/delete
[ "$(sql "SELECT CONCAT(name, ':', deleted_at IS NULL) FROM geofences WHERE id = $country")" = "Country:1" ]; check "an area can't be renamed or deleted" $?

arrive=$(sql 'SELECT id FROM geofence_webhooks WHERE url LIKE "%f=arrive"'); leave=$(sql 'SELECT id FROM geofence_webhooks WHERE url LIKE "%f=leave"')
PL hook-test -X POST $BASE/places/fixture/$home/hooks/$arrive/test
grep -q '"uri":"/hook?f=arrive".*\\"event\\":\\"test\\"' "$OUT/responses/hook-test.forwarded.txt"; check "the test button sends a test event to that web hook" $?
PL hook-update -d "on_enter=1&on_cross=1&max_age=3600" --data-urlencode "url=$FIX/hook?f=arrive2" $BASE/places/fixture/$home/hooks/$arrive
PL hook-update-wrong-place -d "on_enter=1" --data-urlencode "url=$FIX/hook?f=sneaky" $BASE/places/fixture/$block/hooks/$arrive
[ "$(sql "SELECT CONCAT(SUBSTRING_INDEX(url, '=', -1), ':', on_enter, on_exit, on_cross, ':', max_age) FROM geofence_webhooks WHERE id = $arrive")" = "arrive2:101:3600" ]; check "a web hook can be edited, but only through its own place" $?
PL hook-delete -X POST $BASE/places/fixture/$home/hooks/$leave/delete
PL place-update -d "name=Home&lat=45.6&lng=-122.7&radius=150" $BASE/places/fixture/$home
[ "$(sql "SELECT CONCAT(name, ':', radius, ':', state) FROM geofences WHERE id = $home")" = "Home:150:outside" ]; check "renaming a place keeps its state" $?
PL place-move -d "name=Home&lat=49.35&lng=-123.2&radius=200" $BASE/places/fixture/$home
[ "$(sql "SELECT CONCAT(name, ':', radius, ':', state) FROM geofences WHERE id = $home")" = "Home:200:unknown" ]; check "moving a place resets its state" $?
# Home now sits where the device is. It settles as inside without an event; then the device goes back into Block.
# (Points over a minute apart, so each is taken at its word.)
P geofence-settle w-fixture '{"locations":['"$(point $(ago -160) -123.2 49.35)"']}'
P geofence-back-in-block w-fixture '{"locations":['"$(point $(ago -230) -123.12 49.28)"']}'
[ "$(allhooks geofence-settle)" = "0" ] && [ "$(hooks geofence-back-in-block '?f=fresh')" = "1" ] && [ "$(hooks geofence-back-in-block '?f=leave')" = "0" ] && [ "$(hooks geofence-back-in-block '-fail')" = "1" ]; check "a moved place settles without an event; a removed web hook is no longer called" $?
PL place-delete -X POST $BASE/places/fixture/$block/delete
record place-delete-logged-out -X POST $BASE/places/fixture/$home/delete
[ "$(sql 'SELECT CONCAT(COUNT(*), ":", (SELECT COUNT(*) FROM geofence_webhooks WHERE deleted_at IS NOT NULL)) FROM geofences WHERE deleted_at IS NOT NULL')" = "1:2" ]; check "deleting a place removes its web hooks; not possible when signed out" $?
P geofence-after-delete w-fixture '{"locations":['"$(point $(ago -295) -123.2 49.35)"']}'
[ "$(hooks geofence-after-delete '-fail')" = "0" ] && [ "$(hooks geofence-after-delete '?f=fresh')" = "1" ]; check "a deleted place sends nothing, while the others carry on" $?
PL place-page-deleted $BASE/places/fixture/$block
mask_places "$OUT"/responses/geofence-*.txt "$OUT"/responses/places-*.txt "$OUT"/responses/place-*.txt "$OUT"/responses/hook-*.txt "$OUT"/responses/area-*.txt

# --- Suggested places -------------------------------------------------------------------
# Ten nights at one spot and three and a half weeks of working days at another, as the stats pipeline would have recorded them
mysql_test -e "
  INSERT INTO stats_segments (database_id, shard_date, seq, type, start_at, end_at, tz, local_date, duration_s, start_lat, start_lng, end_lat, end_lng)
    SELECT 1, UTC_DATE() - INTERVAL seq DAY, 900, 'stop', (UTC_DATE() - INTERVAL seq DAY) + INTERVAL 5 HOUR, (UTC_DATE() - INTERVAL seq DAY) + INTERVAL 15 HOUR,
      'America/Los_Angeles', UTC_DATE() - INTERVAL seq DAY, 36000, 45.55000, -122.65000, 45.55000, -122.65000 FROM seq_1_to_10;
  INSERT INTO stats_segments (database_id, shard_date, seq, type, start_at, end_at, tz, local_date, duration_s, start_lat, start_lng, end_lat, end_lng)
    SELECT 1, UTC_DATE() - INTERVAL seq DAY, 901, 'stop', (UTC_DATE() - INTERVAL seq DAY) + INTERVAL 17 HOUR, (UTC_DATE() - INTERVAL seq DAY) + INTERVAL 25 HOUR,
      'America/Los_Angeles', UTC_DATE() - INTERVAL seq DAY, 28800, 45.52000, -122.68000, 45.52000, -122.68000 FROM seq_1_to_25 WHERE WEEKDAY(UTC_DATE() - INTERVAL seq DAY) < 5"
[ "$(sql 'SELECT id FROM `databases` WHERE name = "fixture"')" = "1" ]; check "the suggestion fixtures belong to the fixture database" $?
places_before=$(sql 'SELECT COUNT(*) FROM geofences')
record suggested-logged-out $BASE/places/fixture/suggested
PL suggested-no-access $BASE/places/other/suggested
PL suggested-page $BASE/places/fixture/suggested
PL suggested-page-3 "$BASE/places/fixture/suggested?span=3"
PL suggested-page-all "$BASE/places/fixture/suggested?span=all"
grep -q 'name="name" maxlength="100" value="Home"' "$OUT/responses/suggested-page.txt" && grep -q 'name="name" maxlength="100" value="Work"' "$OUT/responses/suggested-page.txt" && grep -q '10 nights and 100 hours' "$OUT/responses/suggested-page.txt"; check "the suggestions page finds home from the nights and work from the working days" $?
grep -q 'name="lat" value="45.55"' "$OUT/responses/suggested-page.txt" && grep -q 'name="lat" value="45.52"' "$OUT/responses/suggested-page.txt"; check "each suggestion carries its position" $?
[ "$(sql 'SELECT COUNT(*) FROM geofences')" = "$places_before" ]; check "looking at the suggestions creates nothing" $?
grep -q 'See suggested places' "$OUT/responses/places-page.txt"; check "the places page links to the suggestions" $?

# Confirming one makes a place through the usual form, and comes back for the next
PL suggested-add -d "kind=circle&return=suggested&span=12&lat=45.55&lng=-122.65&radius=75" --data-urlencode "name=My home" $BASE/places/fixture
grep -qi '^location: .*/places/fixture/suggested$' "$OUT/responses/suggested-add.txt" && [ "$(sql 'SELECT CONCAT(kind, ":", lat, ":", radius, ":", state) FROM geofences WHERE name = "My home"')" = "circle:45.55000:75:unknown" ]; check "adding a suggestion makes a place and returns to the suggestions" $?
PL suggested-after-add $BASE/places/fixture/suggested
grep -q 'Added &quot;My home&quot;' "$OUT/responses/suggested-after-add.txt" && ! grep -q 'name="lat" value="45.55"' "$OUT/responses/suggested-after-add.txt" && grep -q 'value="Work"' "$OUT/responses/suggested-after-add.txt"; check "a spot that is now a place is no longer suggested, and work is still work" $?
PL suggested-add-bad -d "kind=circle&return=suggested&span=3&lat=&lng=&radius=75&name=Nowhere" $BASE/places/fixture
grep -qi '^location: .*/places/fixture/suggested?span=3$' "$OUT/responses/suggested-add-bad.txt"; check "a mistake comes back to the same stretch of time" $?

# "Not a place" is remembered, and can be undone
PL suggested-dismiss -d "lat=45.52&lng=-122.68&span=12" $BASE/places/fixture/suggested/dismiss
record suggested-dismiss-logged-out -d "lat=45.52&lng=-122.68" $BASE/places/fixture/suggested/dismiss
PL suggested-dismiss-other-db -d "lat=45.52&lng=-122.68" $BASE/places/other/suggested/dismiss
PL suggested-after-dismiss $BASE/places/fixture/suggested
[ "$(sql 'SELECT GROUP_CONCAT(CONCAT(database_id, ":", lat, ":", lng)) FROM geofence_dismissed')" = "1:45.52000:-122.68000" ] && grep -q 'Nothing to suggest' "$OUT/responses/suggested-after-dismiss.txt" && grep -q 'Bring back' "$OUT/responses/suggested-after-dismiss.txt" && [ "$(sql 'SELECT COUNT(*) FROM geofences WHERE name = "Work"')" = "0" ]; check "a dismissed suggestion leaves the list and is kept for undoing, without becoming a place" $?
dismissed=$(sql 'SELECT id FROM geofence_dismissed')
record suggested-restore-logged-out -X POST $BASE/places/fixture/suggested/restore/$dismissed
PL suggested-restore-other-db -X POST $BASE/places/other/suggested/restore/$dismissed
[ "$(sql 'SELECT COUNT(*) FROM geofence_dismissed')" = "1" ]; check "only a member of the database can bring a suggestion back" $?
PL suggested-restore -X POST $BASE/places/fixture/suggested/restore/$dismissed
PL suggested-after-restore $BASE/places/fixture/suggested
[ "$(sql 'SELECT COUNT(*) FROM geofence_dismissed')" = "0" ] && grep -q 'value="Work"' "$OUT/responses/suggested-after-restore.txt"; check "bringing it back suggests it again" $?
# The counts depend on which weekday the run falls on
sed -i -E "s/[0-9,]+ (nights? and [0-9,]+ )?hours? on [0-9,]+ days?/EVIDENCE/g; s/[A-Z][a-z]{2} [0-9]{1,2}(, [0-9]{4})? to [A-Z][a-z]{2} [0-9]{1,2}, [0-9]{4}/DATES/g" "$OUT"/responses/suggested-*.txt
mask_places "$OUT"/responses/suggested-*.txt

# --- Logout -------------------------------------------------------------------------------
record logout "${J[@]}" $BASE/auth/logout
record after-logout "${J[@]}" $BASE/

# --- Console commands -------------------------------------------------------------------
# Loads every command class, so compile-time deprecations in app/Console show up in the logs
(cd "$OUT/app" && $PHP "${PHPFLAGS[@]}" artisan list > "$OUT/artisan-list.txt" 2>&1); check "artisan list" $?

# --- Summaries --------------------------------------------------------------------------
cat "$OUT"/app/storage/logs/*.log > "$OUT/app-log.txt" 2>/dev/null
sed -E 's/^\[[^]]*\] //; s#'"$OUT"'/##g; s#'"$APP"'/##g' "$OUT/php-errors.log" 2>/dev/null | sort | uniq -c | sort -rn > "$OUT/php-errors-summary.txt"
grep -hoE '[a-z]+\.(ERROR|CRITICAL|WARNING)[^{]*' "$OUT/app-log.txt" 2>/dev/null | sed -E 's#'"$OUT"'/##g' | sort | uniq -c | sort -rn > "$OUT/app-log-summary.txt"
fatals=$(grep -ciE 'fatal|uncaught' "$OUT/php-errors-summary.txt")
echo "$(basename "$OUT"): $pass checks passed, $fail failed; php error log: $(wc -l < "$OUT/php-errors-summary.txt") distinct, $fatals fatal; app log: $(wc -l < "$OUT/app-log-summary.txt") distinct warnings/errors"
[ "$fail" = "0" ]
