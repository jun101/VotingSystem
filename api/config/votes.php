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

    // The voters' routes (election information, session, ballots, submit, end of session) per address and election per
    // minute. High on purpose: a whole school can sit behind one address. A voter's visit is about 4 of these, so 3 000
    // is some 700 voters a minute from one address (load test, tools/loadtest/vote_load.py: 500 voters in a minute
    // passed from a single address at this value, and were refused beyond 150 voters at the former 600). It guards the
    // servers, not the codes: wrong codes are counted apart, above. Multiplied by `auth.rate_limit_factor`.
    'public_per_minute' => 3000,

];
