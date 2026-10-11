<?php

/*
 * Slice 09 review round: list limits on `groups`, `groups` ignored for a general ballot, a draft election with a group
 * ballot deleted, a group ballot duplicated as a general one. Endpoint files: docs/api/ballots/,
 * docs/api/voters/POST-elections-{election}-voter-copies.md, docs/api/elections/DELETE-elections-{election}.md,
 * docs/api/elections/POST-elections-{election}-duplicate.md.
 */

use Illuminate\Support\Str;
use Tests\Support\Accounts;
use Tests\Support\Team;

it('answers 422 groups invalid, before any query, for a very long list of groups on a ballot [NFR-SEC-02] (review 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $many = array_map(fn () => (string) Str::uuid(), range(1, 70000));

    $response = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Trop', 'scope' => 'group', 'groups' => $many])->assertStatus(422);

    expect($response->json('error.fields.groups'))->toContain('invalid')
        ->and(Accounts::ballotRows($election))->toBe([]);
});

it('answers 422 groups invalid for a very long list of groups on a voter copy [NFR-SEC-02] (review 9)', function () {
    $t = Team::two();
    $institution = $t['a']['owner']['institution'];
    $source = Accounts::plantElection(['institution' => $institution, 'title' => 'Avant']);
    $target = Accounts::plantElection(['institution' => $institution, 'title' => 'Maintenant']);
    Team::signIn($this, $t['a']['owner']);
    $many = array_map(fn () => (string) Str::uuid(), range(1, 70000));

    $response = $this->browser->post("/api/v1/elections/{$target}/voter-copies", ['source' => $source, 'groups' => $many])->assertStatus(422);

    expect($response->json('error.fields.groups'))->toContain('invalid')
        ->and(Accounts::voterRows($target))->toBe([]);
});

it('ignores groups for a general ballot, with or without the scope [FR-BAL-03] (review 6)', function (array $body) {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Président'] + $body)->assertCreated();

    expect($response->json('data.scope'))->toBe('general')
        ->and($response->json('data.groups'))->toBe([])
        ->and(Accounts::ballotGroups($response->json('data.id')))->toBe([]);
})->with([
    'no scope, not uuids' => [['groups' => [5, 'x']]],
    'scope general, not uuids' => [['scope' => 'general', 'groups' => ['4e']]],
]);

it('deletes a draft election that has a group ballot [FR-ELEC-03] (review 2)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose', 'group' => $group]);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué', 'groups' => [$group]]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete("/api/v1/elections/{$election}")->assertNoContent();

    expect(Accounts::electionRow($election))->toBeNull()
        ->and(Accounts::ballotRow($ballot))->toBeNull()
        ->and(Accounts::groupRow($group))->toBeNull();
});

it('duplicates a group ballot as a general ballot with no group [FR-ELEC-06] (review 4)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'closed']);
    $group = Accounts::plantGroup(['election' => $source, 'name' => '6e A']);
    Accounts::plantBallot(['election' => $source, 'title' => 'Délégué 6e A', 'groups' => [$group]]);
    Team::signIn($this, $t['a']['owner']);

    $copy = $this->browser->post("/api/v1/elections/{$source}/duplicate", [])->assertCreated()->json('data.id');

    $rows = Accounts::ballotRows($copy);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('Délégué 6e A')
        ->and($rows[0]['scope'])->toBe('general')
        ->and(Accounts::ballotGroups($rows[0]['uuid']))->toBe([]);
});
