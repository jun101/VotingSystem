<?php

/*
 * The year filter and `meta.years` read the start in the election's own time zone —
 * docs/api/elections/GET-elections.md (Request), docs/slices/05-elections.md (rule 4).
 * Route in the tenant suite: api/v1/elections.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

it('counts an election in the year of its own time zone, not of UTC [FR-ELEC-07, FR-ELEC-02]', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    // Port-au-Prince is UTC-5 in January: 04:30 UTC on 1 January is still 31 December there.
    Accounts::plantElection(['institution' => $a, 'title' => 'Encore 2025', 'timezone' => 'America/Port-au-Prince', 'starts_at' => '2026-01-01 04:30:00', 'ends_at' => '2026-01-01 20:00:00']);
    // ... and 05:30 UTC is 00:30 on 1 January.
    Accounts::plantElection(['institution' => $a, 'title' => 'Déjà 2026', 'timezone' => 'America/Port-au-Prince', 'starts_at' => '2026-01-01 05:30:00', 'ends_at' => '2026-01-01 20:00:00']);
    // Auckland is UTC+13 in January: 12:00 UTC on 31 December is 01:00 on 1 January there.
    Accounts::plantElection(['institution' => $a, 'title' => 'Auckland', 'timezone' => 'Pacific/Auckland', 'starts_at' => '2025-12-31 12:00:00', 'ends_at' => '2026-01-01 12:00:00']);
    Team::signIn($this, $t['a']['owner']);

    $y2026 = $this->browser->get('/api/v1/elections?year=2026')->assertOk();
    $y2025 = $this->browser->get('/api/v1/elections?year=2025')->assertOk();

    expect(array_column($y2026->json('data'), 'title'))->toEqualCanonicalizing(['Déjà 2026', 'Auckland'])
        ->and(array_column($y2025->json('data'), 'title'))->toBe(['Encore 2025'])
        ->and($y2026->json('meta.years'))->toBe([2026, 2025]);
});

it('moves with the time zone when an election is edited [FR-ELEC-02]', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'timezone' => 'America/Port-au-Prince', 'starts_at' => '2026-01-01 04:30:00', 'ends_at' => '2026-01-01 20:00:00']);
    Team::signIn($this, $t['a']['owner']);
    expect($this->browser->get('/api/v1/elections?year=2025')->json('meta.total'))->toBe(1);

    $this->browser->patch("/api/v1/elections/{$id}", ['timezone' => 'Europe/Paris'])->assertOk();

    // In Paris (UTC+1) the same instant is 05:30 on 1 January: 2026.
    expect($this->browser->get('/api/v1/elections?year=2025')->json('meta.total'))->toBe(0)
        ->and($this->browser->get('/api/v1/elections?year=2026')->json('meta.total'))->toBe(1);
});
