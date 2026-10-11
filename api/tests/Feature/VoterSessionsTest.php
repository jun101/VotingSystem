<?php

use App\Models\Election;
use App\Models\Voter;
use App\Support\Voting\CodeAttempts;
use App\Support\Voting\VoterBallots;
use App\Support\Voting\VoterSessions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Accounts;
use Tests\Support\Voting;

beforeEach(function () {
    Cache::flush();
});

it('opens a session with a 256-bit token that is not the cache key [FR-VOTE-06]', function () {
    $sessions = new VoterSessions;

    $session = $sessions->open(7, 9, 0, 1_790_000_000);

    expect(strlen($session->token))->toBe(43)
        ->and(preg_match('/\A[A-Za-z0-9_-]{43}\z/', $session->token))->toBe(1)
        ->and(Cache::has($session->token))->toBeFalse()
        ->and(Cache::has('vote-session:'.hash('sha256', $session->token)))->toBeTrue()
        ->and($sessions->find($session->token)?->voterId)->toBe(7)
        ->and($sessions->find($session->token)?->electionId)->toBe(9);
});

it('gives two sessions two different tokens and seeds [FR-VOTE-06]', function () {
    $sessions = new VoterSessions;

    $a = $sessions->open(1, 1, 0, 0);
    $b = $sessions->open(1, 1, 0, 0);

    expect($a->token)->not->toBe($b->token)->and($a->seed)->not->toBe($b->seed);
});

it('ends a session after its minutes by the application clock [FR-VOTE-06]', function () {
    $sessions = new VoterSessions;
    $session = $sessions->open(1, 1, 0, 0);

    $this->travel(14)->minutes();
    expect($sessions->find($session->token))->not->toBeNull();

    $this->travel(2)->minutes();
    expect($sessions->find($session->token))->toBeNull();
});

it('forgets a session and answers nothing for a token that is not one [FR-VOTE-06]', function () {
    $sessions = new VoterSessions;
    $session = $sessions->open(1, 1, 0, 0);

    $sessions->forget($session->token);
    $sessions->forget('not a token');
    $sessions->forget(null);

    expect($sessions->find($session->token))->toBeNull()
        ->and($sessions->find('not a token'))->toBeNull()
        ->and($sessions->find(null))->toBeNull()
        ->and($sessions->find(str_repeat('A', 43)))->toBeNull();
});

it('keeps the remaining minutes in a voted state [FR-VOTE-05]', function () {
    $sessions = new VoterSessions;
    $session = $sessions->open(1, 1, 0, 0);

    $this->travel(5)->minutes();
    $sessions->markVoted($session, now()->getTimestamp());

    $found = $sessions->find($session->token);
    expect($found?->hasVoted())->toBeTrue()
        ->and($found?->expiresAt)->toBe($session->expiresAt);

    $this->travel(11)->minutes();
    expect($sessions->find($session->token))->toBeNull();
});

it('does not bring back a session that has expired by marking it voted [FR-VOTE-05]', function () {
    $sessions = new VoterSessions;
    $session = $sessions->open(1, 1, 0, 0);

    $this->travel(20)->minutes();
    $sessions->markVoted($session, now()->getTimestamp());

    expect($sessions->find($session->token))->toBeNull();
});

it('counts a wrong code against the address and the election and nothing else [NFR-SEC-05]', function () {
    $attempts = new CodeAttempts;
    $election = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

    for ($i = 0; $i < 30; $i++) {
        expect($attempts->lockedFor($election, '10.0.0.1'))->toBeNull();
        $attempts->failed($election, '10.0.0.1');
    }

    expect($attempts->lockedFor($election, '10.0.0.1'))->toBeGreaterThan(0)->toBeLessThanOrEqual(600)
        ->and($attempts->lockedFor($election, '10.0.0.2'))->toBeNull()
        ->and($attempts->lockedFor('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa', '10.0.0.1'))->toBeNull();
});

it('keeps the address in a hashed key and releases it after the window [NFR-SEC-05]', function () {
    $attempts = new CodeAttempts;
    $election = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';
    $attempts->failed($election, '203.0.113.9');

    expect(RateLimiter::attempts('vote-code:address:'.$election.':'.hash('sha256', '203.0.113.9')))->toBe(1)
        ->and(RateLimiter::attempts('vote-code:election:'.$election))->toBe(1);

    for ($i = 0; $i < 29; $i++) {
        $attempts->failed($election, '203.0.113.9');
    }
    expect($attempts->lockedFor($election, '203.0.113.9'))->not->toBeNull();

    $this->travel(11)->minutes();
    expect($attempts->lockedFor($election, '203.0.113.9'))->toBeNull();
});

it('locks an election for everyone after 300 wrong codes in an hour [NFR-SEC-05]', function () {
    $attempts = new CodeAttempts;
    $election = '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

    for ($i = 0; $i < 300; $i++) {
        $attempts->failed($election, '10.1.'.intdiv($i, 250).'.'.($i % 250));
    }

    expect($attempts->lockedFor($election, '192.0.2.1'))->toBeGreaterThan(0)
        ->and($attempts->lockedFor('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa', '192.0.2.1'))->toBeNull();
});

it('shuffles the candidates the same way for a session and differently for another [FR-CAND-05]', function () {
    Accounts::reset();
    $e = Voting::election(['candidate_order' => 'shuffled']);
    $election = Election::withoutInstitutionScope()->where('uuid', $e['election'])->firstOrFail();
    $voter = Voter::withoutInstitutionScope()->where('uuid', $e['voters']['Wideline'])->firstOrFail();
    $ballots = new VoterBallots;

    $order = fn (string $seed) => array_column($ballots->present($election, $voter, $seed)[0]['candidates'], 'id');

    $first = $order('seed-one');
    expect($order('seed-one'))->toBe($first)
        ->and($first)->toHaveCount(3);

    $orders = [];
    foreach (range(1, 20) as $i) {
        $orders[] = implode(',', $order('seed-'.$i));
    }
    // Twenty seeds do not all give one order of three (a fixed order would be a shuffle that shuffles nothing).
    expect(count(array_unique($orders)))->toBeGreaterThan(1);
});

it('keeps the election\'s own order when it is manual [FR-CAND-05]', function () {
    Accounts::reset();
    $e = Voting::election();
    $election = Election::withoutInstitutionScope()->where('uuid', $e['election'])->firstOrFail();
    $voter = Voter::withoutInstitutionScope()->where('uuid', $e['voters']['Marc'])->firstOrFail();

    $ids = array_column((new VoterBallots)->present($election, $voter, 'any')[0]['candidates'], 'id');

    expect($ids)->toBe([$e['candidates']['A'], $e['candidates']['B'], $e['candidates']['C']])
        ->and(DB::connection(useMigratorConnection())->table('votes')->count())->toBe(0);
});
