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

    // Duplicate location points are only filtered out when a cache is configured
    'dedupe_cache' => env('CACHE_STORE', env('CACHE_DRIVER')),

    // Mapbox access token and style (as "user/style-id") for the map tiles
    'mapbox_token' => env('MAPBOX_TOKEN', 'pk.eyJ1IjoiYWFyb25wayIsImEiOiJja3A0eXV2ZXIwMGt3MnVuc2Uzcm1yYzFuIn0.-_qwPOLRiQk8t56xs6vkfg'),
    'mapbox_style' => env('MAPBOX_STYLE', 'mapbox/light-v10'),

];
