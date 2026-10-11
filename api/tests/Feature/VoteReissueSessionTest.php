<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Voting;

it('ends a voter session opened with a code that was replaced since, for reading and for voting [FR-CRED-03]', function () {
    Accounts::reset();
    Voting::keypair();
    $owner = Accounts::user();
    $e = Voting::election(['institution' => $owner['institution']]);
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Paul'])->assertCreated();
    $voter->get("/api/v1/vote/{$e['election']}/ballots")->assertOk();
    $admin = Voting::browser($this);
    $admin->login($owner['email'], $owner['password'])->assertOk();

    $admin->post("/api/v1/voters/{$e['voters']['Paul']}/credential")->assertCreated();

    $voter->get("/api/v1/vote/{$e['election']}/ballots")->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertStatus(401);
    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0])
        ->and(DB::connection(useMigratorConnection())->table('participations')->count())->toBe(0);
});

it('does not let a session of one election read or vote in another [FR-VOTE-06]', function () {
    Accounts::reset();
    Voting::keypair();
    $a = Voting::election();
    $b = Voting::election();
    $voter = Voting::browser($this);
    Voting::enter($voter, $a['election'], $a['codes']['Wideline'])->assertCreated();

    $voter->get("/api/v1/vote/{$b['election']}/ballots")->assertStatus(401);
    $voter->post("/api/v1/vote/{$b['election']}/submit", Voting::body($b), [], false)->assertStatus(401);
    // Ending the session at another election's address leaves this one alone.
    $voter->delete("/api/v1/vote/{$b['election']}/session", [], false)->assertNoContent();
    $voter->get("/api/v1/vote/{$a['election']}/ballots")->assertOk();
});

it('refuses the code of an election in another election\'s address [FR-VOTE-01]', function () {
    Accounts::reset();
    Voting::keypair();
    $a = Voting::election();
    $b = Voting::election();

    Voting::enter(Voting::browser($this), $b['election'], $a['codes']['Wideline'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_code');
});
