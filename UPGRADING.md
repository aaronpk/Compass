# Upgrading Compass

This guide is for an existing install that runs the old version of Compass
(Lumen 5.1 on PHP 5 or 7) and is moving to the current one (Laravel 13 on
PHP 8.4 or newer, with the stats pages, the world map and the reworked
sharing).

If your install already runs on Laravel and you only need the newer features,
skip to [step 5](#5-deploy-the-code) and ignore the notes about PHP and
sessions.

Your location data is not converted or rewritten at any point. The files in
`STORAGE_DIR` stay as they are, and everything new is kept in extra MySQL
tables that can be emptied and rebuilt.

## What changes

- **PHP 8.4 or newer is required.** 8.4, 8.5 and 8.6 are tested.
- **The framework is Laravel 13.** The web root is still `compass/public` and
  the same rewrite rule to `index.php` applies, so the web server
  configuration only needs the new PHP-FPM socket.
- **Eight database migrations are pending.** They add tables and nullable
  columns only. Nothing existing is altered in a way the old code can't read.
- **New pages:** yearly stats and a world map for each database, places
  (geofences) that run web hooks, a page to manage share links, and a
  one-month calendar on the map page.
- **A second queue worker** keeps the stats up to date.
- **Mapbox tiles need your own token** in `.env`. The token used to be built
  into the scripts. Without one the maps use OpenStreetMap's own tiles.

## Before you start

1. **Back up** the MySQL database and the `.env` file. Keep the old release
   directory until you are happy with the new one.
2. **Check `APP_KEY`** in `.env`. It must be 32 characters (a plain string
   from an old install is fine). Laravel uses it to encrypt cookies.
3. **Expect one sign-out.** The session cookie format changes, so everyone
   is signed out once. Devices posting with a write token are not affected.

## 1. Install PHP

Install PHP 8.4 or newer, both the command line and FPM, with the extensions
Laravel needs (`ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`,
`pdo`, `tokenizer`, `xml`) plus `pdo_mysql` and `zip`. Redis is reached
through a PHP library, so no redis extension is needed.

Check which version a bare `php` runs. Cron jobs and the commands below use it.

On recent Debian and Ubuntu releases, also install `tzdata-legacy`:

```
sudo apt install tzdata-legacy
```

Older timezone names such as `US/Pacific` moved into that package. If a
database is set to one of them and the package is missing, the map's API
calls fail for it.

## 2. Stop the old queue listeners

Stop whatever runs `php artisan queue:listen` and let the queue empty first.
Jobs queued by the old version should be assumed unreadable by the new one.

## 3. Edit `.env`

The old setting names (`QUEUE_DRIVER`, `CACHE_DRIVER`) still work, so nothing
has to be renamed. Make these changes:

| Setting | What to do |
|---|---|
| `MAPBOX_TOKEN` | Add it, with a public access token (it starts with `pk.`) from your Mapbox account. Without one the maps use OpenStreetMap's own tiles, which need no account but are only meant for light use. |
| `STATS_ENABLED` | Add `STATS_ENABLED=false` for now. You will remove it in step 9. |
| `DEFAULT_AUTH_ENDPOINT` | Delete it. It is no longer read. Signing in with an address that advertises no authorization endpoint now shows an error. |

`STATS_ENABLED=false` matters: the new code updates the stats tables whenever
a device posts data, and those tables don't exist until the migrations have
run.

## 4. Point the web server at the new PHP

Change the FPM socket in the site's configuration to the new PHP version and
reload the web server. Nothing else in the configuration needs to change.

## 5. Deploy the code

```
git pull
cd compass
composer install --no-dev
```

If you are coming from the Laravel version and cache the configuration, run
`php artisan optimize` as the web server's user after every `.env` change in
this guide.

## 6. Run the migrations

```
php artisan migrate --force
```

Each one takes well under a second. They create the lookup, stats and
geofence tables, and add columns for share links, the world map's public
address and failed jobs.

## 7. Load the lookup tables

```
php artisan geo:import
```

This downloads the list of cities with more than 5,000 people from GeoNames
(about 6 MB), and the names of states and provinces, so that Compass can name
the country, region, city and timezone of a location without calling an
outside service. Do this before step 9, or the
stats come out with no countries or cities.

## 8. Install the queue workers

The `deploy` directory has systemd units. Edit `WorkingDirectory`, `User` and
the PHP binary in each to match your install, then:

```
sudo cp deploy/compass-queue@.service deploy/compass-queue.target deploy/compass-stats@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now compass-queue.target
sudo systemctl enable --now compass-stats@1
```

- `compass-queue.target` runs four workers for trips, notifications and
  geofences, in place of the old `queue:listen`.
- `compass-stats@1` runs one low-priority worker that keeps the stats tables
  up to date. Without it the stats pages stay empty.

Restart both after every later deploy.

## 9. Turn the stats on and build them

Remove `STATS_ENABLED=false` from `.env`, then:

```
php artisan stats:backfill
php artisan stats:status
```

`stats:backfill` queues every day file for the stats worker, and
`stats:status` shows how far it has got. Use
`php artisan stats:backfill --sync` to run it in the foreground with a
progress bar instead.

Remove the setting **before** starting the backfill. A day that has already
been processed is only looked at again if the setting was on when more data
for it arrived.

A day of data takes around a tenth of a second, so years of history take
minutes, not hours. The backfill only reads the location files, and new data
from devices is handled ahead of it.

## 10. Check

- `php artisan stats:status` shows every day processed and no errors.
- The map page shows tiles, and the calendar days are shaded.
- The Stats and World pages show data.
- A device posting to `/api/input` gets `"result": "ok"`.
- A share link opens and shows the current location.

## What you and your users will notice

- **One more point on old days.** Day files written by old versions often
  have no final newline, and the old code dropped their last record. It now
  shows up.
- **Share links show less.** A viewer gets the position, time, speed and
  accuracy. Battery level, wifi name and device id are no longer sent.
- **Places.** A new page for geofences. Compass keeps track of whether you
  are in each one, and each can have web hooks for arriving and leaving, or
  for a change of city, region or country. Nothing happens until someone
  adds one.
- **Share links can be managed.** The Share page lists them and can stop one.
- **The world map can be made public.** This is off until the database's
  owner turns it on from the World page.
- **Error pages look different.** They are Laravel's standard ones.

## Optional: live updates for share links

By default a share page asks for the location every few seconds. To push new
locations to viewers the moment they arrive, install nginx's
[nchan](https://nchan.io) module, follow `deploy/nginx-nchan.conf`, and set
`NCHAN_PUBLISH_URL` in `.env`. This can be done at any later time.

## Rolling back

Point the web server back at the old release and its PHP version, stop
`compass-queue.target` and `compass-stats@1`, and start the old queue
listener. The new tables and columns can stay; the old code ignores them.
Everyone is signed out once more.

## Later upgrades

After any later update:

```
git pull
cd compass
composer install --no-dev
php artisan migrate --force
php artisan stats:backfill
sudo systemctl restart compass-queue.target compass-stats@1
```

If your install is from before geofences were added, run
`php artisan geo:import --only-regions` once after the migration. It loads the
names of states and provinces that "region changes" places show; without it
they show a code such as `US.OR`.

`stats:backfill` is quick when nothing needs redoing. When an update changes
how the stats are worked out, it reprocesses the days done with the older
version.
