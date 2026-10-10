<?php

/*
 * GET /api/v1/elections/{election}/groups — docs/api/groups/GET-elections-{election}-groups.md
 * Tenant suite, route api/v1/elections/{election}/groups: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const GROUP_LIST_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function groupListUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/groups";
}

function groupListSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('lists the groups by name with their voter counts and the totals [FR-VOT-08] (scenario 1)', function () {
    [$t, $election] = groupListSignedIn($this);
    $b = Accounts::plantGroup(['election' => $election, 'name' => 'Terminale A']);
    $a = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Un', 'group' => $a]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Deux', 'group' => $a]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Trois', 'group' => $b]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe']);
    // Another election's voters never count.
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $other, 'full_name' => 'Ailleurs']);

    $response = $this->browser->get(groupListUrl($election).'?per_page=100')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$a, $b])
        ->and($response->json('data.0'))->toMatchArray(['id' => $a, 'name' => '4e année', 'voters_count' => 2])
        ->and($response->json('data.1.voters_count'))->toBe(1)
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'name', 'voters_count', 'created_at', 'updated_at'])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 100, 'total' => 2, 'voters_total' => 4, 'ungrouped' => 1]);
});

it('answers an empty list with the totals for an election with no group [FR-VOT-08] (scenario 2)', function () {
    [$t, $election] = groupListSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Seul']);

    $response = $this->browser->get(groupListUrl($election))->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0)
        ->and($response->json('meta.voters_total'))->toBe(1)
        ->and($response->json('meta.ungrouped'))->toBe(1);
});

it('lists the groups of an election that is not a draft [FR-VOT-08] (scenario 3)', function (string $status) {
    [$t, $election] = groupListSignedIn($this, $status);
    Accounts::plantGroup(['election' => $election, 'name' => 'Lu']);

    $this->browser->get(groupListUrl($election))->assertOk()->assertJsonPath('meta.total', 1);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Accounts::plantGroup(['election' => $foreign, 'name' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(groupListUrl(GROUP_LIST_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(groupListUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 [FR-VOT-08] (scenarios 5 to 7)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(groupListUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    Team::signIn($this, $t['a']['owner']);
    $this->browser->other('PATCH', groupListUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(groupListUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
