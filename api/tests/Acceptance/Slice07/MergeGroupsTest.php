<?php

/*
 * POST /api/v1/groups/{group}/merge — docs/api/groups/POST-groups-{group}-merge.md
 * Tenant suite, route api/v1/groups/{group}/merge: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const GROUP_MERGE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function groupMergeUrl(string $uuid): string
{
    return "/api/v1/groups/{$uuid}/merge";
}

/** An owner of A signed in, an election of the given status with groups "4e année" (a, 2 voters) and "5e année" (b, 1 voter). Returns [team, election, a, b, [voters of a], voter of b]. */
function groupMergeSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $a = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $b = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    $inA = [
        Accounts::plantVoter(['election' => $election, 'full_name' => 'A un', 'group' => $a]),
        Accounts::plantVoter(['election' => $election, 'full_name' => 'A deux', 'group' => $a]),
    ];
    $inB = Accounts::plantVoter(['election' => $election, 'full_name' => 'B un', 'group' => $b]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $a, $b, $inA, $inB];
}

it('moves the voters into the other group and deletes the merged one [FR-VOT-08] (scenario 1)', function (string $status) {
    [$t, $election, $a, $b, $inA, $inB] = groupMergeSignedIn($this, $status);

    $response = $this->browser->post(groupMergeUrl($a), ['into' => $b])->assertOk();

    $target = Accounts::groupRow($b);
    expect($response->json('data'))->toMatchArray(['id' => $b, 'name' => '5e année', 'voters_count' => 3])
        ->and(Accounts::groupRow($a))->toBeNull()
        ->and(Accounts::voterRow($inA[0])['voter_group_id'])->toBe($target['id'])
        ->and(Accounts::voterRow($inA[1])['voter_group_id'])->toBe($target['id'])
        ->and(Accounts::voterRow($inB)['voter_group_id'])->toBe($target['id'])
        ->and(Accounts::groupRows($election))->toHaveCount(1);
})->with(['draft', 'scheduled']);

it('removes a group with no voter by merging it [FR-VOT-08] (scenario 2)', function () {
    [$t, $election, $a, $b] = groupMergeSignedIn($this);
    $empty = Accounts::plantGroup(['election' => $election, 'name' => 'Vide']);

    $this->browser->post(groupMergeUrl($empty), ['into' => $a])->assertOk()->assertJsonPath('data.voters_count', 2);

    expect(Accounts::groupRow($empty))->toBeNull();
});

it('answers 422 when into is missing or not a UUID [FR-VOT-08] (scenario 3)', function (array $body, string $rule) {
    [$t, $election, $a] = groupMergeSignedIn($this);

    $response = $this->browser->post(groupMergeUrl($a), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.into'))->toContain($rule)
        ->and(Accounts::groupRow($a))->not->toBeNull();
})->with([
    'missing' => [[], 'required'],
    'not a uuid' => [['into' => '12'], 'uuid'],
]);

it('answers 422 into: same when the group is merged into itself [FR-VOT-08] (scenario 4)', function () {
    [$t, $election, $a, $b, $inA] = groupMergeSignedIn($this);

    $response = $this->browser->post(groupMergeUrl($a), ['into' => $a]);

    $response->assertStatus(422);
    expect($response->json('error.fields.into'))->toContain('same')
        ->and(Accounts::groupRow($a))->not->toBeNull()
        ->and(Accounts::voterRow($inA[0])['voter_group_id'])->toBe(Accounts::groupRow($a)['id']);
});

it('answers the same 422 into: invalid for a group that is unknown, of another election or of another institution [FR-INST-05] (scenario 5)', function () {
    [$t, $election, $a] = groupMergeSignedIn($this);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $elsewhere = Accounts::plantGroup(['election' => $otherElection, 'name' => 'Ailleurs']);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'De B']);

    $shapes = [];
    foreach ([GROUP_MERGE_UNKNOWN, $elsewhere, $foreign] as $into) {
        $response = $this->browser->post(groupMergeUrl($a), ['into' => $into]);
        $response->assertStatus(422);
        expect($response->json('error.fields.into'))->toContain('invalid');
        $shapes[] = Team::shape($response);
    }
    expect($shapes[1])->toBe($shapes[0])->and($shapes[2])->toBe($shapes[0])
        ->and(Accounts::groupRow($a))->not->toBeNull();
});

it('answers 409 election_voters_locked for an open, closed, published or archived election [FR-SEC-06] (scenario 6)', function (string $status) {
    [$t, $election, $a, $b] = groupMergeSignedIn($this, $status);

    $this->browser->post(groupMergeUrl($a), ['into' => $b])->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::groupRow($a))->not->toBeNull();
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a group that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    [$t, $election, $a, $b] = groupMergeSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'De B']);

    $unknown = Team::shape($this->browser->post(groupMergeUrl(GROUP_MERGE_UNKNOWN), ['into' => $b]));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(groupMergeUrl($target), ['into' => $b])))->toBe($unknown);
    }
    expect(Accounts::groupRow($foreign))->not->toBeNull();
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenarios 8 to 13)', function () {
    [$t, $election, $a, $b] = groupMergeSignedIn($this);

    $this->browser->post(groupMergeUrl($a), ['into' => $b], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('PATCH', groupMergeUrl($a))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->rawBody('POST', groupMergeUrl($a), '{"into": ')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(groupMergeUrl($a), ['into' => $b])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::groupRow($a))->not->toBeNull();
});

it('answers 401 when nobody is signed in [FR-VOT-08] (scenario 8)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $a = Accounts::plantGroup(['election' => $election, 'name' => 'A']);
    $b = Accounts::plantGroup(['election' => $election, 'name' => 'B']);

    $this->browser->post(groupMergeUrl($a), ['into' => $b])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 11)', function () {
    [$t, $election, $a, $b] = groupMergeSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->post(groupMergeUrl($a), [])->assertStatus(422);
    }

    $this->browser->post(groupMergeUrl($a), ['into' => $b])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::groupRow($a))->not->toBeNull();
});
