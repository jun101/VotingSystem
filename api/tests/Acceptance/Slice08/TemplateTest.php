<?php

/*
 * GET /api/v1/elections/{election}/voter-imports/template — docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md
 * Tenant suite, route api/v1/elections/{election}/voter-imports/template: another institution's election answers 404,
 * the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

const IMPORT_TEMPLATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function importTemplateUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/elections/{$uuid}/voter-imports/template{$query}";
}

function importTemplateSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('downloads the template as an xlsx by default [FR-VOT-03] (scenario 1)', function (string $query) {
    [$t, $election] = importTemplateSignedIn($this);

    $response = $this->browser->get(importTemplateUrl($election, $query))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('modele-electeurs.xlsx')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $rows = Spreadsheets::read(Spreadsheets::body($response));
    expect($rows[0])->toBe(Spreadsheets::HEADER)
        ->and(count($rows))->toBe(4);
    foreach (array_slice($rows, 1) as $row) {
        expect($row[0])->not->toBe('')
            ->and(implode('', $row))->not->toContain('=');
    }
})->with(['no format' => [''], 'xlsx' => ['?format=xlsx']]);

it('downloads the template as a csv with a byte-order mark [FR-VOT-03] (scenario 2)', function () {
    [$t, $election] = importTemplateSignedIn($this);

    $response = $this->browser->get(importTemplateUrl($election, '?format=csv'))->assertOk();

    $body = Spreadsheets::body($response);
    expect($response->headers->get('Content-Disposition'))->toContain('modele-electeurs.csv')
        ->and(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
    $lines = array_values(array_filter(preg_split('/\r?\n/', substr($body, 3))));
    expect($lines[0])->toBe('nom_complet,groupe,identifiant,courriel,telephone')
        ->and(count($lines))->toBe(4);
});

it('answers 422 for another format [FR-VOT-03] (scenario 3)', function () {
    [$t, $election] = importTemplateSignedIn($this);

    $response = $this->browser->get(importTemplateUrl($election, '?format=pdf'))->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.fields.format'))->toContain('invalid');
});

it('gives the template in every status of the election [FR-VOT-03] (scenario 1)', function (string $status) {
    [$t, $election] = importTemplateSignedIn($this, $status);

    $this->browser->get(importTemplateUrl($election))->assertOk();
})->with(['draft', 'scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(importTemplateUrl(IMPORT_TEMPLATE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(importTemplateUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 [NFR-SEC-04] (scenarios 5, 6 and 8)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(importTemplateUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->other('POST', importTemplateUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(importTemplateUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    [$t, $election] = importTemplateSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->get(importTemplateUrl($election, '?format=csv'))->assertOk();
    }

    $this->browser->get(importTemplateUrl($election))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
