<?php

return [

    // Where you've installed Compass
    'base_url' => env('BASE_URL'),

    // Location data is stored here, one folder per database. Must end with /
    'storage_dir' => env('STORAGE_DIR'),

    // Atlas is used for geocoding trips
    'atlas_base' => env('ATLAS_BASE'),

    // If false, only existing users can sign in
    'allow_new_users' => env('ALLOW_NEW_USERS', false),

    'github_id' => env('GITHUB_ID'),
    'github_secret' => env('GITHUB_SECRET'),

    // Keep the stats tables up to date as location data arrives. Needs the
    // lookup tables from "artisan geo:import" and a queue worker on the
    // compass-stats queue (deploy/compass-stats@.service).
    'stats_enabled' => env('STATS_ENABLED', true),

    // Live sharing pushes new locations to viewers through nginx's nchan
    // module (see deploy/nginx-nchan.conf). This is the address of nchan's
    // publisher location, for example http://127.0.0.1:8090/pub. Left empty,
    // share pages ask for the current location every few seconds instead.
    'nchan_publish_url' => env('NCHAN_PUBLISH_URL'),

    // Duplicate location points are only filtered out when a cache is configured
    'dedupe_cache' => env('CACHE_STORE', env('CACHE_DRIVER')),

    // Mapbox public access token and style (as "user/style-id") for the map
    // tiles. The token comes from the environment only; without one the maps
    // use OpenStreetMap's own tiles.
    'mapbox_token' => env('MAPBOX_TOKEN'),
    'mapbox_style' => env('MAPBOX_STYLE', 'mapbox/light-v10'),

];
