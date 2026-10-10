<?php

/*
 * DELETE /api/v1/groups/{group} — docs/api/groups/DELETE-groups-{group}.md
 * Tenant suite, route api/v1/groups/{group}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const GROUP_DELETE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function groupDeleteUrl(string $uuid): string
{
    return "/api/v1/groups/{$uuid}";
}

/** An owner of A signed in, an election of the given status and one empty group. Returns [team, election, group]. */
function groupDeleteSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Vide']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $group];
}

it('deletes a group with no voter and leaves the others alone [FR-VOT-08] (scenario 1)', function (string $status) {
    [$t, $election, $group] = groupDeleteSignedIn($this, $status);
    $stays = Accounts::plantGroup(['election' => $election, 'name' => 'Reste']);

    $this->browser->delete(groupDeleteUrl($group))->assertNoContent();

    expect(Accounts::groupRow($group))->toBeNull()
        ->and(Accounts::groupRow($stays))->not->toBeNull();
})->with(['draft', 'scheduled']);

it('answers 409 group_in_use when a voter is in the group, and keeps everything [FR-VOT-08] (scenario 2)', function () {
    [$t, $election, $group] = groupDeleteSignedIn($this);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Dedans', 'group' => $group]);

    $this->browser->delete(groupDeleteUrl($group))->assertStatus(409)->assertJsonPath('error.code', 'group_in_use');

    expect(Accounts::groupRow($group))->not->toBeNull()
        ->and(Accounts::voterRow($voter)['voter_group_id'])->toBe(Accounts::groupRow($group)['id']);
});

it('answers 409 election_voters_locked for an open, closed, published or archived election [FR-SEC-06] (scenario 3)', function (string $status) {
    [$t, $election, $group] = groupDeleteSignedIn($this, $status);

    $this->browser->delete(groupDeleteUrl($group))->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::groupRow($group))->not->toBeNull();
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a group that is unknown, deleted, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    [$t, $election, $gone] = groupDeleteSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'De B']);
    $this->browser->delete(groupDeleteUrl($gone))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(groupDeleteUrl(GROUP_DELETE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, $gone, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(groupDeleteUrl($target))))->toBe($unknown);
    }
    expect(Accounts::groupRow($foreign))->not->toBeNull();
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 5 to 9)', function () {
    [$t, $election, $group] = groupDeleteSignedIn($this);

    $this->browser->delete(groupDeleteUrl($group), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('POST', groupDeleteUrl($group))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->delete(groupDeleteUrl($group))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::groupRow($group))->not->toBeNull();
});

it('answers 401 when nobody is signed in [FR-VOT-08] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Seul']);

    $this->browser->delete(groupDeleteUrl($group))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 8)', function () {
    [$t, $election, $group] = groupDeleteSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->delete(groupDeleteUrl(GROUP_DELETE_UNKNOWN))->assertStatus(404);
    }

    $this->browser->delete(groupDeleteUrl($group))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::groupRow($group))->not->toBeNull();
});
