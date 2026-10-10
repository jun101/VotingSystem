<?php

/*
 * PATCH /api/v1/groups/{group} — docs/api/groups/PATCH-groups-{group}.md
 * Tenant suite, route api/v1/groups/{group}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const GROUP_UPDATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function groupUpdateUrl(string $uuid): string
{
    return "/api/v1/groups/{$uuid}";
}

/** An owner of A signed in, an election of the given status with groups "4e année" and "5e année". Returns [team, election, a, b]. */
function groupUpdateSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $a = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $b = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $a, $b];
}

it('renames a group and its voters follow it [FR-VOT-08] (scenario 1)', function (string $status) {
    [$t, $election, $a] = groupUpdateSignedIn($this, $status);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Suit', 'group' => $a]);

    $response = $this->browser->patch(groupUpdateUrl($a), ['name' => ' 6e année '])->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $a, 'name' => '6e année', 'voters_count' => 1])
        ->and(Accounts::groupRow($a)['name'])->toBe('6e année')
        ->and(Accounts::groupRow($a)['name_key'])->toBe('6e année')
        ->and(Accounts::voterRow($voter)['voter_group_id'])->toBe(Accounts::groupRow($a)['id']);
})->with(['draft', 'scheduled']);

it('accepts its own name, in another case [FR-VOT-08] (scenario 2)', function () {
    [$t, $election, $a] = groupUpdateSignedIn($this);

    $this->browser->patch(groupUpdateUrl($a), ['name' => '4e année'])->assertOk();
    $this->browser->patch(groupUpdateUrl($a), ['name' => '4E Année'])->assertOk()->assertJsonPath('data.name', '4E Année');
});

it('answers 422 for a bad name or one used by another group [FR-VOT-08] (scenario 3)', function (array $body, string $rule) {
    [$t, $election, $a] = groupUpdateSignedIn($this);

    $response = $this->browser->patch(groupUpdateUrl($a), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.name'))->toContain($rule)
        ->and(Accounts::groupRow($a)['name'])->toBe('4e année');
})->with([
    'missing' => [[], 'required'],
    'blank' => [['name' => ' '], 'required'],
    'too long' => [['name' => str_repeat('g', 101)], 'max'],
    'used' => [['name' => '5E ANNÉE'], 'unique'],
]);

it('answers 409 election_voters_locked for an open, closed, published or archived election [FR-SEC-06] (scenario 4)', function (string $status) {
    [$t, $election, $a] = groupUpdateSignedIn($this, $status);

    $this->browser->patch(groupUpdateUrl($a), ['name' => 'Trop tard'])->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::groupRow($a)['name'])->toBe('4e année');
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a group that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 5)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->patch(groupUpdateUrl(GROUP_UPDATE_UNKNOWN), ['name' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(groupUpdateUrl($target), ['name' => 'X'])))->toBe($unknown);
    }
    expect(Accounts::groupRow($foreign)['name'])->toBe('De B');
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenarios 6 to 11)', function () {
    [$t, $election, $a] = groupUpdateSignedIn($this);

    $this->browser->patch(groupUpdateUrl($a), ['name' => 'X'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('POST', groupUpdateUrl($a))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->rawBody('PATCH', groupUpdateUrl($a), '{"name": ')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->patch(groupUpdateUrl($a), ['name' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::groupRow($a)['name'])->toBe('4e année');
});

it('answers 401 when nobody is signed in [FR-VOT-08] (scenario 6)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $a = Accounts::plantGroup(['election' => $election, 'name' => 'Seul']);

    $this->browser->patch(groupUpdateUrl($a), ['name' => 'X'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 9)', function () {
    [$t, $election, $a] = groupUpdateSignedIn($this);
    foreach (range(1, 120) as $i) {
        $this->browser->patch(groupUpdateUrl($a), ['name' => ''])->assertStatus(422);
    }

    $this->browser->patch(groupUpdateUrl($a), ['name' => 'Trop'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
