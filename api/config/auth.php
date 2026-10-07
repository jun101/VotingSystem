<?php

use App\Models\User;

return [

    'defaults' => [
        'guard' => 'web',
    ],

    // One guard: the cookie session of the institution users and platform admins
    // (docs/design/architecture.md section 4.3). The voter session is another one (slice 12).
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'password_timeout' => 10800,

    // Every number of the auth rate limiters (docs/api/auth/) is multiplied by this integer.
    // Unset or empty: 1. The development stack and the browser tests raise it, since they
    // register many accounts from one address; the API tests keep it at 1.
    'rate_limit_factor' => max(1, (int) env('AUTH_RATE_LIMIT_FACTOR', 1)),

];
