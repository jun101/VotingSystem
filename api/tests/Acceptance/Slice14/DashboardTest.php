<?php

/*
 * GET /api/v1/dashboard — docs/api/turnout/GET-dashboard.md
 * Tenant suite, route api/v1/dashboard: only the signed-in institution's elections are counted or listed.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Turnout;

function dashboardUrl(): string
{
    return '/api/v1/dashboard';
}

function plantFor(array $t, array $o): string
{
    return Accounts::plantElection($o + ['institution' => $t['a']['owner']['institution'], 'timezone' => 'UTC']);
}

it('counts the elections by status and the voters, lists the open ones with turnout and the scheduled ones with their start [FR-NAV-01, FR-RES-01] (scenarios 1, 3)', function () {
    $t = Team::two();
    $open = plantFor($t, ['title' => 'Conseil', 'status' => 'open', 'starts_at' => now('UTC')->subHour()->toDateTimeString(), 'ends_at' => '2030-03-05 19:00:00']);
    $voters = array_map(fn ($i) => Accounts::plantVoter(['election' => $open]), range(1, 8));
    Turnout::voted($voters[0], now('UTC')->subMinutes(30)->toDateTimeString());
    Turnout::voted($voters[1], now('UTC')->subMinutes(20)->toDateTimeString());
    Turnout::voted($voters[2], now('UTC')->subMinutes(10)->toDateTimeString());
    $scheduled = plantFor($t, ['title' => 'À venir', 'status' => 'scheduled', 'starts_at' => '2030-04-01 12:00:00', 'ends_at' => '2030-04-02 12:00:00']);
    plantFor($t, ['status' => 'draft']);
    plantFor($t, ['status' => 'closed']);
    plantFor($t, ['status' => 'closed']);
    plantFor($t, ['status' => 'published']);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'open', 'starts_at' => now('UTC')->subHour()->toDateTimeString(), 'ends_at' => '2030-03-05 19:00:00']);
    Accounts::plantVoter(['election' => $foreign]);
    Team::signIn($this, $t['a']['owner']);

    $data = $this->browser->get(dashboardUrl())->assertOk()->json('data');

    expect($data['counts'])->toBe(['draft' => 1, 'scheduled' => 1, 'open' => 1, 'closed' => 2, 'published' => 1, 'archived' => 0])
        ->and($data['voters'])->toBe(8)
        ->and($data['open'])->toBe([['id' => $open, 'title' => 'Conseil', 'ends_at' => '2030-03-05T19:00:00Z', 'eligible' => 8, 'voted' => 3, 'percentage' => 37.5]])
        ->and($data['upcoming'])->toBe([['id' => $scheduled, 'title' => 'À venir', 'starts_at' => '2030-04-01T12:00:00Z']])
        ->and(json_encode($data))->not->toContain($foreign);
});

it('does not list as open an election whose end has passed even if the scheduler has not run [FR-ELEC-05] (scenario 1)', function () {
    $t = Team::two();
    plantFor($t, ['status' => 'open', 'starts_at' => now('UTC')->subDays(2)->toDateTimeString(), 'ends_at' => now('UTC')->subMinute()->toDateTimeString()]);
    Team::signIn($this, $t['a']['owner']);

    $data = $this->browser->get(dashboardUrl())->assertOk()->json('data');

    expect($data['open'])->toBe([]);
});

it('lists open elections newest first, at most 10 [FR-NAV-01] (scenario 1)', function () {
    $t = Team::two();
    $ids = [];
    foreach (range(1, 12) as $i) {
        $ids[] = plantFor($t, ['title' => "Élection {$i}", 'status' => 'open', 'starts_at' => now('UTC')->subDay()->toDateTimeString(), 'ends_at' => '2030-03-05 19:00:00', 'created_at' => now('UTC')->subMinutes(100 - $i)->toDateTimeString()]);
    }
    Team::signIn($this, $t['a']['owner']);

    $open = $this->browser->get(dashboardUrl())->assertOk()->json('data.open');

    expect($open)->toHaveCount(10)->and($open[0]['id'])->toBe($ids[11])->and($open[9]['id'])->toBe($ids[2]);
});

it('gives the to-do items: voters not voted, draft blockers and codes missing, each with its election [FR-NAV-01] (scenario 1)', function () {
    $t = Team::two();
    $open = plantFor($t, ['title' => 'Ouverte', 'status' => 'open', 'starts_at' => now('UTC')->subHour()->toDateTimeString(), 'ends_at' => '2030-03-05 19:00:00']);
    $a = Accounts::plantVoter(['election' => $open]);
    $b = Accounts::plantVoter(['election' => $open]);
    $c = Accounts::plantVoter(['election' => $open]);
    Accounts::plantCredential(['voter' => $a]);
    Accounts::plantCredential(['voter' => $b]);
    Turnout::voted($a, now('UTC')->subMinutes(5)->toDateTimeString());
    $draft = plantFor($t, ['title' => 'Brouillon', 'status' => 'draft', 'starts_at' => '2030-03-01 12:00:00', 'ends_at' => '2030-03-05 19:00:00']);
    Team::signIn($this, $t['a']['owner']);

    $todo = collect($this->browser->get(dashboardUrl())->assertOk()->json('data.todo'));
    $by = fn (string $kind, string $election) => $todo->first(fn ($i) => $i['kind'] === $kind && $i['election']['id'] === $election);

    expect($by('voters_not_voted', $open))->toBe(['kind' => 'voters_not_voted', 'election' => ['id' => $open, 'title' => 'Ouverte'], 'count' => 2])
        ->and($by('codes_missing', $open))->toBe(['kind' => 'codes_missing', 'election' => ['id' => $open, 'title' => 'Ouverte'], 'count' => 1])
        ->and($by('draft_blockers', $draft))->toBe(['kind' => 'draft_blockers', 'election' => ['id' => $draft, 'title' => 'Brouillon'], 'count' => 2])
        ->and($by('draft_blockers', $open))->toBeNull();
});

it('answers zeros and empty lists for an institution with no election [FR-NAV-01] (scenario 2)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $data = $this->browser->get(dashboardUrl())->assertOk()->json('data');

    expect($data)->toBe(['counts' => ['draft' => 0, 'scheduled' => 0, 'open' => 0, 'closed' => 0, 'published' => 0, 'archived' => 0], 'voters' => 0, 'open' => [], 'upcoming' => [], 'todo' => []]);
});

it('answers 401, 403 and 405 as every read, and a manager may read it [NFR-SEC-04] (scenario 4)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);
    $this->browser->get(dashboardUrl())->assertOk();
    $this->browser->post(dashboardUrl(), [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(dashboardUrl())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    $this->browser->forgetCookies();
    $this->browser->get(dashboardUrl())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});
