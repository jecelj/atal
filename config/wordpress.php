<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WordPress Sites
    |--------------------------------------------------------------------------
    |
    | List of WordPress sites to sync to. Each site should have the
    | Atal Filament Sync plugin installed and configured.
    |
    */
    'sites' => [
        env('WORDPRESS_SITE_URL', 'https://atal.at'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | Shared secret key for authentication. Must match the key configured
    | in the WordPress plugin settings.
    |
    */
    'api_key' => env('WORDPRESS_SYNC_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Filament API URL
    |--------------------------------------------------------------------------
    |
    | Base URL for the Filament API that WordPress will fetch data from.
    |
    */
    'filament_api_url' => env('APP_URL') . '/api/sync',
];
