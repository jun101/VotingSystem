<?php

/*
 * PATCH /api/v1/voter-imports/{import} — docs/api/voter-imports/PATCH-voter-imports-{import}.md
 * Tenant suite, route api/v1/voter-imports/{import}: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_MAP_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importMapUrl(string $uuid): string
{
    return "/api/v1/voter-imports/{$uuid}";
}

/**
 * An owner of A signed in and a real upload (so the file is on disk and was checked): the columns are
 * Nom, Classe, Contact; the proposed mapping reads name and group only. Returns [team, election, import].
 */
function importMapSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'draft']);
    Team::signIn($test, $t['a']['owner']);
    $response = $test->browser->upload('POST', "/api/v1/elections/{$election}/voter-imports", ['file' => Spreadsheets::upload(Spreadsheets::xlsx([
        ['Nom', 'Classe', 'Contact'],
        ['Rose', '4e année', 'rose@example.ht'],
        ['Jean', '5e année', 'pas-un-courriel'],
    ]))])->assertStatus(202);
    if ($status !== 'draft') {
        Accounts::setElectionStatus($election, $status);
    }

    return [$t, $election, $response->json('data.id')];
}

it('changes the mapping and checks the file again [FR-VOT-03] (scenario 1)', function () {
    [$t, $election, $import] = importMapSignedIn($this);
    $before = $this->browser->get(importMapUrl($import))->json('data');
    expect($before['mapping'])->toBe(['full_name' => 0, 'group' => 1, 'identifier' => null, 'email' => null, 'phone' => null])
        ->and($before['valid_rows'])->toBe(2);

    $response = $this->browser->patch(importMapUrl($import), ['mapping' => ['email' => 2]])->assertStatus(202);

    expect($response->json('data.status'))->toBeIn(['uploaded', 'checked']);
    $after = $this->browser->get(importMapUrl($import))->assertOk()->json('data');
    expect($after)->toMatchArray(['status' => 'checked', 'valid_rows' => 1, 'invalid_rows' => 1, 'total_rows' => 2])
        ->and($after['mapping'])->toBe(['full_name' => 0, 'group' => 1, 'identifier' => null, 'email' => 2, 'phone' => null]);
    $rows = $this->browser->get(importMapUrl($import).'/rows?filter=errors')->json('data');
    expect($rows[0])->toMatchArray(['line' => 3, 'reasons' => ['email_invalid'], 'email' => 'pas-un-courriel']);
});

it('stops reading a column when its field is set to null [FR-VOT-03] (scenario 1)', function () {
    [$t, $election, $import] = importMapSignedIn($this);

    $this->browser->patch(importMapUrl($import), ['mapping' => ['group' => null]])->assertStatus(202);

    $after = $this->browser->get(importMapUrl($import))->json('data');
    expect($after['mapping']['group'])->toBeNull()
        ->and($after['new_groups'])->toBe([]);
    $rows = $this->browser->get(importMapUrl($import).'/rows')->json('data');
    expect($rows[0]['group'])->toBeNull();
});

it('accepts the mapping already stored [FR-VOT-03] (scenario 2)', function () {
    [$t, $election, $import] = importMapSignedIn($this);
    $mapping = $this->browser->get(importMapUrl($import))->json('data.mapping');

    $this->browser->patch(importMapUrl($import), ['mapping' => $mapping])->assertStatus(202);

    expect($this->browser->get(importMapUrl($import))->json('data'))->toMatchArray(['status' => 'checked', 'valid_rows' => 2]);
});

it('answers 422 for a mapping that is not valid [FR-VOT-03] (scenario 3)', function (array $body) {
    [$t, $election, $import] = importMapSignedIn($this);
    $before = Accounts::importRow($import);

    $response = $this->browser->patch(importMapUrl($import), $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.fields.mapping'))->toContain('invalid')
        ->and(Accounts::importRow($import)['mapping'])->toBe($before['mapping']);
})->with([
    'no mapping' => [[]],
    'not an object' => [['mapping' => 'full_name']],
    'unknown key' => [['mapping' => ['salary' => 2]]],
    'index out of the columns' => [['mapping' => ['email' => 3]]],
    'negative index' => [['mapping' => ['email' => -1]]],
    'not an integer' => [['mapping' => ['email' => 'C']]],
    'full name null' => [['mapping' => ['full_name' => null]]],
    'a column used twice' => [['mapping' => ['email' => 0]]],
]);

it('answers 409 import_not_checked unless the import is checked [FR-VOT-03] (scenario 4)', function (string $status) {
    [$t, $election] = importMapSignedIn($this);
    $planted = Accounts::plantImport(['election' => $election, 'status' => $status, 'columns' => ['Nom', 'Classe', 'Contact'],
        'mapping' => ['full_name' => 0, 'group' => 1, 'identifier' => null, 'email' => null, 'phone' => null]]);

    $this->browser->patch(importMapUrl($planted), ['mapping' => ['email' => 2]])->assertStatus(409)->assertJsonPath('error.code', 'import_not_checked');
})->with(['uploaded', 'importing', 'done', 'failed']);

it('answers 409 election_voters_locked once the election is no longer a draft or scheduled [FR-SEC-06] (scenario 5)', function (string $status) {
    [$t, $election, $import] = importMapSignedIn($this, $status);

    $this->browser->patch(importMapUrl($import), ['mapping' => ['email' => 2]])->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an import that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantImport(['election' => $foreignElection, 'rows' => [['full_name' => 'De B']]]);
    Team::signIn($this, $t['a']['owner']);
    $body = ['mapping' => ['email' => 2]];

    $unknown = Team::shape($this->browser->patch(importMapUrl(IMPORT_MAP_UNKNOWN), $body));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(importMapUrl($target), $body)))->toBe($unknown);
    }
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 7 to 9 and 11)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election, 'columns' => ['Nom', 'Classe', 'Contact']]);
    $body = ['mapping' => ['phone' => 2]];

    $this->browser->patch(importMapUrl($import), $body)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->patch(importMapUrl($import), $body, [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('PUT', importMapUrl($import))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->patch(importMapUrl($import), $body)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 10)', function () {
    [$t, $election, $import] = importMapSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->patch(importMapUrl($import), ['mapping' => ['email' => 2]])->assertStatus(202);
    }

    $this->browser->patch(importMapUrl($import), ['mapping' => ['email' => 2]])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
