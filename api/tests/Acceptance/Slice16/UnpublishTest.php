<?php

/*
 * POST /api/v1/elections/{election}/unpublish — docs/api/publication/POST-elections-{election}-unpublish.md
 * Tenant suite, route api/v1/elections/{election}/unpublish: another institution's election answers 404, the same as
 * an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Publication;
use Tests\Support\Team;

function unpublishUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/unpublish";
}

it('takes a published election back to closed and the public pages answer 404 at once [FR-ELEC-03] (scenario 1)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published');
    Team::signIn($this, $t['a']['owner']);
    $anonymous = new AuthClient($this);
    $anonymous->get("/api/v1/public/elections/{$e['election']}/results")->assertOk();

    $response = $this->browser->post(unpublishUrl($e['election']))->assertOk();

    $row = Accounts::electionRow($e['election']);
    expect($response->json('data.status'))->toBe('closed')
        ->and($row['status'])->toBe('closed')
        ->and($row['published_at'])->toBeNull();
    $anonymous->get("/api/v1/public/elections/{$e['election']}/results")->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    $anonymous->get("/api/v1/vote/{$e['election']}")->assertOk()->assertJsonPath('data.results', null);
});

it('answers 409 election_not_published for any other status [FR-ELEC-03] (scenario 2)', function (string $status) {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], $status);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(unpublishUrl($e['election']))->assertStatus(409)->assertJsonPath('error.code', 'election_not_published');
})->with(['draft', 'scheduled', 'open', 'closed', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreign = Publication::election($t['b']['owner']['institution'], 'published');
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(unpublishUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404);

    foreach ([$foreign['election'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(unpublishUrl($target))))->toBe($unknown);
    }
    expect(Accounts::electionRow($foreign['election'])['status'])->toBe('published');
});

it('answers 401, 403, 419 and 405 as every write, and lets a manager unpublish [NFR-SEC-04] (scenario 4)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published');
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(unpublishUrl($e['election']), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', unpublishUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->post(unpublishUrl($e['election']))->assertOk();
    $this->browser->forgetCookies();
    $this->browser->post(unpublishUrl($e['election']))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(unpublishUrl($e['election']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
