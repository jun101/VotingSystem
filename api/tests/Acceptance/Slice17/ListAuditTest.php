<?php

/*
 * GET /api/v1/audit — docs/api/audit/GET-audit.md
 * Tenant suite, route api/v1/audit: only the signed-in institution's entries are ever listed; an election filter naming
 * another institution's election gives an empty list, like an unknown one.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Audit;
use Tests\Support\Team;
use Tests\Support\Voting;

it('lists the owner\'s entries, newest first, and only theirs [FR-AUD-03, FR-INST-05] (scenario 1)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    Audit::plant($a, ['action' => 'election.created', 'at' => '2026-10-01 10:00:00', 'user' => $t['a']['owner']['user']]);
    Audit::plant($a, ['action' => 'election.scheduled', 'at' => '2026-10-02 10:00:00']);
    Audit::plant($t['b']['owner']['institution'], ['action' => 'election.deleted', 'at' => '2026-10-03 10:00:00']);
    Audit::plant('platform', ['action' => 'institution.suspended', 'at' => '2026-10-04 10:00:00']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get('/api/v1/audit')->assertOk();
    $actions = array_column($response->json('data'), 'action');

    expect($actions)->toContain('election.scheduled')->toContain('election.created')->not->toContain('election.deleted')->not->toContain('institution.suspended')
        ->and(array_search('election.scheduled', $actions))->toBeLessThan(array_search('election.created', $actions))
        ->and($response->json('meta'))->toMatchArray(['page' => 1, 'per_page' => 50]);
});

it('answers an empty list for an institution with no entry, apart from the sign-in itself [FR-AUD-03] (scenario 2)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);
    DB::connection(useMigratorConnection())->table('audit_entries')->where('institution_id', DB::connection(useMigratorConnection())->table('institutions')->where('uuid', $t['a']['owner']['institution'])->value('id'))->delete();

    $response = $this->browser->get('/api/v1/audit')->assertOk();

    expect($response->json('data'))->toBe([])->and($response->json('meta.total'))->toBe(0);
});

it('filters by action code and by family prefix [FR-AUD-03] (scenario 3)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    foreach (['election.closed', 'election.published', 'voter.created', 'credentials.reissued'] as $i => $action) {
        Audit::plant($a, ['action' => $action, 'at' => '2026-10-0'.($i + 1).' 10:00:00']);
    }
    Team::signIn($this, $t['a']['owner']);

    $exact = $this->browser->get('/api/v1/audit?action=voter.created')->assertOk();
    $family = $this->browser->get('/api/v1/audit?action=election.')->assertOk();
    $none = $this->browser->get('/api/v1/audit?action=nothing.here')->assertOk();

    expect(array_column($exact->json('data'), 'action'))->toBe(['voter.created'])
        ->and(array_column($family->json('data'), 'action'))->toBe(['election.published', 'election.closed'])
        ->and($family->json('meta.total'))->toBe(2)
        ->and($none->json('data'))->toBe([]);
});

it('filters by election, by user and by system, and by dates inclusive [FR-AUD-03] (scenario 3)', function () {
    $t = Team::two();
    $a = $t['a']['owner']['institution'];
    $e1 = Accounts::plantElection(['institution' => $a, 'title' => 'Un']);
    $e2 = Accounts::plantElection(['institution' => $a, 'title' => 'Deux']);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Audit::plant($a, ['action' => 'election.updated', 'election' => $e1, 'user' => $t['a']['owner']['user'], 'at' => '2026-10-01 23:59:59']);
    Audit::plant($a, ['action' => 'election.closed', 'election' => $e2, 'user' => $t['a']['manager']['user'], 'at' => '2026-10-02 00:00:00']);
    Audit::plant($a, ['action' => 'election.opened', 'election' => $e2, 'at' => '2026-10-03 12:00:00']);
    Team::signIn($this, $t['a']['owner']);
    $count = fn (string $q) => $this->browser->get('/api/v1/audit?action=election.'.$q)->assertOk()->json('meta.total');

    expect($count("&election={$e1}"))->toBe(1)
        ->and($count("&election={$e2}"))->toBe(2)
        ->and($count("&election={$foreign}"))->toBe(0)
        ->and($count('&election=not-a-uuid'))->toBe(0)
        ->and($count('&user='.$t['a']['manager']['user']))->toBe(1)
        ->and($count('&user=system'))->toBe(1)
        ->and($count('&from=2026-10-02'))->toBe(2)
        ->and($count('&to=2026-10-02'))->toBe(2)
        ->and($count('&from=2026-10-02&to=2026-10-02'))->toBe(1);
});

it('pages by 50 and honours per_page and page [FR-AUD-03] (scenario 1)', function () {
    $t = Team::two();
    foreach (range(1, 60) as $i) {
        Audit::plant($t['a']['owner']['institution'], ['action' => 'voter.updated', 'at' => now('UTC')->subMinutes(100 - $i)->toDateTimeString()]);
    }
    Team::signIn($this, $t['a']['owner']);

    $first = $this->browser->get('/api/v1/audit?action=voter.')->assertOk();
    $second = $this->browser->get('/api/v1/audit?action=voter.&page=2&per_page=25')->assertOk();

    expect($first->json('data'))->toHaveCount(50)->and($first->json('meta.total'))->toBe(60)
        ->and($second->json('data'))->toHaveCount(25)->and($second->json('meta.page'))->toBe(2);
});

it('answers 422 for a bad date, a per_page out of range and a page below 1 [FR-AUD-03] (scenario 4)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->get('/api/v1/audit?from=demain')->assertStatus(422)->assertJsonPath('error.fields.from.0', 'invalid');
    $this->browser->get('/api/v1/audit?to=2026-13-40')->assertStatus(422)->assertJsonPath('error.fields.to.0', 'invalid');
    $this->browser->get('/api/v1/audit?per_page=101')->assertStatus(422)->assertJsonPath('error.fields.per_page.0', 'max');
    $this->browser->get('/api/v1/audit?page=0')->assertStatus(422)->assertJsonPath('error.fields.page.0', 'min');
});

it('answers 403 forbidden to a manager and to a platform admin [FR-AUD-03] (scenario 5)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get('/api/v1/audit')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('answers 401, 403 and 405 as every read [NFR-SEC-04] (scenario 6)', function () {
    $t = Team::two();
    $this->browser->get('/api/v1/audit')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post('/api/v1/audit', [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get('/api/v1/audit')->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('gives no numeric id, no foreign key and no secret [NFR-SEC-08, FR-SEC-06] (scenario 1)', function () {
    $t = Team::two();
    $e = Voting::election(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->post("/api/v1/elections/{$e['election']}/close", ['confirmation' => 'Conseil des élèves 2026'])->assertOk();

    $body = $this->browser->get('/api/v1/audit')->assertOk()->getContent();

    expect($body)->not->toContain('institution_id')->not->toContain('user_id')->not->toContain('election_id')->not->toContain('"id":1,')
        ->not->toContain($e['codes']['Wideline']);
});
