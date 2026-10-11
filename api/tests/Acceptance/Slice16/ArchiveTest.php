<?php

/*
 * POST /api/v1/elections/{election}/archive and /unarchive — docs/api/publication/POST-elections-{election}-archive.md
 * Tenant suite, routes api/v1/elections/{election}/archive and api/v1/elections/{election}/unarchive: another
 * institution's election answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Publication;
use Tests\Support\Team;

function archiveUrl(string $uuid, string $action = 'archive'): string
{
    return "/api/v1/elections/{$uuid}/{$action}";
}

it('archives a closed election [FR-ELEC-03] (scenario 1)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'closed');
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post(archiveUrl($e['election']))->assertOk();

    $row = Accounts::electionRow($e['election']);
    expect($response->json('data.status'))->toBe('archived')
        ->and($row['status'])->toBe('archived')
        ->and($row['archived_at'])->not->toBeNull();
    $this->browser->get("/api/v1/elections/{$e['election']}/results")->assertOk();
});

it('archives a published election and takes it off the public pages [FR-ELEC-03] (scenario 2)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published');
    Team::signIn($this, $t['a']['owner']);
    $anonymous = new AuthClient($this);
    $anonymous->get("/api/v1/public/elections/{$e['election']}/results")->assertOk();

    $this->browser->post(archiveUrl($e['election']))->assertOk();

    $anonymous->get("/api/v1/public/elections/{$e['election']}/results")->assertStatus(404);
    $institution = $anonymous->get('/api/v1/public/institutions/'.$t['a']['owner']['institution'])->assertOk();
    expect($institution->json('data.elections'))->toBe([]);
});

it('answers 409 election_not_archivable for a draft, scheduled, open or archived election [FR-ELEC-03] (scenario 3)', function (string $status) {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], $status);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(archiveUrl($e['election']))->assertStatus(409)->assertJsonPath('error.code', 'election_not_archivable');
})->with(['draft', 'scheduled', 'open', 'archived']);

it('unarchives an archived election back to closed [FR-ELEC-03] (scenario 4)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'archived');
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post(archiveUrl($e['election'], 'unarchive'))->assertOk();

    $row = Accounts::electionRow($e['election']);
    expect($response->json('data.status'))->toBe('closed')
        ->and($row['archived_at'])->toBeNull()
        ->and($row['published_at'])->toBeNull();
});

it('answers 409 election_not_archived when unarchiving any other status [FR-ELEC-03] (scenario 5)', function (string $status) {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], $status);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(archiveUrl($e['election'], 'unarchive'))->assertStatus(409)->assertJsonPath('error.code', 'election_not_archived');
})->with(['draft', 'scheduled', 'open', 'closed', 'published']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function (string $action) {
    $t = Team::two();
    $foreign = Publication::election($t['b']['owner']['institution'], $action === 'archive' ? 'closed' : 'archived');
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(archiveUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa', $action)));
    expect($unknown['status'])->toBe(404);

    foreach ([$foreign['election'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(archiveUrl($target, $action))))->toBe($unknown);
    }
})->with(['archive', 'unarchive']);

it('answers 401, 403, 419 and 405 as every write, and lets a manager archive [NFR-SEC-04] (scenario 7)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'closed');
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(archiveUrl($e['election']), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', archiveUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->post(archiveUrl($e['election']))->assertOk();
    $this->browser->forgetCookies();
    $this->browser->post(archiveUrl($e['election'], 'unarchive'))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(archiveUrl($e['election'], 'unarchive'))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
