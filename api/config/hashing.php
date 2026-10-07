<?php

// Passwords are hashed with Argon2id (NFR-SEC-02). The cost is set here, so it can be raised
// without touching the code: a hash made with older parameters is rewritten at the next
// sign-in. Defaults: the minimum OWASP recommends for Argon2id (19 MiB, 2 passes, 1 lane).
return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 19456),
        'time' => (int) env('ARGON_TIME', 2),
        'threads' => (int) env('ARGON_THREADS', 1),
        // Off on purpose: a hash of another algorithm (an import, an older record) must be
        // checked with `password_verify` and then rewritten, not refused.
        'verify' => false,
    ],

    'bcrypt' => [
        'rounds' => 12,
        'verify' => false,
        'limit' => null,
    ],

    'rehash_on_login' => true,

];
