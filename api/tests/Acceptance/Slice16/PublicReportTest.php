<?php

/*
 * GET /api/v1/public/elections/{election}/report — docs/api/publication/GET-public-elections-{election}-report.md
 * Public route api/v1/public/elections/{election}/report.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Pdfs;
use Tests\Support\Publication;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;

function publicReportUrl(string $uuid): string
{
    return "/api/v1/public/elections/{$uuid}/report";
}

it('gives the PDF of a published election to anyone, with the counts of a full display [FR-RES-06] (scenario 1)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published', 37, 11, 2);

    $response = (new AuthClient($this))->get(publicReportUrl($e['election']))->assertOk();
    $pdf = Spreadsheets::body($response);
    $text = Pdfs::text($pdf);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="rapport-conseil-des-eleves-2026.pdf"')
        ->and(Pdfs::isPdf($pdf))->toBeTrue()
        ->and($text)->toContain('Conseil des élèves 2026')->toContain('Pierre-Louis')->toContain('37')->toContain('11');
});

it('leaves the counts out for a winners display [FR-RES-08] (scenario 1)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published', 37, 11, 2, ['results_display' => 'winners']);

    $text = Pdfs::text(Spreadsheets::body((new AuthClient($this))->get(publicReportUrl($e['election']))->assertOk()));

    expect($text)->toContain('Pierre-Louis')->not->toContain('37')->not->toContain('11');
});

it('answers 404 for any status but published, unknown, not a UUID and a suspended institution [FR-SEC-03] (scenario 2)', function () {
    $t = Team::two();
    $anonymous = new AuthClient($this);
    $unknown = Team::shape($anonymous->get(publicReportUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404);

    foreach (['draft', 'open', 'closed', 'archived'] as $status) {
        $e = Publication::election($t['a']['owner']['institution'], $status);
        expect(Team::shape($anonymous->get(publicReportUrl($e['election']))))->toBe($unknown);
    }
    expect(Team::shape($anonymous->get(publicReportUrl('not-a-uuid'))))->toBe($unknown);

    $published = Publication::election($t['a']['owner']['institution'], 'published');
    Accounts::suspend($t['a']['owner']['institution']);
    $anonymous->get(publicReportUrl($published['election']))->assertStatus(404);
});

it('answers 429 above 30 requests a minute from one address and 405 for another method [NFR-SEC-05] (scenario 3)', function () {
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published');
    $anonymous = (new AuthClient($this))->fromAddress('10.31.31.31');
    $anonymous->other('POST', publicReportUrl($e['election']))->assertStatus(405);

    foreach (range(1, 30) as $i) {
        $anonymous->get(publicReportUrl($e['election']))->assertOk();
    }

    $anonymous->get(publicReportUrl($e['election']))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
