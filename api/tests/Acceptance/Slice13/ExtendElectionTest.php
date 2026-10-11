<?php

/*
 * POST /api/v1/elections/{election}/extend — docs/api/elections/POST-elections-{election}-extend.md
 * Tenant suite, route api/v1/elections/{election}/extend: another institution's election answers 404, the same as an
 * unknown one.
 */

use Carbon\Carbon;
use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Voting;

const EXTEND_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function extendUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/extend";
}

/** An owner of A signed in and an open election of A that ends at `$end` (default: in two days). Returns [team, voting data, end]. */
function extendSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $end = Carbon::parse($options['ends_at'] ?? now('UTC')->addDays(2)->startOfSecond(), 'UTC');
    $e = Voting::election(['ends_at' => $end->toDateTimeString()] + $options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $e, $end];
}

function iso(Carbon $at): string
{
    return $at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
}

it('moves the end of an open election later and keeps its status [FR-ELEC-05] (scenario 1)', function () {
    [$t, $e, $end] = extendSignedIn($this);
    $new = $end->copy()->addHours(5);

    $response = $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($new)])->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $e['election'], 'status' => 'open', 'ends_at' => iso($new)])
        ->and(Accounts::electionRow($e['election'])['ends_at'])->toBe($new->toDateTimeString())
        ->and(Accounts::electionRow($e['election'])['status'])->toBe('open');
});

it('moves the end of a scheduled election later [FR-ELEC-05] (scenario 2)', function () {
    [$t, $e, $end] = extendSignedIn($this, ['status' => 'scheduled', 'starts_at' => now('UTC')->addDay()->toDateTimeString()]);
    $new = $end->copy()->addDay();

    $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($new)])->assertOk()->assertJsonPath('data.status', 'scheduled');

    expect(Accounts::electionRow($e['election'])['ends_at'])->toBe($new->toDateTimeString());
});

it('lets a manager extend, and reads an end given with an offset [FR-ELEC-05] (scenario 1)', function () {
    [$t, $e, $end] = extendSignedIn($this);
    Team::signIn($this, $t['a']['manager']);
    $new = $end->copy()->addHours(3);

    $this->browser->post(extendUrl($e['election']), ['ends_at' => $new->copy()->setTimezone('-05:00')->format('Y-m-d\TH:i:sP')])->assertOk();

    expect(Accounts::electionRow($e['election'])['ends_at'])->toBe($new->toDateTimeString());
});

it('accepts an end exactly 30 days after the current one [FR-ELEC-05] (scenario 1)', function () {
    [$t, $e, $end] = extendSignedIn($this);

    $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($end->copy()->addDays(30))])->assertOk();
});

it('answers 409 election_not_extendable for an open election whose end has just passed [FR-ELEC-05] (scenario 3)', function () {
    [$t, $e] = extendSignedIn($this, ['ends_at' => now('UTC')->subMinute()->startOfSecond()]);

    $this->browser->post(extendUrl($e['election']), ['ends_at' => iso(now('UTC')->addDay())])->assertStatus(409)->assertJsonPath('error.code', 'election_not_extendable');
});

it('answers 422 ends_at not_after for an end equal to or before the current end [FR-ELEC-05] (scenario 4)', function (string $shift) {
    [$t, $e, $end] = extendSignedIn($this);

    $response = $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($end->copy()->modify($shift))])->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.fields.ends_at'))->toContain('not_after')
        ->and(Accounts::electionRow($e['election'])['ends_at'])->toBe($end->toDateTimeString());
})->with(['the same end' => ['+0 seconds'], 'one second earlier' => ['-1 second'], 'a day earlier' => ['-1 day']]);

it('answers 422 ends_at too_far for an end more than 30 days after the current one [FR-ELEC-05] (scenario 5)', function () {
    [$t, $e, $end] = extendSignedIn($this);

    $response = $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($end->copy()->addDays(30)->addSecond())])->assertStatus(422);

    expect($response->json('error.fields.ends_at'))->toContain('too_far')
        ->and(Accounts::electionRow($e['election'])['ends_at'])->toBe($end->toDateTimeString());
});

it('answers 422 for a missing or invalid ends_at [FR-ELEC-05] (scenario 6)', function (array $body, string $code) {
    [$t, $e] = extendSignedIn($this);

    $this->browser->post(extendUrl($e['election']), $body)->assertStatus(422)->assertJsonPath('error.fields.ends_at.0', $code);
})->with([
    'missing' => [[], 'required'],
    'empty' => [['ends_at' => ''], 'required'],
    'not a date' => [['ends_at' => 'demain'], 'invalid'],
    'a number' => [['ends_at' => 12], 'invalid'],
]);

it('answers 409 election_not_extendable for a draft, closed, published or archived election [FR-ELEC-05] (scenario 7)', function (string $status) {
    [$t, $e, $end] = extendSignedIn($this, ['status' => $status]);

    $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($end->copy()->addDay())])->assertStatus(409)->assertJsonPath('error.code', 'election_not_extendable');

    expect(Accounts::electionRow($e['election'])['ends_at'])->toBe($end->toDateTimeString());
})->with(['draft', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreign = Voting::election(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    $body = ['ends_at' => iso(now('UTC')->addDays(3))];

    $unknown = Team::shape($this->browser->post(extendUrl(EXTEND_UNKNOWN), $body));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign['election'], 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(extendUrl($target), $body)))->toBe($unknown);
    }
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenario 9)', function () {
    [$t, $e, $end] = extendSignedIn($this);
    $body = ['ends_at' => iso($end->copy()->addDay())];
    $this->browser->forgetCookies();

    $this->browser->post(extendUrl($e['election']), $body)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(extendUrl($e['election']), $body, [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', extendUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(extendUrl($e['election']), $body)->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($e['election'])['ends_at'])->toBe($end->toDateTimeString());
});

it('answers 429 above 20 requests an hour from one user [NFR-SEC-05] (scenario 9)', function () {
    [$t, $e, $end] = extendSignedIn($this);
    foreach (range(1, 20) as $i) {
        $this->browser->post(extendUrl($e['election']), ['ends_at' => 'non'])->assertStatus(422);
    }

    $this->browser->post(extendUrl($e['election']), ['ends_at' => iso($end->copy()->addDay())])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(Accounts::electionRow($e['election'])['ends_at'])->toBe($end->toDateTimeString());
});
