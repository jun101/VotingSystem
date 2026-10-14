<?php

/*
 * PUT /api/v1/elections/{election}/ballots/order — docs/api/ballots/PUT-elections-{election}-ballots-order.md
 * Tenant suite, route api/v1/elections/{election}/ballots/order: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const ORDER_UNKNOWN_ELECTION = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function orderUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/ballots/order";
}

/** A draft of A with three ballots at positions 1, 2, 3, signed in as the owner. Returns [team, election, [a, b, c]]. */
function orderSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $ids = [
        Accounts::plantBallot(['election' => $election, 'title' => 'A', 'position' => 1]),
        Accounts::plantBallot(['election' => $election, 'title' => 'B', 'position' => 2]),
        Accounts::plantBallot(['election' => $election, 'title' => 'C', 'position' => 3]),
    ];
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ids];
}

it('saves a new order and answers the list in that order [FR-BAL-01] (scenario 1)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);

    $response = $this->browser->put(orderUrl($election), ['ballots' => [$c, $a, $b]])->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$c, $a, $b])
        ->and(array_column($response->json('data'), 'position'))->toBe([1, 2, 3])
        ->and(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$c, $a, $b])
        ->and($response->json('meta.total'))->toBe(3);
});

it('answers 200 and changes nothing for the stored order [FR-BAL-01] (scenario 2)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);

    $this->browser->put(orderUrl($election), ['ballots' => [$a, $b, $c]])->assertOk();

    expect(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$a, $b, $c]);
});

it('answers 200 with an empty list for an election with no ballot [FR-BAL-01] (scenario 6)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->put(orderUrl($election), ['ballots' => []])->assertOk()->assertJsonPath('data', []);
});

it('answers 422 when ballots is missing, not an array or holds a non-UUID [FR-BAL-01] (scenario 3)', function (array $body, string $field, string $rule) {
    [$t, $election, $ids] = orderSignedIn($this);

    $response = $this->browser->put(orderUrl($election), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    // The field names are flat keys that can hold a dot ("ballots.0"), so read the map, not a dotted path.
    expect($response->json('error.fields')[$field] ?? [])->toContain($rule)
        ->and(array_column(Accounts::ballotRows($election), 'uuid'))->toBe($ids);
})->with([
    'missing' => [[], 'ballots', 'required'],
    'not an array' => [['ballots' => 'abc'], 'ballots', 'array'],
    'not a uuid' => [['ballots' => ['not-a-uuid']], 'ballots.0', 'uuid'],
]);

it('answers 422 set_mismatch when the set is not exactly the election\'s ballots [FR-BAL-01] (scenario 4)', function (string $case) {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $elsewhere = Accounts::plantBallot(['election' => $otherElection]);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantBallot(['election' => $foreignElection]);
    $bodies = [
        'repeated' => [$a, $a, $b],
        'missing' => [$a, $b],
        'extra unknown' => [$a, $b, $c, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa'],
        'another election' => [$a, $b, $elsewhere],
        'another institution' => [$a, $b, $foreign],
    ];

    $response = $this->browser->put(orderUrl($election), ['ballots' => $bodies[$case]]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.ballots'))->toContain('set_mismatch')
        ->and(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$a, $b, $c]);
})->with(['repeated', 'missing', 'extra unknown', 'another election', 'another institution']);

it('gives the same 422 for another institution\'s ballot as for an unknown one [FR-INST-05] (scenario 4)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantBallot(['election' => $foreignElection]);

    $withForeign = Team::shape($this->browser->put(orderUrl($election), ['ballots' => [$a, $b, $foreign]]));
    $withUnknown = Team::shape($this->browser->put(orderUrl($election), ['ballots' => [$a, $b, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']]));

    expect($withForeign)->toBe($withUnknown)
        ->and($withForeign['status'])->toBe(422);
});

it('answers 409 for an election that is not a draft, and keeps the order [FR-SEC-06] (scenario 5)', function (string $status) {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this, $status);

    $response = $this->browser->put(orderUrl($election), ['ballots' => [$c, $b, $a]]);

    $response->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$a, $b, $c]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $kept = Accounts::plantBallot(['election' => $foreign, 'title' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->put(orderUrl(ORDER_UNKNOWN_ELECTION), ['ballots' => []]));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->put(orderUrl($target), ['ballots' => [$kept]])))->toBe($unknown);
    }
});

it('answers 401 when nobody is signed in [FR-BAL-01] (scenario 8)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->put(orderUrl($election), ['ballots' => []])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 9)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->put(orderUrl($election), ['ballots' => [$c, $b, $a]])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$a, $b, $c]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 10)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);

    $this->browser->put(orderUrl($election), ['ballots' => [$c, $b, $a]], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 11)', function () {
    [$t, $election] = orderSignedIn($this);

    $this->browser->rawBody('PUT', orderUrl($election), '{"ballots": [')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this);

    foreach (range(1, 240) as $i) {
        $this->browser->put(orderUrl($election), ['ballots' => $i % 2 === 0 ? [$a, $b, $c] : [$c, $b, $a]])->assertOk();
    }

    $response = $this->browser->put(orderUrl($election), ['ballots' => [$b, $a, $c]]);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$a, $b, $c]);
});

it('answers 405 for another method than PUT [FR-BAL-01] (scenario 13)', function (string $method) {
    [$t, $election] = orderSignedIn($this);

    $this->browser->other($method, orderUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'POST', 'PATCH', 'DELETE']);

it('answers 422 for more than 50 items, before looking at each one [FR-BAL-01] (scenario 3)', function () {
    [$t, $election] = orderSignedIn($this);

    $response = $this->browser->put(orderUrl($election), ['ballots' => array_fill(0, 51, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.ballots'))->toContain('max');
});

it('answers 422 set_mismatch, not 409, for a wrong set on an election that is not a draft [FR-BAL-01] (scenario 5b)', function () {
    [$t, $election, [$a, $b, $c]] = orderSignedIn($this, 'scheduled');

    $response = $this->browser->put(orderUrl($election), ['ballots' => [$a, $b, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']]);

    $response->assertStatus(422);
    expect($response->json('error.fields.ballots'))->toContain('set_mismatch')
        ->and(array_column(Accounts::ballotRows($election), 'uuid'))->toBe([$a, $b, $c]);
});
