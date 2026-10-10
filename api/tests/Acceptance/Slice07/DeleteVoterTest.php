<?php

/*
 * DELETE /api/v1/voters/{voter} — docs/api/voters/DELETE-voters-{voter}.md
 * Tenant suite, route api/v1/voters/{voter}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const VOTER_DELETE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function voterDeleteUrl(string $uuid): string
{
    return "/api/v1/voters/{$uuid}";
}

/** An owner of A signed in, an election of the given status, a group and one voter in it. Returns [team, election, voter, group]. */
function voterDeleteSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Seul groupe']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Partant', 'group' => $group]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $voter, $group];
}

it('deletes a voter of a draft or scheduled election and keeps its group [FR-VOT-06] (scenario 1)', function (string $status) {
    [$t, $election, $voter, $group] = voterDeleteSignedIn($this, $status);
    $stays = Accounts::plantVoter(['election' => $election, 'full_name' => 'Reste']);

    $this->browser->delete(voterDeleteUrl($voter))->assertNoContent();

    expect(Accounts::voterRow($voter))->toBeNull()
        ->and(Accounts::voterRow($stays))->not->toBeNull()
        ->and(Accounts::groupRow($group))->not->toBeNull();
})->with(['draft', 'scheduled']);

it('answers 409 election_voters_locked for an open, closed, published or archived election [FR-SEC-06] (scenario 2)', function (string $status) {
    [$t, $election, $voter] = voterDeleteSignedIn($this, $status);

    $this->browser->delete(voterDeleteUrl($voter))->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::voterRow($voter))->not->toBeNull();
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a voter that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantVoter(['election' => $foreignElection, 'full_name' => 'De B']);
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $gone = Accounts::plantVoter(['election' => $election, 'full_name' => 'Parti']);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->delete(voterDeleteUrl($gone))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(voterDeleteUrl(VOTER_DELETE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(voterDeleteUrl($target))))->toBe($unknown);
    }
    expect(Accounts::voterRow($foreign))->not->toBeNull();
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 4 to 8)', function () {
    [$t, $election, $voter] = voterDeleteSignedIn($this);

    $this->browser->delete(voterDeleteUrl($voter), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('POST', voterDeleteUrl($voter))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->delete(voterDeleteUrl($voter))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::voterRow($voter))->not->toBeNull();
});

it('answers 401 when nobody is signed in [FR-VOT-06] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Reste']);

    $this->browser->delete(voterDeleteUrl($voter))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::voterRow($voter))->not->toBeNull();
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    [$t, $election, $voter] = voterDeleteSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->delete(voterDeleteUrl(VOTER_DELETE_UNKNOWN))->assertStatus(404);
    }

    $this->browser->delete(voterDeleteUrl($voter))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::voterRow($voter))->not->toBeNull();
});
