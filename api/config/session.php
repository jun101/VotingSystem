<?php

use Illuminate\Support\Str;

// Not used by this slice (the API has no sign-in yet); kept on Redis for the slices that follow.
return [

    'driver' => env('SESSION_DRIVER', 'redis'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => false,
    'connection' => 'default',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => Str::slug((string) env('APP_NAME', 'laravel')).'-session',
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE'),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
    'serialization' => 'json',

];
