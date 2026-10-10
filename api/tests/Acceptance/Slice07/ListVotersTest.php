<?php

/*
 * GET /api/v1/elections/{election}/voters — docs/api/voters/GET-elections-{election}-voters.md
 * Tenant suite, route api/v1/elections/{election}/voters: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const VOTER_LIST_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function voterListUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/elections/{$uuid}/voters{$query}";
}

function voterListSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('lists the voters by full name with the documented fields [FR-VOT-01] (scenario 1)', function () {
    [$t, $election] = voterListSignedIn($this);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $b = Accounts::plantVoter(['election' => $election, 'full_name' => 'Nadège Louis', 'group' => $group, 'identifier' => 'E-2107', 'email' => 'n@example.ht', 'phone' => '+509 3712 4455']);
    $a = Accounts::plantVoter(['election' => $election, 'full_name' => 'adrien Joseph']);

    $response = $this->browser->get(voterListUrl($election))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$a, $b])
        ->and($response->json('data.0'))->toMatchArray(['id' => $a, 'full_name' => 'adrien Joseph', 'group' => null, 'identifier' => null, 'email' => null, 'phone' => null])
        ->and($response->json('data.1'))->toMatchArray(['id' => $b, 'group' => ['id' => $group, 'name' => '4e année'], 'identifier' => 'E-2107', 'email' => 'n@example.ht', 'phone' => '+509 3712 4455'])
        ->and(array_keys($response->json('data.1')))->toEqualCanonicalizing(['id', 'full_name', 'group', 'identifier', 'email', 'phone', 'created_at', 'updated_at'])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 24, 'total' => 2]);
});

it('pages by 24 and honours page and per_page [FR-VOT-06] (scenario 1)', function () {
    [$t, $election] = voterListSignedIn($this);
    foreach (range(1, 30) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => sprintf('Voter %02d', $i)]);
    }

    $first = $this->browser->get(voterListUrl($election))->assertOk();
    $second = $this->browser->get(voterListUrl($election, '?page=2&per_page=24'))->assertOk();
    $small = $this->browser->get(voterListUrl($election, '?per_page=10&page=3'))->assertOk();

    expect($first->json('data'))->toHaveCount(24)
        ->and($first->json('meta.total'))->toBe(30)
        ->and($second->json('data'))->toHaveCount(6)
        ->and($second->json('data.0.full_name'))->toBe('Voter 25')
        ->and($small->json('data'))->toHaveCount(10)
        ->and($small->json('data.0.full_name'))->toBe('Voter 21');
});

it('answers an empty list for an election with no voter [FR-VOT-01] (scenario 2)', function () {
    [$t, $election] = voterListSignedIn($this);

    $this->browser->get(voterListUrl($election))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});

it('searches the full name, the identifier and the email, ignoring case [FR-VOT-06] (scenario 3)', function () {
    [$t, $election] = voterListSignedIn($this);
    $byName = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose-Marie Désir']);
    $byId = Accounts::plantVoter(['election' => $election, 'full_name' => 'Autre', 'identifier' => 'E-ROSE-1']);
    $byMail = Accounts::plantVoter(['election' => $election, 'full_name' => 'Encore', 'email' => 'x.rose@example.ht']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre']);

    $response = $this->browser->get(voterListUrl($election, '?q='.rawurlencode('  ROSE ')))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing([$byName, $byId, $byMail])
        ->and($response->json('meta.total'))->toBe(3);
    $this->browser->get(voterListUrl($election, '?q=%20%20'))->assertOk()->assertJsonPath('meta.total', 4);
});

it('filters by group and by none [FR-VOT-06] (scenario 4)', function () {
    [$t, $election] = voterListSignedIn($this);
    $g = Accounts::plantGroup(['election' => $election, 'name' => 'Terminale A']);
    $in = Accounts::plantVoter(['election' => $election, 'full_name' => 'Dedans', 'group' => $g]);
    $out = Accounts::plantVoter(['election' => $election, 'full_name' => 'Dehors']);

    $grouped = $this->browser->get(voterListUrl($election, "?group={$g}"))->assertOk();
    $none = $this->browser->get(voterListUrl($election, '?group=none'))->assertOk();

    expect(array_column($grouped->json('data'), 'id'))->toBe([$in])
        ->and(array_column($none->json('data'), 'id'))->toBe([$out]);
});

it('answers an empty list for a group that is unknown, of another election or another institution [FR-INST-05] (scenario 5)', function () {
    [$t, $election] = voterListSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Présent']);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $elsewhere = Accounts::plantGroup(['election' => $otherElection]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection]);

    foreach ([VOTER_LIST_UNKNOWN, $elsewhere, $foreign] as $group) {
        $this->browser->get(voterListUrl($election, "?group={$group}"))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }
});

it('answers 422 for a bad group, per_page or page [FR-VOT-06] (scenario 6)', function (string $query, string $field, string $rule) {
    [$t, $election] = voterListSignedIn($this);

    $response = $this->browser->get(voterListUrl($election, $query));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule);
})->with([
    'group not a uuid' => ['?group=abc', 'group', 'invalid'],
    'group numeric' => ['?group=12', 'group', 'invalid'],
    'per_page too big' => ['?per_page=101', 'per_page', 'max'],
    'page zero' => ['?page=0', 'page', 'min'],
]);

it('lists the voters of an election that is not a draft [FR-VOT-06] (scenario 7)', function (string $status) {
    [$t, $election] = voterListSignedIn($this, $status);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Lu seulement']);

    $this->browser->get(voterListUrl($election))->assertOk()->assertJsonPath('meta.total', 1);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Accounts::plantVoter(['election' => $foreign, 'full_name' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(voterListUrl(VOTER_LIST_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(voterListUrl($target))))->toBe($unknown);
    }
});

it('answers 401 when nobody is signed in [FR-VOT-06] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(voterListUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    [$t, $election] = voterListSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(voterListUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 for another method than GET, HEAD, POST [FR-VOT-06] (scenario 13)', function (string $method) {
    [$t, $election] = voterListSignedIn($this);

    $this->browser->other($method, voterListUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PATCH', 'DELETE']);

it('lets a manager read the list too [FR-VOT-06]', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Vu']);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get(voterListUrl($election))->assertOk()->assertJsonPath('meta.total', 1);
});
