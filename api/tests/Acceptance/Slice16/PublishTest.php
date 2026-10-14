<?php

/*
 * POST /api/v1/elections/{election}/publish — docs/api/publication/POST-elections-{election}-publish.md
 * Tenant suite, route api/v1/elections/{election}/publish: another institution's election answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Publication;
use Tests\Support\Results;
use Tests\Support\Team;

const PUBLISH_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function publishUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/publish";
}

/** An owner of A signed in and a finished election of A. Returns [team, data]. */
function publishSignedIn($test, string $status = 'closed', array $options = []): array
{
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], $status, 5, 3, 2, $options);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $e];
}

it('publishes a closed election whose check passes and makes it public [FR-RES-04] (scenario 1)', function () {
    [$t, $e] = publishSignedIn($this);

    $response = $this->browser->post(publishUrl($e['election']))->assertOk();

    $row = Accounts::electionRow($e['election']);
    expect($response->json('data'))->toMatchArray(['id' => $e['election'], 'status' => 'published'])
        ->and($row['status'])->toBe('published')
        ->and($row['published_at'])->not->toBeNull()
        ->and(abs(strtotime($row['published_at'].' UTC') - now('UTC')->timestamp))->toBeLessThan(10);
    $anonymous = new Tests\Support\AuthClient($this);
    $anonymous->get("/api/v1/public/elections/{$e['election']}/results")->assertOk();
    $anonymous->get("/api/v1/vote/{$e['election']}")->assertOk()->assertJsonPath('data.results', "/results/{$e['election']}");
});

it('lets a manager publish [FR-RES-04] (scenario 1)', function () {
    [$t, $e] = publishSignedIn($this);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(publishUrl($e['election']))->assertOk()->assertJsonPath('data.status', 'published');
});

it('uses and keeps the results_display given with the publication [FR-RES-08] (scenario 2)', function () {
    [$t, $e] = publishSignedIn($this);

    $this->browser->post(publishUrl($e['election']), ['results_display' => 'winners'])->assertOk()->assertJsonPath('data.results_display', 'winners');

    expect(Accounts::electionRow($e['election'])['results_display'])->toBe('winners');
});

it('publishes a tie as a tie without breaking it [FR-RES-03] (scenario 3)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'closed', 4, 4, 0);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(publishUrl($e['election']))->assertOk();

    $public = (new Tests\Support\AuthClient($this))->get("/api/v1/public/elections/{$e['election']}/results")->assertOk()->json('data.ballots.0');
    expect($public['winners'])->toBe([])->and($public['tie']['candidates'])->toHaveCount(2);
});

it('refuses with 409 results_check_failed when a ballot\'s papers differ from its voters, and lists the ballots [FR-SEC-05] (scenario 4)', function () {
    [$t, $e] = publishSignedIn($this);
    Results::paper($e['ballots']['P'], [$e['candidates']['A']], 2);

    $response = $this->browser->post(publishUrl($e['election']))->assertStatus(409);

    expect($response->json('error.code'))->toBe('results_check_failed')
        ->and($response->json('error.ballots'))->toBe([$e['ballots']['P']])
        ->and(Accounts::electionRow($e['election'])['status'])->toBe('closed');
});

it('answers 409 election_not_closed for every other status [FR-ELEC-03] (scenario 5)', function (string $status) {
    [$t, $e] = publishSignedIn($this, $status);

    $this->browser->post(publishUrl($e['election']))->assertStatus(409)->assertJsonPath('error.code', 'election_not_closed');

    expect(Accounts::electionRow($e['election'])['status'])->toBe($status);
})->with(['draft', 'scheduled', 'open', 'published', 'archived']);

it('answers 422 for a results_display that is not full or winners [FR-RES-08] (scenario 6)', function () {
    [$t, $e] = publishSignedIn($this);

    $this->browser->post(publishUrl($e['election']), ['results_display' => 'counts'])->assertStatus(422)->assertJsonPath('error.fields.results_display.0', 'invalid');

    expect(Accounts::electionRow($e['election'])['status'])->toBe('closed');
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreign = Publication::election($t['b']['owner']['institution']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(publishUrl(PUBLISH_UNKNOWN)));
    expect($unknown['status'])->toBe(404)->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign['election'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(publishUrl($target))))->toBe($unknown);
    }
    expect(Accounts::electionRow($foreign['election'])['status'])->toBe('closed');
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenario 8)', function () {
    [$t, $e] = publishSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->post(publishUrl($e['election']))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(publishUrl($e['election']), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', publishUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(publishUrl($e['election']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($e['election'])['status'])->toBe('closed');
});

it('answers 429 above 20 requests an hour from one user, publish and unpublish counted together [NFR-SEC-05] (scenario 8)', function () {
    [$t, $e] = publishSignedIn($this);
    foreach (range(1, 10) as $i) {
        $this->browser->post(publishUrl($e['election']), ['results_display' => 'x'])->assertStatus(422);
        $this->browser->post("/api/v1/elections/{$e['election']}/unpublish")->assertStatus(409);
    }

    $this->browser->post(publishUrl($e['election']))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
