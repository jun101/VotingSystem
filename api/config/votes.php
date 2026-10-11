<?php

return [

    // The public half of the key pair that seals the audit record of every vote (docs/design/database.md
    // section 4.2): 64 hexadecimal characters, from `VOTE_AUDIT_PUBLIC_KEY`. It can only encrypt.
    // Application code reads it here, never with env(). A missing or malformed key makes a vote fail
    // as a whole, with nothing recorded.
    'audit_public_key' => env('VOTE_AUDIT_PUBLIC_KEY'),

    // How long a voter session lasts at most, from the moment the code was accepted.
    'session_minutes' => (int) env('VOTE_SESSION_MINUTES', 15),

    // Wrong codes only; a valid code never counts (docs/api/vote/POST-vote-{election}-session.md).
    'code_failures_per_address' => 30,
    'code_failures_address_window_seconds' => 600,
    'code_failures_per_election' => 300,
    'code_failures_election_window_seconds' => 3600,

    // Submissions per session per minute.
    'submit_per_minute' => 10,

    // The public reads (election information, ballots, end of session) per address and election per minute.
    // High on purpose: a whole class sits behind one address. Multiplied by `auth.rate_limit_factor`.
    'public_per_minute' => 600,

];
