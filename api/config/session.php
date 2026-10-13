<?php

use Illuminate\Support\Str;

// The cookie session of the institution users (docs/api/auth/GET-auth-csrf.md): in Redis,
// HttpOnly, SameSite=Lax, 120 minutes since the last request, Secure outside local development.
return [

    'driver' => env('SESSION_DRIVER', 'redis'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    // The longest a signed-in session lives, in minutes from the sign-in, whatever its activity (slice 19,
    // ASVS 3.3.2). See App\Http\Middleware\EndExpiredSession.
    'absolute_lifetime' => (int) env('SESSION_ABSOLUTE_LIFETIME', 720),
    'expire_on_close' => false,
    'encrypt' => false,
    'connection' => 'default',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    // School mode is served over plain HTTP (slice 19c): the installer also sets SESSION_SECURE_COOKIE=false there.
    'secure' => (bool) env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') !== 'local' && env('DEPLOYMENT_MODE', 'online') !== 'school'),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
    'serialization' => 'json',

];
