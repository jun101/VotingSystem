<?php

/*
 * What a group ballot changes for the groups of slice 07 (slice 09a): `ballots_count` of a group, the delete rule and
 * the merge. docs/api/groups/ (GET groups, DELETE group scenario 2, POST merge scenario 14).
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

/** An owner of A signed in and an election with groups A, B, C and one group ballot on A and B. */
function gbrSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $a = Accounts::plantGroup(['election' => $election, 'name' => 'A']);
    $b = Accounts::plantGroup(['election' => $election, 'name' => 'B']);
    $c = Accounts::plantGroup(['election' => $election, 'name' => 'C']);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué', 'groups' => [$a, $b]]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $a, $b, $c, $ballot];
}

it('gives the number of group ballots that cover each group [FR-BAL-03] (scenario 1)', function () {
    [$t, $election, $a, $b, $c] = gbrSignedIn($this);
    Accounts::plantBallot(['election' => $election, 'title' => 'Autre', 'groups' => [$a]]);
    Accounts::plantBallot(['election' => $election, 'title' => 'Général']);

    $groups = collect($this->browser->get("/api/v1/elections/{$election}/groups?per_page=100")->assertOk()->json('data'))->keyBy('id');

    expect($groups[$a]['ballots_count'])->toBe(2)
        ->and($groups[$b]['ballots_count'])->toBe(1)
        ->and($groups[$c]['ballots_count'])->toBe(0);
});

it('refuses to delete a group a ballot covers, even with no voter [FR-VOT-08] (scenario 2)', function () {
    [$t, $election, $a, $b, $c] = gbrSignedIn($this);

    $this->browser->delete("/api/v1/groups/{$a}")->assertStatus(409)->assertJsonPath('error.code', 'group_in_use');
    expect(Accounts::groupRow($a))->not->toBeNull();

    $this->browser->delete("/api/v1/groups/{$c}")->assertNoContent();
    expect(Accounts::groupRow($c))->toBeNull();
});

it('can delete the group again once the ballot no longer covers it [FR-VOT-08] (scenario 1)', function () {
    [$t, $election, $a, $b, $c, $ballot] = gbrSignedIn($this);
    $this->browser->patch("/api/v1/ballots/{$ballot}", ['groups' => [$b]])->assertOk();

    $this->browser->delete("/api/v1/groups/{$a}")->assertNoContent();

    expect(Accounts::groupRow($a))->toBeNull();
});

it('moves the ballots to the receiving group when a group is merged, once [FR-VOT-08] (scenario 14)', function () {
    [$t, $election, $a, $b, $c, $ballot] = gbrSignedIn($this);
    $onlyA = Accounts::plantBallot(['election' => $election, 'title' => 'Seulement A', 'groups' => [$a]]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Dans A', 'group' => $a]);

    // A into B: the ballot that covered A and B covers B once; the one that covered A only covers B.
    $response = $this->browser->post("/api/v1/groups/{$a}/merge", ['into' => $b])->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $b, 'voters_count' => 1, 'ballots_count' => 2])
        ->and(Accounts::ballotGroups($ballot))->toBe([$b])
        ->and(Accounts::ballotGroups($onlyA))->toBe([$b])
        ->and(Accounts::groupRow($a))->toBeNull();
});

it('merges a group into one no ballot covers, and the ballot follows [FR-VOT-08] (scenario 14)', function () {
    [$t, $election, $a, $b, $c, $ballot] = gbrSignedIn($this);

    $this->browser->post("/api/v1/groups/{$b}/merge", ['into' => $c])->assertOk()->assertJsonPath('data.ballots_count', 1);

    expect(Accounts::ballotGroups($ballot))->toEqualCanonicalizing([$a, $c]);
});

it('merges while the election is scheduled although its ballots are locked [FR-VOT-08] (scenario 14)', function () {
    [$t, $election, $a, $b, $c, $ballot] = gbrSignedIn($this, 'scheduled');

    $this->browser->post("/api/v1/groups/{$a}/merge", ['into' => $c])->assertOk();

    expect(Accounts::ballotGroups($ballot))->toEqualCanonicalizing([$b, $c]);
});
