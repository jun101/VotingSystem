<?php

/*
 * GET /api/v1/elections — docs/api/elections/GET-elections.md
 * Tenant suite, route api/v1/elections: another institution's elections are never listed, counted or
 * given as a year (scenario 6).
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const ELECTIONS = '/api/v1/elections';

/** A fixture: owner of institution A signed in, with elections in several statuses and years. */
function listFixture($test): array
{
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    $ids = [
        'open' => Accounts::plantElection(['institution' => $a, 'title' => 'Ouverte', 'status' => 'open', 'starts_at' => '2026-10-12 12:00:00', 'ends_at' => '2026-10-16 19:00:00', 'created_at' => '2026-10-01 10:00:00']),
        'draft_new' => Accounts::plantElection(['institution' => $a, 'title' => 'Brouillon récent', 'status' => 'draft', 'starts_at' => '2026-12-01 12:00:00', 'ends_at' => '2026-12-02 12:00:00', 'created_at' => '2026-10-02 10:00:00']),
        'draft_old' => Accounts::plantElection(['institution' => $a, 'title' => 'Brouillon ancien', 'status' => 'draft', 'starts_at' => '2026-06-01 12:00:00', 'ends_at' => '2026-06-02 12:00:00', 'created_at' => '2026-10-03 10:00:00']),
        'scheduled' => Accounts::plantElection(['institution' => $a, 'title' => 'Planifiée', 'status' => 'scheduled', 'starts_at' => '2026-11-10 12:00:00', 'ends_at' => '2026-11-11 12:00:00']),
        'closed' => Accounts::plantElection(['institution' => $a, 'title' => 'Close', 'status' => 'closed', 'starts_at' => '2025-11-03 12:00:00', 'ends_at' => '2025-11-05 12:00:00']),
        'published_1' => Accounts::plantElection(['institution' => $a, 'title' => 'Publiée 1', 'status' => 'published', 'starts_at' => '2025-10-13 12:00:00', 'ends_at' => '2025-10-17 12:00:00']),
        'published_2' => Accounts::plantElection(['institution' => $a, 'title' => 'Publiée 2', 'status' => 'published', 'starts_at' => '2025-03-01 12:00:00', 'ends_at' => '2025-03-02 12:00:00']),
        'archived' => Accounts::plantElection(['institution' => $a, 'title' => 'Archivée', 'status' => 'archived', 'starts_at' => '2024-09-02 12:00:00', 'ends_at' => '2024-09-02 18:00:00']),
    ];
    Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'title' => 'Chez l\'autre', 'status' => 'open', 'starts_at' => '2023-05-01 12:00:00', 'ends_at' => '2023-05-02 12:00:00']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $ids];
}

it('lists the elections but the archived ones, in the order of the file, with the shape of the file [FR-ELEC-07] (scenario 1)', function () {
    [$t, $ids] = listFixture($this);

    $response = $this->browser->get(ELECTIONS)->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_column($response->json('data'), 'title'))->toBe(['Ouverte', 'Planifiée', 'Brouillon récent', 'Brouillon ancien', 'Close', 'Publiée 1', 'Publiée 2'])
        ->and($response->json('meta.page'))->toBe(1)
        ->and($response->json('meta.per_page'))->toBe(25)
        ->and($response->json('meta.total'))->toBe(7)
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'title', 'description', 'status', 'starts_at', 'ends_at', 'timezone', 'language', 'candidate_order', 'results_display', 'cover', 'ballots_count', 'voters_count', 'created_at'])
        ->and($response->json('data.0'))->toMatchArray(['id' => $ids['open'], 'status' => 'open', 'starts_at' => '2026-10-12T12:00:00Z', 'ends_at' => '2026-10-16T19:00:00Z', 'timezone' => 'America/Port-au-Prince', 'ballots_count' => 0, 'voters_count' => 0, 'cover' => null])
        ->and($response->json('data.0.id'))->toMatch(UUID_V4);
});

it('counts every status, ignoring the filters, with `all` leaving the archived out, and lists the years newest first [FR-ELEC-07] (scenario 1)', function () {
    [$t, $ids] = listFixture($this);

    $meta = $this->browser->get(ELECTIONS.'?status=published&year=2025')->assertOk()->json('meta');

    expect($meta['total'])->toBe(2)
        ->and($meta['counts'])->toBe(['all' => 7, 'draft' => 2, 'scheduled' => 1, 'open' => 1, 'closed' => 1, 'published' => 2, 'archived' => 1])
        ->and($meta['years'])->toBe([2026, 2025, 2024]);
});

it('filters by status, and shows the archived ones only when asked [FR-ELEC-07] (scenario 2)', function () {
    [$t, $ids] = listFixture($this);

    $drafts = $this->browser->get(ELECTIONS.'?status=draft')->assertOk();
    expect(array_column($drafts->json('data'), 'title'))->toBe(['Brouillon récent', 'Brouillon ancien'])
        ->and($drafts->json('meta.total'))->toBe(2);

    $archived = $this->browser->get(ELECTIONS.'?status=archived')->assertOk();
    expect(array_column($archived->json('data'), 'title'))->toBe(['Archivée']);
});

it('filters by the year of the start [FR-ELEC-07] (scenario 3)', function () {
    [$t, $ids] = listFixture($this);

    $response = $this->browser->get(ELECTIONS.'?year=2025')->assertOk();

    expect(array_column($response->json('data'), 'title'))->toBe(['Close', 'Publiée 1', 'Publiée 2'])
        ->and($response->json('meta.total'))->toBe(3);
    // The archived election of 2024 is not in the default list, so the year 2024 gives nothing.
    expect($this->browser->get(ELECTIONS.'?year=2024')->json('data'))->toBe([]);
});

it('combines status and year [FR-ELEC-07] (scenario 4)', function () {
    [$t, $ids] = listFixture($this);

    $response = $this->browser->get(ELECTIONS.'?status=draft&year=2026')->assertOk();
    expect($response->json('meta.total'))->toBe(2);

    expect($this->browser->get(ELECTIONS.'?status=open&year=2025')->json('data'))->toBe([])
        ->and($this->browser->get(ELECTIONS.'?status=archived&year=2024')->json('meta.total'))->toBe(1);
});

it('orders elections of one status by the latest start, then the latest creation [FR-ELEC-07] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    Accounts::plantElection(['institution' => $a, 'title' => 'B', 'starts_at' => '2026-05-01 12:00:00', 'ends_at' => '2026-05-02 12:00:00', 'created_at' => '2026-01-01 10:00:00']);
    Accounts::plantElection(['institution' => $a, 'title' => 'C', 'starts_at' => '2026-05-01 12:00:00', 'ends_at' => '2026-05-02 12:00:00', 'created_at' => '2026-02-01 10:00:00']);
    Accounts::plantElection(['institution' => $a, 'title' => 'A', 'starts_at' => '2026-07-01 12:00:00', 'ends_at' => '2026-07-02 12:00:00', 'created_at' => '2026-01-15 10:00:00']);
    Team::signIn($this, $t['a']['owner']);

    expect(array_column($this->browser->get(ELECTIONS)->json('data'), 'title'))->toBe(['A', 'C', 'B']);
});

it('answers an empty list and zero counts for an institution with no election [FR-ELEC-07] (scenario 5)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(ELECTIONS)->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 25, 'total' => 0, 'counts' => ['all' => 0, 'draft' => 0, 'scheduled' => 0, 'open' => 0, 'closed' => 0, 'published' => 0, 'archived' => 0], 'years' => []]);
});

it('shows a manager the same list as an owner [FR-ELEC-07] (scenario 1)', function () {
    $t = Team::two();
    Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Visible']);
    Team::signIn($this, $t['a']['manager']);

    expect(array_column($this->browser->get(ELECTIONS)->assertOk()->json('data'), 'title'))->toBe(['Visible']);
});

it('lists only this institution\'s elections, counts and years [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'De A', 'starts_at' => '2026-05-01 12:00:00', 'ends_at' => '2026-05-02 12:00:00']);
    Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'title' => 'De B', 'status' => 'published', 'starts_at' => '2019-05-01 12:00:00', 'ends_at' => '2019-05-02 12:00:00']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(ELECTIONS)->assertOk();

    expect(array_column($response->json('data'), 'title'))->toBe(['De A'])
        ->and($response->json('meta.counts.published'))->toBe(0)
        ->and($response->json('meta.counts.all'))->toBe(1)
        ->and($response->json('meta.years'))->toBe([2026])
        ->and($response->getContent())->not->toContain('De B');
});

it('paginates [FR-ELEC-07] (scenario 1)', function () {
    $t = Team::two();
    foreach (range(1, 7) as $i) {
        Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => "E{$i}", 'starts_at' => "2026-0{$i}-01 12:00:00", 'ends_at' => "2026-0{$i}-02 12:00:00"]);
    }
    Team::signIn($this, $t['a']['owner']);

    $page = $this->browser->get(ELECTIONS.'?per_page=3&page=3')->assertOk();

    expect($page->json('meta.page'))->toBe(3)
        ->and($page->json('meta.per_page'))->toBe(3)
        ->and($page->json('meta.total'))->toBe(7)
        ->and(array_column($page->json('data'), 'title'))->toBe(['E1']);
});

it('answers 422 for a bad status, year, page or page size [FR-ELEC-07] (scenario 7)', function (string $query, string $field) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(ELECTIONS.$query);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields'))->toHaveKey($field);
})->with([['?status=sleeping', 'status'], ['?year=26', 'year'], ['?year=abcd', 'year'], ['?per_page=101', 'per_page'], ['?per_page=0', 'per_page'], ['?page=0', 'page']]);

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-07] (scenario 8)', function () {
    $this->browser->get(ELECTIONS)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 9)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(ELECTIONS)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 to another method than GET, HEAD or POST [NFR-SEC-01] (scenario 10)', function (string $method) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, ELECTIONS);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('GET')->toContain('POST')->not->toContain($method);
})->with(['PUT', 'PATCH', 'DELETE']);
