<?php

/*
 * The open elections of a suspended institution are paused (docs/slices/18-platform-administration.md, decisions of
 * 2026-10-12): votes refused, nothing closed or opened, the end time not moved, everything resumes on reactivation.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Voting;

require_once __DIR__.'/SuspendInstitutionTest.php';

it('refuses a vote while suspended and takes it again after reactivation [FR-INST-06, FR-VOTE-02]', function () {
    $e = Voting::election();
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertOk();
    $admin = new AuthClient($this);
    $admin->login(platformAdmin()['email'], Accounts::PASSWORD)->assertOk();

    $admin->post(suspendUrl($e['institution']), ['reason' => 'Motif valide'])->assertOk();

    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');
    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
    Voting::enter(Voting::browser($this), $e['election'], $e['codes']['Paul'])->assertStatus(409)->assertJsonPath('error.phase', 'paused');

    $admin->post(reactivateUrl($e['institution']))->assertOk();

    $again = Voting::browser($this);
    Voting::enter($again, $e['election'], $e['codes']['Paul'])->assertOk();
    $again->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertStatus(201);
});

it('shows the phase paused to voters and leaves the status and the times as they were [FR-INST-06]', function () {
    $e = Voting::election();
    $before = Accounts::electionRow($e['election']);
    Accounts::suspend($e['institution']);

    Voting::browser($this)->get("/api/v1/vote/{$e['election']}")->assertOk()->assertJsonPath('data.phase', 'paused');

    $after = Accounts::electionRow($e['election']);
    expect($after['status'])->toBe('open')->and($after['starts_at'])->toBe($before['starts_at'])->and($after['ends_at'])->toBe($before['ends_at']);
});

it('does not open or close the elections of a suspended institution, and does after reactivation [FR-ELEC-03, FR-INST-06]', function () {
    $due = Voting::election(['status' => 'scheduled', 'starts_at' => now('UTC')->subMinute()->toDateTimeString()]);
    $over = Voting::election(['status' => 'open', 'ends_at' => now('UTC')->subMinute()->toDateTimeString()]);
    $otherDue = Voting::election(['status' => 'scheduled', 'starts_at' => now('UTC')->subMinute()->toDateTimeString()]);
    Accounts::suspend($due['institution']);
    Accounts::suspend($over['institution']);

    Artisan::call('elections:advance');

    expect(Accounts::electionRow($due['election'])['status'])->toBe('scheduled')
        ->and(Accounts::electionRow($over['election'])['status'])->toBe('open')
        ->and(Accounts::electionRow($over['election'])['closed_at'])->toBeNull()
        ->and(Accounts::electionRow($otherDue['election'])['status'])->toBe('open');

    Accounts::updateInstitution($due['institution'], ['suspended_at' => null, 'suspension_reason' => null]);
    Accounts::updateInstitution($over['institution'], ['suspended_at' => null, 'suspension_reason' => null]);
    Artisan::call('elections:advance');

    expect(Accounts::electionRow($due['election'])['status'])->toBe('open')
        ->and(Accounts::electionRow($over['election'])['status'])->toBe('closed');
});

it('keeps the voting data of the institution as it was [FR-INST-06]', function () {
    $e = Voting::election();
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertOk();
    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertStatus(201);
    $counts = Voting::counts($e['election']);
    $admin = new AuthClient($this);
    $admin->login(platformAdmin()['email'], Accounts::PASSWORD)->assertOk();

    $admin->post(suspendUrl($e['institution']), ['reason' => 'Motif valide'])->assertOk();
    $admin->post(reactivateUrl($e['institution']))->assertOk();

    expect(Voting::counts($e['election']))->toBe($counts);
});
