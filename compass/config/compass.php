<?php

return [

    // Where you've installed Compass
    'base_url' => env('BASE_URL'),

    // Location data is stored here, one folder per database. Must end with /
    'storage_dir' => env('STORAGE_DIR'),

    // Atlas is used for geocoding trips
    'atlas_base' => env('ATLAS_BASE'),

    // Used to sign in when a site doesn't advertise its own authorization endpoint
    'default_auth_endpoint' => env('DEFAULT_AUTH_ENDPOINT'),

    // If false, only existing users can sign in
    'allow_new_users' => env('ALLOW_NEW_USERS', false),

    'github_id' => env('GITHUB_ID'),
    'github_secret' => env('GITHUB_SECRET'),

    // Duplicate location points are only filtered out when a cache is configured
    'dedupe_cache' => env('CACHE_STORE', env('CACHE_DRIVER')),

];
