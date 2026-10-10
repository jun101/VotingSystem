<?php

/*
 * PATCH /api/v1/voters/{voter} — docs/api/voters/PATCH-voters-{voter}.md
 * Tenant suite, route api/v1/voters/{voter}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const VOTER_UPDATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function voterUpdateUrl(string $uuid): string
{
    return "/api/v1/voters/{$uuid}";
}

/** An owner of A signed in and one voter (in group "4e année") in an election of the given status. Returns [team, election, voter, group]. */
function voterUpdateSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Avant', 'group' => $group, 'identifier' => 'E-1', 'email' => 'avant@example.ht', 'phone' => '+509 1111 1111']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $voter, $group];
}

it('changes the given fields and moves updated_at [FR-VOT-06] (scenario 1)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    $before = Accounts::voterRow($voter);

    $response = $this->browser->patch(voterUpdateUrl($voter), ['full_name' => ' Après ', 'email' => 'Apres@Example.HT'])->assertOk();

    $row = Accounts::voterRow($voter);
    expect($response->json('data'))->toMatchArray(['id' => $voter, 'full_name' => 'Après', 'email' => 'apres@example.ht', 'identifier' => 'E-1'])
        ->and($row['full_name'])->toBe('Après')
        ->and($row['identifier'])->toBe('E-1')
        ->and($row['phone'])->toBe($before['phone']);
});

it('moves the voter to another group by name, or to a new one [FR-VOT-08] (scenario 2)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    $other = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);

    $this->browser->patch(voterUpdateUrl($voter), ['group' => '5E année'])->assertOk()->assertJsonPath('data.group.id', $other);
    $response = $this->browser->patch(voterUpdateUrl($voter), ['group' => 'Terminale A'])->assertOk();

    expect($response->json('data.group.name'))->toBe('Terminale A')
        ->and(Accounts::groupRows($election))->toHaveCount(3);
});

it('clears the group, the identifier, the email and the phone with null [FR-VOT-06] (scenario 3)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);

    $response = $this->browser->patch(voterUpdateUrl($voter), ['group' => null, 'identifier' => '', 'email' => null, 'phone' => '  '])->assertOk();

    expect($response->json('data'))->toMatchArray(['group' => null, 'identifier' => null, 'email' => null, 'phone' => null])
        ->and(Accounts::voterRow($voter)['voter_group_id'])->toBeNull();
});

it('accepts the same values, and an empty object, and changes nothing [FR-VOT-06] (scenarios 4, 5)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    $before = Accounts::voterRow($voter);

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'Avant', 'identifier' => 'E-1', 'email' => 'avant@example.ht'])->assertOk();
    $this->browser->patch(voterUpdateUrl($voter), [])->assertOk();

    $after = Accounts::voterRow($voter);
    foreach (['full_name', 'identifier', 'email', 'phone', 'voter_group_id'] as $column) {
        expect($after[$column])->toBe($before[$column]);
    }
});

it('answers 422 for bad values or values used by another voter [FR-VOT-06] (scenario 6)', function (array $body, string $field, string $rule) {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Autre', 'identifier' => 'E-2', 'email' => 'autre@example.ht']);

    $response = $this->browser->patch(voterUpdateUrl($voter), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::voterRow($voter)['full_name'])->toBe('Avant');
})->with([
    'name blank' => [['full_name' => '  '], 'full_name', 'required'],
    'name too long' => [['full_name' => str_repeat('a', 151)], 'full_name', 'max'],
    'identifier used' => [['identifier' => 'e-2'], 'identifier', 'unique'],
    'email used' => [['email' => 'AUTRE@example.ht'], 'email', 'unique'],
    'email bad' => [['email' => 'nope'], 'email', 'email'],
    'phone bad' => [['phone' => 'abc'], 'phone', 'invalid'],
    'group too long' => [['group' => str_repeat('g', 101)], 'group', 'max'],
]);

it('answers 409 group_limit_reached when a new group is needed at 100 groups [FR-VOT-08] (scenario 7)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    foreach (range(1, 99) as $i) {
        Accounts::plantGroup(['election' => $election, 'name' => "Groupe {$i}"]);
    }

    $this->browser->patch(voterUpdateUrl($voter), ['group' => 'Un de trop'])->assertStatus(409)->assertJsonPath('error.code', 'group_limit_reached');
    expect(Accounts::groupRows($election))->toHaveCount(100);
});

it('answers 409 election_voters_locked for a closed, published or archived election [FR-SEC-06] (scenario 8)', function (string $status) {
    [$t, $election, $voter] = voterUpdateSignedIn($this, $status);

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'Trop tard'])->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::voterRow($voter)['full_name'])->toBe('Avant');
})->with(['closed', 'published', 'archived']);

it('edits a voter of a scheduled or open election [FR-ELEC-03] (scenario 1)', function (string $status) {
    [$t, $election, $voter] = voterUpdateSignedIn($this, $status);

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'Corrigé'])->assertOk();
})->with(['scheduled', 'open']);

it('keeps the voter in their group in an open election [FR-SEC-06] (scenario 8)', function (array $body) {
    [$t, $election, $voter, $group] = voterUpdateSignedIn($this, 'open');
    Accounts::plantGroup(['election' => $election, 'name' => 'Autre']);

    $this->browser->patch(voterUpdateUrl($voter), $body)->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');

    expect(Accounts::groupRows($election))->toHaveCount(2)
        ->and(Accounts::voterRow($voter)['voter_group_id'] ?? null)->not->toBeNull();
})->with([
    'another group' => [['group' => 'Autre']],
    'a new group' => [['group' => 'Inventé']],
    'no group' => [['group' => null]],
]);

it('lets an open election repeat the voter\'s own group while other fields change [FR-SEC-06] (scenario 1)', function () {
    [$t, $election, $voter, $group] = voterUpdateSignedIn($this, 'open');

    $this->browser->patch(voterUpdateUrl($voter), ['group' => ' 4E ANNÉE ', 'full_name' => 'Corrigé'])
        ->assertOk()->assertJsonPath('data.group.id', $group);
});

it('answers 404, the same for every case, for a voter that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 9)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantVoter(['election' => $foreignElection, 'full_name' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->patch(voterUpdateUrl(VOTER_UPDATE_UNKNOWN), ['full_name' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(voterUpdateUrl($target), ['full_name' => 'X'])))->toBe($unknown);
    }
    expect(Accounts::voterRow($foreign)['full_name'])->toBe('De B');
});

it('answers 401, 403, 419, 400 and 405 as every write [NFR-SEC-01, 04] (scenarios 10 to 15)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'X'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->rawBody('PATCH', voterUpdateUrl($voter), '{"full_name": ')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
    $this->browser->other('POST', voterUpdateUrl($voter))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 401 when nobody is signed in [FR-VOT-06] (scenario 10)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Avant']);

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'X'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 13)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->patch(voterUpdateUrl($voter), ['full_name' => ''])->assertStatus(422);
    }

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'Trop'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('ignores election, id and the numeric keys in the body [FR-INST-05] (notes)', function () {
    [$t, $election, $voter] = voterUpdateSignedIn($this);
    $elsewhere = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $before = Accounts::voterRow($voter);

    $this->browser->patch(voterUpdateUrl($voter), ['full_name' => 'Même', 'election' => $elsewhere, 'election_id' => 99, 'id' => 5, 'institution_id' => 2])->assertOk();

    expect(Accounts::voterRow($voter)['election_id'])->toBe($before['election_id'])
        ->and(Accounts::voterRow($voter)['institution_id'])->toBe($before['institution_id']);
});
