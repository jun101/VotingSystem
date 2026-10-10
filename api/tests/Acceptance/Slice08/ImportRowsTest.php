<?php

/*
 * GET /api/v1/voter-imports/{import}/rows — docs/api/voter-imports/GET-voter-imports-{import}-rows.md
 * Tenant suite, route api/v1/voter-imports/{import}/rows: another institution's record answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const IMPORT_ROWS_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importRowsUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/voter-imports/{$uuid}/rows{$query}";
}

/** 120 rows: lines 2 to 121; every fifth is invalid, every tenth a duplicate. Returns [team, election, import]. */
function importRowsSignedIn($test, string $status = 'checked'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $rows = [];
    for ($i = 1; $i <= 120; $i++) {
        $rows[] = match (true) {
            $i % 10 === 0 => ['status' => 'duplicate', 'reasons' => ['identifier_exists'], 'full_name' => "Électeur {$i}", 'identifier' => "E-{$i}"],
            $i % 5 === 0 => ['status' => 'invalid', 'reasons' => ['email_invalid'], 'full_name' => "Électeur {$i}", 'email' => 'mauvais@'],
            default => ['status' => 'ok', 'full_name' => "Électeur {$i}", 'group' => '4e année', 'identifier' => "E-{$i}", 'email' => "e{$i}@example.ht", 'phone' => '+509 1111 1111'],
        };
    }
    $import = Accounts::plantImport(['election' => $election, 'status' => $status, 'rows' => $rows]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $import];
}

it('lists the rows in file order, 50 a page, with the documented fields [FR-VOT-03] (scenario 1)', function () {
    [$t, $election, $import] = importRowsSignedIn($this);

    $first = $this->browser->get(importRowsUrl($import))->assertOk();
    $last = $this->browser->get(importRowsUrl($import, '?page=3'))->assertOk();

    expect($first->json('meta'))->toBe(['page' => 1, 'per_page' => 50, 'total' => 120])
        ->and($first->json('data'))->toHaveCount(50)
        ->and($last->json('data'))->toHaveCount(20)
        ->and($first->json('data.0'))->toBe([
            'line' => 2, 'status' => 'ok', 'reasons' => [], 'full_name' => 'Électeur 1', 'group' => '4e année',
            'identifier' => 'E-1', 'email' => 'e1@example.ht', 'phone' => '+509 1111 1111',
        ])
        ->and(array_column($first->json('data'), 'line'))->toBe(range(2, 51))
        ->and($last->json('data.19.line'))->toBe(121);
});

it('filters the valid rows and the rejected ones [FR-VOT-03] (scenarios 2, 3)', function () {
    [$t, $election, $import] = importRowsSignedIn($this);

    $ok = $this->browser->get(importRowsUrl($import, '?filter=ok'))->assertOk();
    $errors = $this->browser->get(importRowsUrl($import, '?filter=errors'))->assertOk();

    expect($ok->json('meta.total'))->toBe(96)
        ->and(collect($ok->json('data'))->pluck('status')->unique()->all())->toBe(['ok'])
        ->and($errors->json('meta.total'))->toBe(24)
        ->and($errors->json('data'))->toHaveCount(24)
        ->and(collect($errors->json('data'))->pluck('status')->unique()->sort()->values()->all())->toBe(['duplicate', 'invalid'])
        ->and($errors->json('data.0'))->toMatchArray(['line' => 6, 'status' => 'invalid', 'reasons' => ['email_invalid']]);
});

it('gives an empty page past the end [FR-VOT-03] (scenario 4)', function () {
    [$t, $election, $import] = importRowsSignedIn($this);

    $response = $this->browser->get(importRowsUrl($import, '?page=99'))->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(120);
});

it('answers 422 for a filter or a page that is not valid [FR-VOT-03] (scenario 5)', function (string $query, string $field) {
    [$t, $election, $import] = importRowsSignedIn($this);

    $response = $this->browser->get(importRowsUrl($import, $query))->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.fields.{$field}"))->not->toBeNull();
})->with([
    'filter' => ['?filter=none', 'filter'],
    'page zero' => ['?page=0', 'page'],
    'page text' => ['?page=a', 'page'],
    'page too far' => ['?page=1000001', 'page'],
]);

it('lists the rows of a running or finished import [FR-VOT-03] (scenario 1)', function (string $status) {
    [$t, $election, $import] = importRowsSignedIn($this, $status);

    $this->browser->get(importRowsUrl($import))->assertOk()->assertJsonPath('meta.total', 120);
})->with(['importing', 'done']);

it('answers 409 import_not_checked while the import is uploaded or failed [FR-VOT-03] (scenario 6)', function (string $status) {
    [$t, $election] = importRowsSignedIn($this);
    $import = Accounts::plantImport(['election' => $election, 'status' => $status]);

    $this->browser->get(importRowsUrl($import))->assertStatus(409)->assertJsonPath('error.code', 'import_not_checked');
})->with(['uploaded', 'failed']);

it('answers 404, the same for every case, for an import that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantImport(['election' => $foreignElection, 'rows' => [['full_name' => 'De B']]]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(importRowsUrl(IMPORT_ROWS_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(importRowsUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 [NFR-SEC-04] (scenarios 8, 9 and 11)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $import = Accounts::plantImport(['election' => $election, 'rows' => [['full_name' => 'Rose']]]);

    $this->browser->get(importRowsUrl($import))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->other('POST', importRowsUrl($import))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(importRowsUrl($import))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 10)', function () {
    [$t, $election, $import] = importRowsSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->get(importRowsUrl($import, '?page=99'))->assertOk();
    }

    $this->browser->get(importRowsUrl($import))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
