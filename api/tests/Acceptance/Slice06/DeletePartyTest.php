<?php

/*
 * DELETE /api/v1/parties/{party} — docs/api/parties/DELETE-parties-{party}.md
 * Tenant suite, route api/v1/parties/{party}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PARTY_DELETE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

/** An owner of A signed in, a draft (or the given status) and one party in it. Returns [team, election, party]. */
function partyDeleteSignedIn($test, string $status = 'draft', array $party = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $id = Accounts::plantParty($party + ['election' => $election, 'name' => 'Avant', 'acronym' => 'AV', 'colour' => '#5468D4']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $id];
}

function partyDeleteUrl(string $uuid): string
{
    return "/api/v1/parties/{$uuid}";
}

it('deletes a party of a draft and leaves the others alone [FR-CAND-01] (scenario 1)', function () {
    [$t, $election, $id] = partyDeleteSignedIn($this);
    $kept = Accounts::plantParty(['election' => $election, 'name' => 'Reste']);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $elsewhere = Accounts::plantParty(['election' => $other, 'name' => 'Ailleurs']);

    $this->browser->delete(partyDeleteUrl($id))->assertNoContent();

    expect(Accounts::partyRow($id))->toBeNull()
        ->and(Accounts::partyRow($kept))->not->toBeNull()
        ->and(Accounts::partyRow($elsewhere))->not->toBeNull();
});

it('lets a manager delete a party too [FR-CAND-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantParty(['election' => $election]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(partyDeleteUrl($id))->assertNoContent();
});

it('frees the name for a new party [FR-CAND-01] (scenario 1)', function () {
    [$t, $election, $id] = partyDeleteSignedIn($this);

    $this->browser->delete(partyDeleteUrl($id))->assertNoContent();

    $this->browser->post("/api/v1/elections/{$election}/parties", ['name' => 'Avant', 'colour' => '#5468D4'])->assertCreated();
});

it('answers 409 for an election that is not a draft, and keeps the party [FR-SEC-06] (scenario 2)', function (string $status) {
    [$t, $election, $id] = partyDeleteSignedIn($this, $status);

    $this->browser->delete(partyDeleteUrl($id))->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::partyRow($id))->not->toBeNull();
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a party that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantParty(['election' => $foreignElection, 'name' => 'De B']);
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $gone = Accounts::plantParty(['election' => $election]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(partyDeleteUrl($gone))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(partyDeleteUrl(PARTY_DELETE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(partyDeleteUrl($target))))->toBe($unknown);
    }
    expect(Accounts::partyRow($foreign)['name'])->toBe('De B');
});

it('answers 401 when nobody is signed in [FR-CAND-01] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantParty(['election' => $election]);

    $this->browser->delete(partyDeleteUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::partyRow($id))->not->toBeNull();
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    [$t, $election, $id] = partyDeleteSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(partyDeleteUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::partyRow($id))->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 6)', function () {
    [$t, $election, $id] = partyDeleteSignedIn($this);

    $this->browser->delete(partyDeleteUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::partyRow($id))->not->toBeNull();
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    [$t, $election, $first] = partyDeleteSignedIn($this);
    $ids = [$first];
    foreach (range(2, 121) as $i) {
        $ids[] = Accounts::plantParty(['election' => $election, 'name' => "Parti {$i}"]);
    }

    foreach (array_slice($ids, 0, 120) as $id) {
        $this->browser->delete(partyDeleteUrl($id))->assertNoContent();
    }

    $this->browser->delete(partyDeleteUrl($ids[120]))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::partyRow($ids[120]))->not->toBeNull();
});

it('answers 405 for another method than PATCH and DELETE [FR-CAND-01] (scenario 8)', function (string $method) {
    [$t, $election, $id] = partyDeleteSignedIn($this);

    $this->browser->other($method, partyDeleteUrl($id))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(Accounts::partyRow($id))->not->toBeNull();
})->with(['GET', 'POST', 'PUT']);
