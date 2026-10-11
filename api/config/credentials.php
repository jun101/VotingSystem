<?php

return [

    // The key of the HMAC-SHA-256 stored in place of an access code (docs/slices/10-codes-and-slips.md,
    // "Rules"). Application code reads it here, never with env().
    'hash_key' => env('CREDENTIAL_HASH_KEY'),

];
