<?php

/*
 * POST /api/v1/voters/{voter}/credential for a voter who has voted: 409 voter_has_voted
 * (docs/api/credentials/POST-voters-{voter}-credential.md scenario 5, FR-CRED-03).
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Voting;

it('refuses to reissue the code of a voter who has voted and changes nothing [FR-CRED-03] (scenario 1)', function () {
    $owner = Accounts::user();
    $e = Voting::election(['institution' => $owner['institution']]);
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, ['G' => ['H']]), [], false)->assertCreated();
    $admin = Voting::browser($this);
    $admin->login($owner['email'], $owner['password'])->assertOk();

    $admin->post("/api/v1/voters/{$e['voters']['Wideline']}/credential")->assertStatus(409)->assertJsonPath('error.code', 'voter_has_voted');

    expect(Accounts::credentialRow($e['voters']['Wideline'])['code_hash'])->toBe(Codes::hash($e['codes']['Wideline']))
        ->and(Accounts::credentialRow($e['voters']['Wideline'])['reissue_count'])->toBe(0);
});

it('still reissues the code of a voter who has not voted [FR-CRED-03] (scenario 2)', function () {
    $owner = Accounts::user();
    $e = Voting::election(['institution' => $owner['institution']]);
    $admin = Voting::browser($this);
    $admin->login($owner['email'], $owner['password'])->assertOk();

    $admin->post("/api/v1/voters/{$e['voters']['Paul']}/credential")->assertCreated();

    expect(Accounts::credentialRow($e['voters']['Paul'])['reissue_count'])->toBe(1);
    expect(DB::connection(useMigratorConnection())->table('participations')->count())->toBe(0);
});

it('does not let a replaced code vote, and the new code works [FR-CRED-03] (scenario 3)', function () {
    $owner = Accounts::user();
    $e = Voting::election(['institution' => $owner['institution']]);
    $admin = Voting::browser($this);
    $admin->login($owner['email'], $owner['password'])->assertOk();
    $new = $admin->post("/api/v1/voters/{$e['voters']['Paul']}/credential")->assertCreated()->json('data.code');

    Voting::enter(Voting::browser($this), $e['election'], $e['codes']['Paul'])->assertStatus(401);
    Voting::enter(Voting::browser($this), $e['election'], $new)->assertCreated();
});
