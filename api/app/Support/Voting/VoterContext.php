<?php

namespace App\Support\Voting;

use App\Models\Election;
use App\Models\Voter;

/** The voter, the election and the session of one request of the voting routes. Built per request, never kept. */
final readonly class VoterContext
{
    public function __construct(
        public Election $election,
        public Voter $voter,
        public VoterSession $session,
    ) {}
}
