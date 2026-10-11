<?php

return [

    // The key of the HMAC-SHA-256 stored in place of an access code (docs/slices/10-codes-and-slips.md,
    // "Rules"). Application code reads it here, never with env().
    'hash_key' => env('CREDENTIAL_HASH_KEY'),

    // The emails that carry a code (docs/slices/11-email-and-file.md): at most this many sent a
    // minute, and this many tries for a message before it is marked failed.
    'email_per_minute' => (int) env('CREDENTIAL_EMAIL_PER_MINUTE', 120),
    'email_tries' => (int) env('CREDENTIAL_EMAIL_TRIES', 3),

];
