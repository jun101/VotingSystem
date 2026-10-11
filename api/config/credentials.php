<?php

return [

    // The key of the HMAC-SHA-256 stored in place of an access code (docs/slices/10-codes-and-slips.md,
    // "Rules"). Application code reads it here, never with env().
    'hash_key' => env('CREDENTIAL_HASH_KEY'),

    // The emails that carry a code (docs/slices/11-email-and-file.md): at most this many sent a
    // minute, and this many tries for a message before it is marked failed.
    'email_per_minute' => (int) env('CREDENTIAL_EMAIL_PER_MINUTE', 120),
    // The cap of every institution together (the per-minute rate above is each institution's own).
    'email_platform_per_minute' => (int) env('CREDENTIAL_EMAIL_PLATFORM_PER_MINUTE', 600),
    // The emails an institution may have queued or sent in 24 hours (409 `email_quota_exceeded`).
    'emails_per_institution_per_day' => (int) env('CREDENTIAL_EMAILS_PER_INSTITUTION_PER_DAY', 5000),
    'email_tries' => (int) env('CREDENTIAL_EMAIL_TRIES', 3),

];
