<?php

/*
 * GET /api/v1/elections/{election}/parties — docs/api/parties/GET-elections-{election}-parties.md
 * Tenant suite, route api/v1/elections/{election}/parties: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PARTY_LIST_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function partyListUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/elections/{$uuid}/parties{$query}";
}

function partyListSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('lists the parties by name with the documented fields [FR-CAND-01] (scenario 1)', function () {
    [$t, $election] = partyListSignedIn($this);
    $b = Accounts::plantParty(['election' => $election, 'name' => 'Ensemble', 'acronym' => 'EN', 'colour' => '#C2410C']);
    $a = Accounts::plantParty(['election' => $election, 'name' => 'avenir Étudiant', 'colour' => '#5468D4']);

    $response = $this->browser->get(partyListUrl($election))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$a, $b])
        ->and($response->json('data.1'))->toMatchArray(['id' => $b, 'name' => 'Ensemble', 'acronym' => 'EN', 'colour' => '#C2410C', 'logo' => null, 'candidates_count' => 0])
        ->and(array_keys($response->json('data.1')))->toEqualCanonicalizing(['id', 'name', 'acronym', 'colour', 'logo', 'candidates_count', 'created_at', 'updated_at'])
        ->and($response->json('meta.total'))->toBe(2);
});

it('answers an empty list for an election with no party [FR-CAND-01] (scenario 2)', function () {
    [$t, $election] = partyListSignedIn($this);

    $this->browser->get(partyListUrl($election))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});

it('lists the parties of an election that is not a draft too [FR-CAND-01] (scenario 3)', function (string $status) {
    [$t, $election] = partyListSignedIn($this, $status);
    Accounts::plantParty(['election' => $election]);

    $this->browser->get(partyListUrl($election))->assertOk()->assertJsonPath('meta.total', 1);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('never lists the parties of another election or institution [FR-INST-05] (scenario 1)', function () {
    [$t, $election] = partyListSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $mine = Accounts::plantParty(['election' => $election]);
    Accounts::plantParty(['election' => $other]);
    Accounts::plantParty(['election' => $foreign]);

    $response = $this->browser->get(partyListUrl($election))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$mine]);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Accounts::plantParty(['election' => $foreign]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(partyListUrl(PARTY_LIST_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(partyListUrl($target))))->toBe($unknown);
    }
});

it('answers 401 when nobody is signed in [FR-CAND-01] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(partyListUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    [$t, $election] = partyListSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(partyListUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 for another method [FR-CAND-01] (scenario 7)', function (string $method) {
    [$t, $election] = partyListSignedIn($this);

    $this->browser->other($method, partyListUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PUT', 'PATCH', 'DELETE']);

it('outputs no numeric id or foreign key [NFR-SEC-08] (scenario 1)', function () {
    [$t, $election] = partyListSignedIn($this);
    Accounts::plantParty(['election' => $election]);

    $item = $this->browser->get(partyListUrl($election))->assertOk()->json('data.0');

    expect($item)->not->toHaveKeys(['election_id', 'institution_id', 'election', 'institution', 'logo_file', 'name_key'])
        ->and(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $item['id']))->toBe(1);
});
