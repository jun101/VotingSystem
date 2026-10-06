<?php

use Illuminate\Support\Str;

return [

    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

    ],

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    'serializable_classes' => false,

];
