<?php

use Illuminate\Support\Str;

// The cookie session of the institution users (docs/api/auth/GET-auth-csrf.md): in Redis,
// HttpOnly, SameSite=Lax, 120 minutes since the last request, Secure outside local development.
return [

    'driver' => env('SESSION_DRIVER', 'redis'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => false,
    'connection' => 'default',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => (bool) env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') !== 'local'),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
    'serialization' => 'json',

];
