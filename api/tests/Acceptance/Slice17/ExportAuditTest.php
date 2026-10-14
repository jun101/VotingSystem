<?php

/*
 * GET /api/v1/audit/export — docs/api/audit/GET-audit-export.md
 * Tenant suite, route api/v1/audit/export: only the signed-in institution's entries are ever written.
 */

use Tests\Support\Accounts;
use Tests\Support\Audit;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

function exportAuditRows($response): array
{
    return Spreadsheets::read(Spreadsheets::body($response));
}

function auditExportSignedIn($test): array
{
    $t = Team::two();
    Accounts::updateInstitution($t['a']['owner']['institution'], ['name' => 'Collège Étoile du Matin']);
    Audit::plant($t['a']['owner']['institution'], ['action' => 'election.created', 'at' => '2026-10-01 10:00:00', 'details' => ['title' => '=HYPERLINK("http://evil.example")']]);
    Audit::plant($t['a']['owner']['institution'], ['action' => 'voter.created', 'at' => '2026-10-02 10:00:00', 'details' => ['name' => 'Rose Désir'], 'ip' => '196.1.2.3']);
    Audit::plant($t['b']['owner']['institution'], ['action' => 'election.deleted', 'at' => '2026-10-03 10:00:00']);
    Team::signIn($test, $t['a']['owner']);

    return $t;
}

it('exports the owner\'s entries as Excel, newest first, with the headers of the election\'s language [FR-AUD-03] (scenario 1)', function () {
    auditExportSignedIn($this);

    $response = $this->browser->get('/api/v1/audit/export?format=xlsx')->assertOk();
    $rows = exportAuditRows($response);

    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="journal-college-etoile-du-matin.xlsx"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($rows[0])->toBe(['Date (UTC)', 'Action', 'Personne', 'Élection', 'Objet', 'Détails', 'Adresse IP']);
    $actions = array_column(array_slice($rows, 1), 1);
    expect($actions)->toContain('voter.created')->toContain('election.created')->not->toContain('election.deleted')
        ->and(array_search('voter.created', $actions))->toBeLessThan(array_search('election.created', $actions));
});

it('exports CSV with a byte order mark and commas, and applies the filters of the list [FR-AUD-03] (scenarios 1, 2)', function () {
    auditExportSignedIn($this);

    $body = Spreadsheets::body($this->browser->get('/api/v1/audit/export?format=csv&action=voter.')->assertOk());

    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = array_map('str_getcsv', preg_split('/\r?\n/', trim(substr($body, 3))));
    expect($lines)->toHaveCount(2)
        ->and($lines[1][1])->toBe('voter.created')
        ->and($lines[1][6])->toBe('196.1.2.3')
        ->and($lines[1][5])->toContain('Rose Désir');
});

it('guards a value that could run as a formula [NFR-SEC-06] (scenario 3)', function () {
    auditExportSignedIn($this);

    $csv = Spreadsheets::body($this->browser->get('/api/v1/audit/export?format=csv&action=election.created')->assertOk());
    $flat = implode("\n", array_merge(...exportAuditRows($this->browser->get('/api/v1/audit/export?format=xlsx&action=election.created')->assertOk())));

    // The details are JSON text starting with a brace, so what could run is inside the text: nothing starts a cell.
    expect($csv)->not->toContain(',=HYPERLINK')->and($flat)->not->toMatch('/(^|\n)=/');
});

it('answers 422 for a missing or unknown format and for a bad date [FR-AUD-03] (scenario 4)', function () {
    auditExportSignedIn($this);

    $this->browser->get('/api/v1/audit/export')->assertStatus(422)->assertJsonPath('error.fields.format.0', 'required');
    $this->browser->get('/api/v1/audit/export?format=pdf')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->browser->get('/api/v1/audit/export?format=csv&from=demain')->assertStatus(422)->assertJsonPath('error.fields.from.0', 'invalid');
});

it('answers 403 to a manager [FR-AUD-03] (scenario 5)', function () {
    $t = auditExportSignedIn($this);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get('/api/v1/audit/export?format=csv')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('answers 401, 403, 405 and 429 above 20 an hour [NFR-SEC-04, NFR-SEC-05] (scenario 6)', function () {
    $this->browser->get('/api/v1/audit/export?format=csv')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $t = auditExportSignedIn($this);
    $this->browser->post('/api/v1/audit/export', [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');

    foreach (range(1, 20) as $i) {
        $this->browser->get('/api/v1/audit/export?format=csv')->assertOk();
    }
    $this->browser->get('/api/v1/audit/export?format=csv')->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');

    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get('/api/v1/audit/export?format=csv')->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
