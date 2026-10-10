<?php

/*
 * Group scope of a ballot (slice 09a): POST /api/v1/elections/{election}/ballots, PATCH /api/v1/ballots/{ballot},
 * GET /api/v1/elections/{election}/ballots — docs/api/ballots/ (the `scope`, `groups`, `eligible_voters` and
 * `warnings` of docs/slices/09-scope-copy-schedule.md). The tenant suites of those routes stay in Slice06.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

/** An owner of A signed in, a draft election with two groups: "4e année" (6 voters) and "5e année" (2 voters). */
function scopeSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $fourth = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $fifth = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    foreach (range(1, 6) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => "Quatrième {$i}", 'group' => $fourth]);
    }
    foreach (range(1, 2) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => "Cinquième {$i}", 'group' => $fifth]);
    }
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $fourth, $fifth];
}

it('creates a group ballot and gives its groups, its eligible voters and no warning [FR-BAL-03] (scenario 15)', function () {
    [$t, $election, $fourth] = scopeSignedIn($this);

    $response = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Délégué 4e', 'scope' => 'group', 'groups' => [$fourth]])->assertCreated();

    expect($response->json('data'))->toMatchArray(['scope' => 'group', 'eligible_voters' => 6, 'warnings' => []])
        ->and($response->json('data.groups'))->toBe([['id' => $fourth, 'name' => '4e année', 'voters_count' => 6]])
        ->and(Accounts::ballotGroups($response->json('data.id')))->toBe([$fourth]);
});

it('keeps a general ballot general, with every voter eligible and no group [FR-BAL-03] (scenario 1)', function (array $body) {
    [$t, $election, $fourth] = scopeSignedIn($this);

    $response = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Président'] + $body)->assertCreated();

    expect($response->json('data'))->toMatchArray(['scope' => 'general', 'eligible_voters' => 9, 'warnings' => [], 'groups' => []])
        ->and(Accounts::ballotGroups($response->json('data.id')))->toBe([]);
})->with([
    'no scope' => [[]],
    'scope general' => [['scope' => 'general']],
    'groups ignored on a general ballot' => [['scope' => 'general', 'groups' => ['00000000-0000-4000-8000-000000000000']]],
]);

it('counts a repeated group once and several groups together [FR-BAL-05] (scenario 15)', function () {
    [$t, $election, $fourth, $fifth] = scopeSignedIn($this);

    $response = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Délégués', 'scope' => 'group', 'groups' => [$fourth, $fifth, $fourth]])->assertCreated();

    expect($response->json('data.eligible_voters'))->toBe(8)
        ->and($response->json('data.groups'))->toHaveCount(2)
        ->and(Accounts::ballotGroups($response->json('data.id')))->toHaveCount(2);
});

it('warns when a group ballot covers fewer than 5 voters, or none [FR-BAL-06] (scenario 1)', function () {
    [$t, $election, $fourth, $fifth] = scopeSignedIn($this);
    $empty = Accounts::plantGroup(['election' => $election, 'name' => 'Vide']);

    $few = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Cinquième', 'scope' => 'group', 'groups' => [$fifth]])->assertCreated();
    $none = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Personne', 'scope' => 'group', 'groups' => [$empty]])->assertCreated();
    $five = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Quatrième', 'scope' => 'group', 'groups' => [$fourth]])->assertCreated();

    expect($few->json('data.warnings'))->toBe(['few_voters'])
        ->and($none->json('data.warnings'))->toBe(['no_voters'])
        ->and($five->json('data.warnings'))->toBe([]);
});

it('lists scope, groups, eligible voters and warnings of every ballot [FR-BAL-03] (scenario 1)', function () {
    [$t, $election, $fourth, $fifth] = scopeSignedIn($this);
    $general = Accounts::plantBallot(['election' => $election, 'title' => 'Président']);
    $group = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué 5e', 'groups' => [$fifth]]);

    $data = $this->browser->get("/api/v1/elections/{$election}/ballots?per_page=100")->assertOk()->json('data');

    expect($data[0])->toMatchArray(['id' => $general, 'scope' => 'general', 'eligible_voters' => 9, 'groups' => [], 'warnings' => []])
        ->and($data[1])->toMatchArray(['id' => $group, 'scope' => 'group', 'eligible_voters' => 2, 'warnings' => ['few_voters']])
        ->and($data[1]['groups'])->toBe([['id' => $fifth, 'name' => '5e année', 'voters_count' => 2]]);
});

it('answers 422 for a group scope that is not valid [FR-BAL-03] (scenario 16)', function (string $name, array $body, string $field, string $rule) {
    [$t, $election, $fourth] = scopeSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'Chez B']);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre']);
    $elsewhere = Accounts::plantGroup(['election' => $otherElection, 'name' => 'Ailleurs']);
    $body = json_decode(str_replace(['FOREIGN', 'ELSEWHERE'], [$foreign, $elsewhere], json_encode($body)), true);

    $response = $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Poste'] + $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::ballotRows($election))->toBe([]);
})->with([
    'unknown scope' => ['scope', ['scope' => 'class'], 'scope', 'invalid'],
    'group scope without groups' => ['none', ['scope' => 'group'], 'groups', 'required'],
    'group scope with an empty list' => ['empty', ['scope' => 'group', 'groups' => []], 'groups', 'required'],
    'not a uuid' => ['uuid', ['scope' => 'group', 'groups' => ['4e']], 'groups', 'invalid'],
    'another institution\'s group' => ['foreign', ['scope' => 'group', 'groups' => ['FOREIGN']], 'groups', 'invalid'],
    'another election\'s group' => ['elsewhere', ['scope' => 'group', 'groups' => ['ELSEWHERE']], 'groups', 'invalid'],
]);

it('replaces the groups, or makes the ballot general again, with PATCH [FR-BAL-03] (scenarios 14, 15)', function () {
    [$t, $election, $fourth, $fifth] = scopeSignedIn($this);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué', 'groups' => [$fourth]]);

    $this->browser->patch("/api/v1/ballots/{$ballot}", ['groups' => [$fifth]])->assertOk()
        ->assertJsonPath('data.scope', 'group')->assertJsonPath('data.eligible_voters', 2);
    expect(Accounts::ballotGroups($ballot))->toBe([$fifth]);

    $this->browser->patch("/api/v1/ballots/{$ballot}", ['scope' => 'group', 'groups' => [$fourth, $fifth]])->assertOk()
        ->assertJsonPath('data.eligible_voters', 8);
    expect(Accounts::ballotGroups($ballot))->toHaveCount(2);

    $this->browser->patch("/api/v1/ballots/{$ballot}", ['scope' => 'general'])->assertOk()
        ->assertJsonPath('data.scope', 'general')->assertJsonPath('data.groups', [])->assertJsonPath('data.eligible_voters', 9);
    expect(Accounts::ballotGroups($ballot))->toBe([])
        ->and(Accounts::ballotRow($ballot)['scope'])->toBe('general');
});

it('turns a general ballot into a group ballot with PATCH, and refuses it without groups [FR-BAL-03] (scenarios 14, 16)', function () {
    [$t, $election, $fourth] = scopeSignedIn($this);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Président']);

    $response = $this->browser->patch("/api/v1/ballots/{$ballot}", ['scope' => 'group'])->assertStatus(422);
    expect($response->json('error.fields.groups'))->toContain('required')
        ->and(Accounts::ballotRow($ballot)['scope'])->toBe('general');

    $this->browser->patch("/api/v1/ballots/{$ballot}", ['scope' => 'group', 'groups' => [$fourth]])->assertOk()->assertJsonPath('data.scope', 'group');
    expect(Accounts::ballotGroups($ballot))->toBe([$fourth]);
});

it('does not touch the groups when PATCH sends other fields only [FR-BAL-03] (scenario 2)', function () {
    [$t, $election, $fourth] = scopeSignedIn($this);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué', 'groups' => [$fourth]]);

    $this->browser->patch("/api/v1/ballots/{$ballot}", ['title' => 'Délégué de classe'])->assertOk()->assertJsonPath('data.scope', 'group');

    expect(Accounts::ballotGroups($ballot))->toBe([$fourth]);
});

it('answers 409 election_not_editable for a scheduled election, as every ballot write [FR-BAL-03] (scenarios 7, 6)', function () {
    [$t, $election, $fourth] = scopeSignedIn($this, 'scheduled');
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué', 'groups' => [$fourth]]);

    $this->browser->post("/api/v1/elections/{$election}/ballots", ['title' => 'Nouveau', 'scope' => 'group', 'groups' => [$fourth]])
        ->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    $this->browser->patch("/api/v1/ballots/{$ballot}", ['groups' => []])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');

    expect(Accounts::ballotGroups($ballot))->toBe([$fourth]);
});
