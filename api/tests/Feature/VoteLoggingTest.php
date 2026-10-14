<?php

use Tests\Support\Accounts;
use Tests\Support\Voting;

/** What was written to the logs since the offsets were taken. */
function voteLoggedSince(array $offsets): string
{
    $logged = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $logged .= (string) substr((string) file_get_contents($file), $offsets[$file] ?? 0);
    }

    return $logged;
}

function voteLogOffsets(): array
{
    $offsets = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $offsets[$file] = filesize($file);
    }

    return $offsets;
}

it('logs a failed vote by route, status and class only, never the body, a code, a voter or a choice [FR-SEC-01]', function () {
    Accounts::reset();
    $pair = Voting::keypair();
    unset($pair);
    $e = Voting::election();
    $browser = Voting::browser($this);
    Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    config(['votes.audit_public_key' => '']);
    $offsets = voteLogOffsets();

    $browser->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, ['P' => ['B'], 'G' => ['I']]), [], false)->assertStatus(500);

    $logged = voteLoggedSince($offsets);
    expect($logged)->toContain('vote.request_failed')
        ->toContain('vote.submit')
        ->toContain('AuditKeyUnusable');
    foreach ([
        $e['candidates']['B'], $e['candidates']['I'], $e['candidates']['A'],
        $e['voters']['Wideline'], $e['codes']['Wideline'], str_replace('-', '', $e['codes']['Wideline']),
        $e['ballots']['P'], 'Wideline', 'The audit public key',
    ] as $secret) {
        expect($logged)->not->toContain($secret);
    }
    // No stack of calls, no file of the application.
    expect($logged)->not->toContain('RecordVote')->and($logged)->not->toContain('.php');
});

it('logs nothing of a code refused, a session refused or a rate limit [NFR-SEC-02]', function () {
    Accounts::reset();
    Voting::keypair();
    $e = Voting::election();
    $offsets = voteLogOffsets();

    $browser = Voting::browser($this);
    $browser->post("/api/v1/vote/{$e['election']}/session", ['code' => 'ZZZZZ-ZZZZZ'], [], false)->assertStatus(401);
    $browser->get("/api/v1/vote/{$e['election']}/ballots")->assertStatus(401);
    $browser->postRaw("/api/v1/vote/{$e['election']}/session", '{"code": "ZZZZZ-ZZZZZ"')->assertStatus(400);

    expect(voteLoggedSince($offsets))->not->toContain('ZZZZZ');
});

it('refuses a form post to the voting routes: the body must be JSON [NFR-SEC-04]', function () {
    Accounts::reset();
    Voting::keypair();
    $e = Voting::election();

    $this->call('POST', "/api/v1/vote/{$e['election']}/session", ['code' => $e['codes']['Wideline']], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'])
        ->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    $this->call('POST', "/api/v1/vote/{$e['election']}/session", [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], $e['codes']['Wideline'])
        ->assertStatus(400);
});

it('puts no cookie of the admin session on a voting answer [NFR-SEC-04]', function () {
    Accounts::reset();
    Voting::keypair();
    $e = Voting::election();

    $response = Voting::enter(Voting::browser($this), $e['election'], $e['codes']['Wideline'])->assertCreated();
    $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());

    expect($names)->toBe(['vote_session']);
});
