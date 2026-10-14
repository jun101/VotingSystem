<?php

/*
 * POST /api/v1/elections/{election}/groups — docs/api/groups/POST-elections-{election}-groups.md
 * Tenant suite, route api/v1/elections/{election}/groups: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const GROUP_CREATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function groupCreateUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/groups";
}

function groupCreateSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('creates an empty group [FR-VOT-08] (scenario 1)', function (string $status) {
    [$t, $election] = groupCreateSignedIn($this, ['status' => $status]);

    $response = $this->browser->post(groupCreateUrl($election), ['name' => '  4e année  '])->assertCreated();

    $row = Accounts::groupRow($response->json('data.id'));
    expect($response->json('data'))->toMatchArray(['name' => '4e année', 'voters_count' => 0])
        ->and($response->headers->get('Location'))->toBe('/api/v1/groups/'.$response->json('data.id'))
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($row['election_id'])->toBe(Accounts::electionRow($election)['id']);
})->with(['draft', 'scheduled']);

it('answers 422 when name is missing, blank or too long [FR-VOT-08] (scenario 2)', function (array $body, string $rule) {
    [$t, $election] = groupCreateSignedIn($this);

    $response = $this->browser->post(groupCreateUrl($election), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.name'))->toContain($rule)
        ->and(Accounts::groupRows($election))->toBe([]);
})->with([
    'missing' => [[], 'required'],
    'blank' => [['name' => '  '], 'required'],
    'too long' => [['name' => str_repeat('é', 101)], 'max'],
]);

it('answers 422 for a name already used, ignoring case and spaces but not accents [FR-VOT-08] (scenario 3)', function () {
    [$t, $election] = groupCreateSignedIn($this);
    Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->post(groupCreateUrl($election), ['name' => ' 4E ANNÉE '])->assertStatus(422)->assertJsonPath('error.fields.name.0', 'unique');
    $this->browser->post(groupCreateUrl($election), ['name' => '4e annee'])->assertCreated();
    $this->browser->post(groupCreateUrl($other), ['name' => '4e année'])->assertCreated();
    expect(Accounts::groupRows($election))->toHaveCount(2);
});

it('answers 409 group_limit_reached at 100 groups [FR-VOT-08] (scenario 4)', function () {
    [$t, $election] = groupCreateSignedIn($this);
    foreach (range(1, 100) as $i) {
        Accounts::plantGroup(['election' => $election, 'name' => "Groupe {$i}"]);
    }

    $this->browser->post(groupCreateUrl($election), ['name' => 'Le 101e'])->assertStatus(409)->assertJsonPath('error.code', 'group_limit_reached');
    expect(Accounts::groupRows($election))->toHaveCount(100);
});

it('answers 409 election_voters_locked for an open, closed, published or archived election [FR-SEC-06] (scenario 5)', function (string $status) {
    [$t, $election] = groupCreateSignedIn($this, ['status' => $status]);

    $this->browser->post(groupCreateUrl($election), ['name' => 'Trop tard'])->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::groupRows($election))->toBe([]);
})->with(['open', 'closed', 'published', 'archived']);

it('checks the body before the state [FR-SEC-06] (scenario 5)', function () {
    [$t, $election] = groupCreateSignedIn($this, ['status' => 'open']);

    $this->browser->post(groupCreateUrl($election), ['name' => ''])->assertStatus(422);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(groupCreateUrl(GROUP_CREATE_UNKNOWN), ['name' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(groupCreateUrl($target), ['name' => 'X'])))->toBe($unknown);
    }
    expect(Accounts::groupRows($foreign))->toBe([]);
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenarios 7 to 12)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $this->browser->post(groupCreateUrl($election), ['name' => 'X'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(groupCreateUrl($election), ['name' => 'X'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('PATCH', groupCreateUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->rawBody('POST', groupCreateUrl($election), '{"name": ')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(groupCreateUrl($election), ['name' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::groupRows($election))->toBe([]);
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 10)', function () {
    [$t, $election] = groupCreateSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->post(groupCreateUrl($election), ['name' => ''])->assertStatus(422);
    }

    $this->browser->post(groupCreateUrl($election), ['name' => 'Trop'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::groupRows($election))->toBe([]);
});

it('accepts a 100-character name whose lower-cased key is longer [FR-VOT-08] (scenario 1)', function () {
    [$t, $election] = groupCreateSignedIn($this);
    $name = str_repeat('İ', 100);

    $response = $this->browser->post(groupCreateUrl($election), ['name' => $name])->assertCreated();

    expect($response->json('data.name'))->toBe($name);
});
