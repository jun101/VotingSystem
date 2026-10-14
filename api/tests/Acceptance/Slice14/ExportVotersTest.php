<?php

/*
 * GET /api/v1/elections/{election}/voters/export — docs/api/turnout/GET-elections-{election}-voters-export.md
 * Tenant suite, route api/v1/elections/{election}/voters/export: another institution's election answers 404, the same
 * as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;
use Tests\Support\Turnout;

const EXPORT_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function exportUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/elections/{$uuid}/voters/export{$query}";
}

/** An owner of A signed in, an open election "Conseil des élèves 2026" with four voters, one of whom voted. */
function exportSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution'], 'status' => 'open', 'title' => 'Conseil des élèves 2026']);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g5 = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    $v = [
        'zoe' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Zoé Louis', 'group' => $g4, 'identifier' => 'E-9', 'email' => 'zoe@example.ht', 'phone' => '+509 3000 0001']),
        'ada' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Ada Joseph', 'group' => $g4]),
        'ben' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Ben Pierre', 'group' => $g5]),
        'eve' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Eve Sans-groupe']),
    ];
    Turnout::voted($v['ada'], '2026-10-12 13:00:00');
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $g4, $v];
}

function exportRows($response): array
{
    return Spreadsheets::read(Spreadsheets::body($response));
}

it('exports the voters who have not voted as Excel, by group then name, with the columns of the list [FR-VOT-07] (scenario 1)', function () {
    [$t, $election] = exportSignedIn($this);

    $response = $this->browser->get(exportUrl($election, '?format=xlsx'))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="electeurs-conseil-des-eleves-2026.xlsx"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(exportRows($response))->toBe([
            ['Nom complet', 'Groupe', 'Identifiant', 'Courriel', 'Téléphone'],
            ['Zoé Louis', '4e année', 'E-9', 'zoe@example.ht', '+509 3000 0001'],
            ['Ben Pierre', '5e année', '', '', ''],
            ['Eve Sans-groupe', '', '', '', ''],
        ]);
});

it('exports CSV with a UTF-8 byte order mark and commas [FR-VOT-07] (scenario 2)', function () {
    [$t, $election] = exportSignedIn($this);

    $body = Spreadsheets::body($this->browser->get(exportUrl($election, '?format=csv'))->assertOk());

    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = array_map('str_getcsv', preg_split('/\r?\n/', trim(substr($body, 3))));
    expect($lines[0])->toBe(['Nom complet', 'Groupe', 'Identifiant', 'Courriel', 'Téléphone'])
        ->and($lines[1])->toBe(['Zoé Louis', '4e année', 'E-9', 'zoe@example.ht', '+509 3000 0001'])
        ->and($lines)->toHaveCount(4);
});

it('applies voted, group and q like the voters list, and adds a "has voted" column for voted=all [FR-VOT-06] (scenario 3)', function () {
    [$t, $election, $g4] = exportSignedIn($this);

    $yes = exportRows($this->browser->get(exportUrl($election, '?format=xlsx&voted=yes'))->assertOk());
    $group = exportRows($this->browser->get(exportUrl($election, "?format=xlsx&voted=all&group={$g4}"))->assertOk());
    $none = exportRows($this->browser->get(exportUrl($election, '?format=xlsx&group=none'))->assertOk());
    $search = exportRows($this->browser->get(exportUrl($election, '?format=xlsx&voted=all&q=ben'))->assertOk());

    expect(array_column($yes, 0))->toBe(['Nom complet', 'Ada Joseph'])
        ->and($group[0])->toBe(['Nom complet', 'Groupe', 'Identifiant', 'Courriel', 'Téléphone', 'A voté'])
        ->and(array_column($group, 0))->toBe(['Nom complet', 'Ada Joseph', 'Zoé Louis'])
        ->and(array_column($group, 5))->toBe(['A voté', 'Oui', 'Non'])
        ->and(array_column($none, 0))->toBe(['Nom complet', 'Eve Sans-groupe'])
        ->and(array_column($search, 0))->toBe(['Nom complet', 'Ben Pierre']);
});

it('uses English headings and Yes or No for an English election [FR-VOT-07] (scenario 3)', function () {
    [$t, $election] = exportSignedIn($this, ['language' => 'en']);

    $rows = exportRows($this->browser->get(exportUrl($election, '?format=xlsx&voted=all'))->assertOk());

    expect($rows[0])->toBe(['Full name', 'Group', 'Identifier', 'Email', 'Phone', 'Has voted'])
        ->and(array_column($rows, 5))->toContain('Yes')->toContain('No');
});

it('guards a name or group that could run as a formula, in Excel and CSV [NFR-SEC-06] (scenario 4)', function () {
    [$t, $election] = exportSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => '=HYPERLINK("http://evil.example")', 'identifier' => '@x', 'email' => '+1', 'phone' => '-2']);
    $evil = Accounts::plantGroup(['election' => $election, 'name' => '+cmd']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Ligne piégée', 'group' => $evil]);

    $xlsx = exportRows($this->browser->get(exportUrl($election, '?format=xlsx'))->assertOk());
    $csv = Spreadsheets::body($this->browser->get(exportUrl($election, '?format=csv'))->assertOk());
    $flat = array_merge(...$xlsx);

    expect($flat)->toContain("'=HYPERLINK(\"http://evil.example\")")->toContain("'@x")->toContain("'+1")->toContain("'-2")->toContain("'+cmd")
        ->and($csv)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

it('never writes a code, a hash, a link or a time of vote [FR-SEC-06] (scenario 1)', function () {
    [$t, $election, , $v] = exportSignedIn($this);
    $code = Accounts::plantCredential(['voter' => $v['zoe']]);

    $all = Spreadsheets::body($this->browser->get(exportUrl($election, '?format=csv&voted=all'))->assertOk());

    expect($all)->not->toContain($code)->not->toContain(str_replace('-', '', $code))->not->toContain('13:00')->not->toContain('http');
});

it('answers 422 for a missing or unknown format and an invalid voted or group [FR-VOT-06] (scenario 5)', function (string $query, string $field) {
    [$t, $election] = exportSignedIn($this);

    $response = $this->browser->get(exportUrl($election, $query))->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')->and($response->json("error.fields.{$field}"))->not->toBeNull();
})->with([
    'no format' => ['', 'format'],
    'unknown format' => ['?format=pdf', 'format'],
    'bad voted' => ['?format=csv&voted=maybe', 'voted'],
    'bad group' => ['?format=csv&group=not-a-uuid', 'group'],
]);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(exportUrl(EXPORT_UNKNOWN, '?format=csv')));
    expect($unknown['status'])->toBe(404);

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(exportUrl($target, '?format=csv'))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 as every read, and 429 above 20 exports an hour [NFR-SEC-04, NFR-SEC-05] (scenario 7)', function () {
    [$t, $election] = exportSignedIn($this);

    foreach (range(1, 20) as $i) {
        $this->browser->get(exportUrl($election, '?format=csv'))->assertOk();
    }
    $this->browser->get(exportUrl($election, '?format=csv'))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');

    $this->browser->post(exportUrl($election, '?format=csv'), [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $this->browser->forgetCookies();
    $this->browser->get(exportUrl($election, '?format=csv'))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 for a suspended institution and lets a manager export [FR-VOT-07] (scenario 7)', function () {
    [$t, $election] = exportSignedIn($this);
    Team::signIn($this, $t['a']['manager']);
    $this->browser->get(exportUrl($election, '?format=csv'))->assertOk();

    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(exportUrl($election, '?format=csv'))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
