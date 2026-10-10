<?php

/*
 * POST /api/v1/elections/{election}/voters — docs/api/voters/POST-elections-{election}-voters.md
 * Tenant suite, route api/v1/elections/{election}/voters: another institution's record answers 404, the same as an unknown one.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Team;

const VOTER_CREATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function voterCreateUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/voters";
}

function voterCreateSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('creates a voter from a full name only [FR-VOT-02] (scenario 1)', function () {
    [$t, $election] = voterCreateSignedIn($this);

    $response = $this->browser->post(voterCreateUrl($election), ['full_name' => '  Rose-Marie Désir  '])->assertCreated();

    $row = Accounts::voterRow($response->json('data.id'));
    expect($response->json('data'))->toMatchArray(['full_name' => 'Rose-Marie Désir', 'group' => null, 'identifier' => null, 'email' => null, 'phone' => null])
        ->and($response->headers->get('Location'))->toBe('/api/v1/voters/'.$response->json('data.id'))
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($row['election_id'])->toBe(Accounts::electionRow($election)['id'])
        ->and($row['voter_group_id'])->toBeNull();
});

it('uses an existing group by its name, ignoring case and spaces [FR-VOT-08] (scenario 2)', function () {
    [$t, $election] = voterCreateSignedIn($this);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);

    $response = $this->browser->post(voterCreateUrl($election), [
        'full_name' => 'Jean Pierre', 'group' => '  4E ANNÉE ', 'identifier' => ' E-2042 ', 'email' => 'Jean.Pierre@Example.HT', 'phone' => '+509 3712 4455',
    ])->assertCreated();

    expect($response->json('data'))->toMatchArray(['group' => ['id' => $group, 'name' => '4e année'], 'identifier' => 'E-2042', 'email' => 'jean.pierre@example.ht', 'phone' => '+509 3712 4455'])
        ->and(Accounts::groupRows($election))->toHaveCount(1);
});

it('creates the group when its name is new [FR-VOT-08] (scenario 3)', function () {
    [$t, $election] = voterCreateSignedIn($this);

    $response = $this->browser->post(voterCreateUrl($election), ['full_name' => 'Nadège Louis', 'group' => 'Terminale B'])->assertCreated();

    $groups = Accounts::groupRows($election);
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['name'])->toBe('Terminale B')
        ->and($groups[0]['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($response->json('data.group'))->toBe(['id' => $groups[0]['uuid'], 'name' => 'Terminale B']);
});

it('lets a manager create, and a scheduled or open election accept voters [FR-VOT-02] (scenarios 1, 11)', function (string $status) {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Ajouté plus tard'])->assertCreated();
    expect(Accounts::voterRows($election))->toHaveCount(1);
})->with(['draft', 'scheduled', 'open']);

it('answers 422 when full_name is missing, blank or too long [FR-VOT-01] (scenario 4)', function (array $body, string $rule) {
    [$t, $election] = voterCreateSignedIn($this);

    $response = $this->browser->post(voterCreateUrl($election), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.full_name'))->toContain($rule)
        ->and(Accounts::voterRows($election))->toBe([]);
})->with([
    'missing' => [[], 'required'],
    'blank' => [['full_name' => '   '], 'required'],
    'too long' => [['full_name' => str_repeat('é', 151)], 'max'],
]);

it('answers 422 for an identifier already used, ignoring case, but not in another election [FR-VOT-01] (scenario 5)', function () {
    [$t, $election] = voterCreateSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Premier', 'identifier' => 'E-2041']);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $response = $this->browser->post(voterCreateUrl($election), ['full_name' => 'Second', 'identifier' => ' e-2041 ']);

    $response->assertStatus(422);
    expect($response->json('error.fields.identifier'))->toContain('unique')
        ->and(Accounts::voterRows($election))->toHaveCount(1);
    $this->browser->post(voterCreateUrl($other), ['full_name' => 'Second', 'identifier' => 'E-2041'])->assertCreated();
});

it('answers 422 for a bad or a used email [FR-VOT-01] (scenario 6)', function (string $email, string $rule) {
    [$t, $election] = voterCreateSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Premier', 'email' => 'pris@example.ht']);

    $response = $this->browser->post(voterCreateUrl($election), ['full_name' => 'Second', 'email' => $email]);

    $response->assertStatus(422);
    expect($response->json('error.fields.email'))->toContain($rule)
        ->and(Accounts::voterRows($election))->toHaveCount(1);
})->with([
    'not an address' => ['not-an-email', 'email'],
    'used' => ['PRIS@example.ht', 'unique'],
    'no dot in the domain' => ['a@localhost', 'email'],
    'ip address' => ['a@[127.0.0.1]', 'email'],
    'quoted part with a line break' => ["\"a\r\n b\"@example.ht", 'email'],
]);

it('takes an existing group but refuses a new one in an open election [FR-SEC-06] (scenario 11)', function () {
    [$t, $election] = voterCreateSignedIn($this, ['status' => 'open']);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Existant']);

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Dans le groupe', 'group' => 'existant'])
        ->assertCreated()->assertJsonPath('data.group.id', $group);
    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Nouveau groupe', 'group' => 'Inventé'])
        ->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');

    expect(Accounts::groupRows($election))->toHaveCount(1)
        ->and(Accounts::voterRows($election))->toHaveCount(1);
});

it('answers 422 for a phone that is too long or has other characters, and for a group over 100 [FR-VOT-01] (scenarios 7, 8)', function (array $body, string $field, string $rule) {
    [$t, $election] = voterCreateSignedIn($this);

    $response = $this->browser->post(voterCreateUrl($election), ['full_name' => 'X'] + $body);

    $response->assertStatus(422);
    expect($response->json("error.fields.{$field}"))->toContain($rule);
})->with([
    'phone too long' => [['phone' => str_repeat('1', 31)], 'phone', 'max'],
    'phone letters' => [['phone' => '37124455abc'], 'phone', 'invalid'],
    'group too long' => [['group' => str_repeat('g', 101)], 'group', 'max'],
]);

it('answers 409 voter_limit_reached at 10 000 voters [FR-VOT-04] (scenario 9)', function () {
    [$t, $election] = voterCreateSignedIn($this);
    $db = DB::connection(useMigratorConnection());
    $row = $db->table('elections')->where('uuid', $election)->first();
    $now = now('UTC')->format('Y-m-d H:i:s');
    foreach (array_chunk(range(1, 10000), 1000) as $chunk) {
        $db->table('voters')->insert(array_map(fn ($i) => [
            'uuid' => (string) Illuminate\Support\Str::uuid(), 'institution_id' => $row->institution_id, 'election_id' => $row->id,
            'full_name' => "Voter {$i}", 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Le 10 001e'])
        ->assertStatus(409)->assertJsonPath('error.code', 'voter_limit_reached');
    expect($db->table('voters')->where('election_id', $row->id)->count())->toBe(10000);
});

it('answers 409 group_limit_reached when a new group is needed at 100 groups [FR-VOT-08] (scenario 10)', function () {
    [$t, $election] = voterCreateSignedIn($this);
    foreach (range(1, 100) as $i) {
        Accounts::plantGroup(['election' => $election, 'name' => "Groupe {$i}"]);
    }

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Nouveau', 'group' => 'Groupe 101'])
        ->assertStatus(409)->assertJsonPath('error.code', 'group_limit_reached');
    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Ancien', 'group' => 'groupe 7'])->assertCreated();
    expect(Accounts::groupRows($election))->toHaveCount(100)
        ->and(Accounts::voterRows($election))->toHaveCount(1);
});

it('answers 409 election_voters_locked for a closed, published or archived election [FR-SEC-06] (scenario 11)', function (string $status) {
    [$t, $election] = voterCreateSignedIn($this, ['status' => $status]);

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'Trop tard'])
        ->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
    expect(Accounts::voterRows($election))->toBe([]);
})->with(['closed', 'published', 'archived']);

it('checks the body before the state [FR-SEC-06] (scenario 11)', function () {
    [$t, $election] = voterCreateSignedIn($this, ['status' => 'closed']);

    $this->browser->post(voterCreateUrl($election), ['full_name' => ''])->assertStatus(422);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 12)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $body = ['full_name' => 'X'];

    $unknown = Team::shape($this->browser->post(voterCreateUrl(VOTER_CREATE_UNKNOWN), $body));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(voterCreateUrl($target), $body)))->toBe($unknown);
    }
    expect(Accounts::voterRows($foreign))->toBe([]);
});

it('answers 401 when nobody is signed in [FR-VOT-02] (scenario 13)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'X'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::voterRows($election))->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 14)', function () {
    [$t, $election] = voterCreateSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 15)', function () {
    [$t, $election] = voterCreateSignedIn($this);

    $this->browser->post(voterCreateUrl($election), ['full_name' => 'X'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 16)', function () {
    [$t, $election] = voterCreateSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->post(voterCreateUrl($election), ['full_name' => ''])->assertStatus(422);
    }

    $response = $this->browser->post(voterCreateUrl($election), ['full_name' => 'Trop']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::voterRows($election))->toBe([]);
});

it('answers 405 for another method than GET, HEAD, POST [FR-VOT-02] (scenario 17)', function (string $method) {
    [$t, $election] = voterCreateSignedIn($this);

    $this->browser->other($method, voterCreateUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PATCH', 'DELETE']);

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 18)', function () {
    [$t, $election] = voterCreateSignedIn($this);

    $this->browser->rawBody('POST', voterCreateUrl($election), '{"full_name": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('ignores fields a request must not set: id, institution, election [FR-INST-05] (notes)', function () {
    [$t, $election] = voterCreateSignedIn($this);

    $response = $this->browser->post(voterCreateUrl($election), [
        'full_name' => 'Seul', 'id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'institution' => $t['b']['owner']['institution'],
        'institution_id' => 2, 'election_id' => 1, 'election' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'voter_group_id' => 1,
    ])->assertCreated();

    $row = Accounts::voterRow($response->json('data.id'));
    expect($response->json('data.id'))->not->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7')
        ->and($row['institution_id'])->toBe(Accounts::electionRow($election)['institution_id'])
        ->and($row['election_id'])->toBe(Accounts::electionRow($election)['id'])
        ->and($row['voter_group_id'])->toBeNull();
});
