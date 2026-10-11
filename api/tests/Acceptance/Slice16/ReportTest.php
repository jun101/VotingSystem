<?php

/*
 * GET /api/v1/elections/{election}/report — docs/api/publication/GET-elections-{election}-report.md
 * Tenant suite, route api/v1/elections/{election}/report: another institution's election answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Pdfs;
use Tests\Support\Publication;
use Tests\Support\Results;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

function reportUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/report";
}

it('gives a US Letter PDF with the title, the institution, the turnout and every ballot with its counts [FR-RES-06] (scenario 1)', function (string $status) {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], $status, 37, 11, 2, ['results_display' => 'winners']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(reportUrl($e['election']))->assertOk();
    $pdf = Spreadsheets::body($response);
    $text = Pdfs::text($pdf);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="rapport-conseil-des-eleves-2026.pdf"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(Pdfs::isPdf($pdf))->toBeTrue()
        ->and(Pdfs::isLetter($pdf))->toBeTrue()
        ->and($text)->toContain('Conseil des élèves 2026')->toContain('Président(e)')->toContain('Pierre-Louis')->toContain('37')->toContain('11')
        ->and($text)->not->toContain($e['election'])->not->toContain($e['ballots']['P']);
})->with(['closed', 'published', 'archived']);

it('says Tie in the report and keeps both candidates [FR-RES-03] (scenario 1)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'closed', 4, 4, 0);
    Team::signIn($this, $t['a']['owner']);

    $text = Pdfs::text(Spreadsheets::body($this->browser->get(reportUrl($e['election']))->assertOk()));

    expect($text)->toContain('Pierre-Louis')->toContain('Désir')->toMatch('/[ÉE]galit/u');
});

it('answers 409 results_not_available for a draft, scheduled or open election [FR-SEC-03] (scenario 2)', function (string $status) {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], $status);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->get(reportUrl($e['election']))->assertStatus(409)->assertJsonPath('error.code', 'results_not_available');
})->with(['draft', 'scheduled', 'open']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    $t = Team::two();
    $foreign = Publication::election($t['b']['owner']['institution']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(reportUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404);

    foreach ([$foreign['election'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(reportUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405, lets a manager download, and answers 429 above 20 an hour [NFR-SEC-04, NFR-SEC-05] (scenario 4)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'closed');
    $this->browser->get(reportUrl($e['election']))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    Team::signIn($this, $t['a']['manager']);
    $this->browser->post(reportUrl($e['election']), [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');

    foreach (range(1, 20) as $i) {
        $this->browser->get(reportUrl($e['election']))->assertOk();
    }
    $this->browser->get(reportUrl($e['election']))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');

    $this->browser->forgetCookies();
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(reportUrl($e['election']))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
