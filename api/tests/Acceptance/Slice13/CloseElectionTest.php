<?php

/*
 * POST /api/v1/elections/{election}/close — docs/api/elections/POST-elections-{election}-close.md
 * Tenant suite, route api/v1/elections/{election}/close: another institution's election answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Voting;

const CLOSE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';
const CLOSE_TITLE = 'Conseil des élèves 2026';

function closeUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/close";
}

/** An owner of A signed in and an open election of A (title "Conseil des élèves 2026"). Returns [team, voting data]. */
function closeSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $e = Voting::election($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $e];
}

it('closes an open election with the right title and sets closed_at [FR-ELEC-03, FR-ELEC-05] (scenario 1)', function () {
    [$t, $e] = closeSignedIn($this);

    $response = $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertOk();

    $row = Accounts::electionRow($e['election']);
    expect($response->json('data'))->toMatchArray(['id' => $e['election'], 'status' => 'closed'])
        ->and($row['status'])->toBe('closed')
        ->and($row['closed_at'])->not->toBeNull()
        ->and(abs(strtotime($row['closed_at'].' UTC') - now('UTC')->timestamp))->toBeLessThan(10);
});

it('lets a manager close too [FR-ELEC-05] (scenario 1)', function () {
    [$t, $e] = closeSignedIn($this);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertOk()->assertJsonPath('data.status', 'closed');
});

it('accepts the title in other case and with spaces around it [FR-ELEC-05] (scenario 2)', function () {
    [$t, $e] = closeSignedIn($this);

    $this->browser->post(closeUrl($e['election']), ['confirmation' => '  CONSEIL DES ÉLÈVES 2026 '])->assertOk()->assertJsonPath('data.status', 'closed');
});

it('answers 422 for a title that does not match (accents count), and for none, and changes nothing [FR-ELEC-05] (scenario 3)', function (array $body, string $code) {
    [$t, $e] = closeSignedIn($this);

    $response = $this->browser->post(closeUrl($e['election']), $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.fields.confirmation'))->toContain($code)
        ->and(Accounts::electionRow($e['election'])['status'])->toBe('open')
        ->and(Accounts::electionRow($e['election'])['closed_at'])->toBeNull();
})->with([
    'another title' => [['confirmation' => 'Autre élection'], 'mismatch'],
    'accents left out' => [['confirmation' => 'Conseil des eleves 2026'], 'mismatch'],
    'empty' => [['confirmation' => ''], 'required'],
    'missing' => [[], 'required'],
]);

it('answers 409 election_not_open for a draft, scheduled, closed, published or archived election [FR-ELEC-03] (scenario 4)', function (string $status) {
    [$t, $e] = closeSignedIn($this, ['status' => $status]);

    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');

    expect(Accounts::electionRow($e['election'])['status'])->toBe($status);
})->with(['draft', 'scheduled', 'closed', 'published', 'archived']);

it('closing twice answers 409 the second time and leaves closed_at as it was [FR-ELEC-03] (scenario 4)', function () {
    [$t, $e] = closeSignedIn($this);
    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertOk();
    $first = Accounts::electionRow($e['election'])['closed_at'];

    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');

    expect(Accounts::electionRow($e['election'])['closed_at'])->toBe($first);
});

it('stops a voter\'s session in progress: the next call answers 409 election_not_open [FR-VOTE-02] (scenario 5)', function () {
    [$t, $e] = closeSignedIn($this);
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $voter->get("/api/v1/vote/{$e['election']}/ballots")->assertOk();

    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertOk();

    $voter->get("/api/v1/vote/{$e['election']}/ballots")->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');
    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');
    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
});

it('keeps the votes already cast and reads none of them [FR-SEC-01] (scenario 1)', function () {
    [$t, $e] = closeSignedIn($this);
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertCreated();
    $before = Voting::counts($e['election']);

    $response = $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertOk();

    expect(Voting::counts($e['election']))->toBe($before)
        ->and(json_encode($response->json('data')))->not->toContain('vote_choices');
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    $foreign = Voting::election(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(closeUrl(CLOSE_UNKNOWN), ['confirmation' => CLOSE_TITLE]));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign['election'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(closeUrl($target), ['confirmation' => CLOSE_TITLE])))->toBe($unknown);
    }
    expect(Accounts::electionRow($foreign['election'])['status'])->toBe('open');
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenario 7)', function () {
    [$t, $e] = closeSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', closeUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(closeUrl($e['election']), ['confirmation' => CLOSE_TITLE])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($e['election'])['status'])->toBe('open');
});

it('answers 429 above 20 requests an hour from one user, close and extend counted together [NFR-SEC-05] (scenario 7)', function () {
    [$t, $e] = closeSignedIn($this);
    foreach (range(1, 10) as $i) {
        $this->browser->post(closeUrl($e['election']), ['confirmation' => 'non'])->assertStatus(422);
        $this->browser->post("/api/v1/elections/{$e['election']}/extend", ['ends_at' => 'non'])->assertStatus(422);
    }

    $this->browser->post(closeUrl($e['election']), ['confirmation' => 'non'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::electionRow($e['election'])['status'])->toBe('open');
});
