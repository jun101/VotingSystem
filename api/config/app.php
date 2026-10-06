<?php

return [

    'name' => env('APP_NAME', 'New Voting System'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    // Every server runs in UTC; the election's timezone is used only to show times.
    'timezone' => 'UTC',

    // Languages of the interface: French by default, English available.
    'locale' => env('APP_LOCALE', 'fr'),
    'fallback_locale' => 'fr',
    'supported_locales' => ['fr', 'en'],

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => 'file',
    ],

];
