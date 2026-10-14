<?php

/*
 * PUT /api/v1/ballots/{ballot}/candidates/order — docs/api/candidates/PUT-ballots-{ballot}-candidates-order.md
 * Tenant suite, route api/v1/ballots/{ballot}/candidates/order: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const CAND_ORDER_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function candOrderUrl(string $uuid): string
{
    return "/api/v1/ballots/{$uuid}/candidates/order";
}

/** A draft of A with a ballot of three candidates at 1, 2, 3, the owner signed in. Returns [team, election, ballot, [a, b, c]]. */
function candOrderSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $ballot = Accounts::plantBallot(['election' => $election]);
    $ids = [
        Accounts::plantCandidate(['ballot' => $ballot, 'first_name' => 'A', 'position' => 1]),
        Accounts::plantCandidate(['ballot' => $ballot, 'first_name' => 'B', 'position' => 2]),
        Accounts::plantCandidate(['ballot' => $ballot, 'first_name' => 'C', 'position' => 3]),
    ];
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $ballot, $ids];
}

it('saves a new order and answers the candidates in that order [FR-CAND-02] (scenario 1)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this);

    $response = $this->browser->put(candOrderUrl($ballot), ['candidates' => [$c, $a, $b]])->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$c, $a, $b])
        ->and(array_column($response->json('data'), 'position'))->toBe([1, 2, 3])
        ->and(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe([$c, $a, $b]);
});

it('answers 200 and changes nothing for the stored order, and for an empty ballot with [] [FR-CAND-02] (scenario 2)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this);
    $empty = Accounts::plantBallot(['election' => $election, 'title' => 'Vide']);

    $this->browser->put(candOrderUrl($ballot), ['candidates' => [$a, $b, $c]])->assertOk();
    $this->browser->put(candOrderUrl($empty), ['candidates' => []])->assertOk()->assertJsonPath('data', []);

    expect(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe([$a, $b, $c]);
});

it('answers 422 when candidates is missing, not an array or holds a non-UUID [FR-CAND-02] (scenario 3)', function (array $body, string $field, string $rule) {
    [$t, $election, $ballot, $ids] = candOrderSignedIn($this);

    $response = $this->browser->put(candOrderUrl($ballot), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields')[$field] ?? [])->toContain($rule)
        ->and(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe($ids);
})->with([
    'missing' => [[], 'candidates', 'required'],
    'not an array' => [['candidates' => 'abc'], 'candidates', 'array'],
    'not a uuid' => [['candidates' => ['nope']], 'candidates.0', 'uuid'],
]);

it('answers 422 set_mismatch when the set is not exactly the ballot\'s candidates, the same for any cause [FR-CAND-02] (scenario 4)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this);
    $otherBallot = Accounts::plantBallot(['election' => $election, 'title' => 'Autre']);
    $elsewhere = Accounts::plantCandidate(['ballot' => $otherBallot]);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $foreignElection])]);

    $reference = Team::shape($this->browser->put(candOrderUrl($ballot), ['candidates' => [$a, $b, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']]));
    expect($reference['status'])->toBe(422);
    foreach ([[$a, $a, $b], [$a, $b], [$a, $b, $c, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa'], [$a, $b, $elsewhere], [$a, $b, $foreign]] as $set) {
        $response = $this->browser->put(candOrderUrl($ballot), ['candidates' => $set]);
        $response->assertStatus(422);
        expect($response->json('error.fields.candidates'))->toContain('set_mismatch');
    }
    expect(Team::shape($this->browser->put(candOrderUrl($ballot), ['candidates' => [$a, $b, $foreign]])))->toBe($reference)
        ->and(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe([$a, $b, $c]);
});

it('answers 409 for an election that is not a draft, and keeps the order [FR-SEC-06] (scenario 5)', function (string $status) {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this, $status);

    $this->browser->put(candOrderUrl($ballot), ['candidates' => [$c, $b, $a]])->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe([$a, $b, $c]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a ballot that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantBallot(['election' => $foreignElection]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->put(candOrderUrl(CAND_ORDER_UNKNOWN), ['candidates' => []]));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');
    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->put(candOrderUrl($target), ['candidates' => []])))->toBe($unknown);
    }
});

it('answers 401 when nobody is signed in [FR-CAND-02] (scenario 7)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $ballot = Accounts::plantBallot(['election' => $election]);

    $this->browser->put(candOrderUrl($ballot), ['candidates' => []])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 8)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->put(candOrderUrl($ballot), ['candidates' => [$c, $b, $a]])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe([$a, $b, $c]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 9)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this);

    $this->browser->put(candOrderUrl($ballot), ['candidates' => [$c, $b, $a]], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 10)', function () {
    [$t, $election, $ballot] = candOrderSignedIn($this);

    $this->browser->rawBody('PUT', candOrderUrl($ballot), '{"candidates": [')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 240 requests an hour from one user [NFR-SEC-05] (scenario 11)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this);
    foreach (range(1, 240) as $i) {
        $this->browser->put(candOrderUrl($ballot), ['candidates' => $i % 2 === 0 ? [$a, $b, $c] : [$c, $b, $a]])->assertOk();
    }

    $this->browser->put(candOrderUrl($ballot), ['candidates' => [$b, $a, $c]])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 for another method than PUT [FR-CAND-02] (scenario 12)', function (string $method) {
    [$t, $election, $ballot] = candOrderSignedIn($this);

    $this->browser->other($method, candOrderUrl($ballot))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['GET', 'POST', 'PATCH', 'DELETE']);

it('answers 422 for more than 50 items, before looking at each one [FR-CAND-02] (scenario 3)', function () {
    [$t, $election, $ballot] = candOrderSignedIn($this);

    $response = $this->browser->put(candOrderUrl($ballot), ['candidates' => array_fill(0, 51, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.candidates'))->toContain('max');
});

it('answers 422 set_mismatch, not 409, for a wrong set on an election that is not a draft [FR-CAND-02] (scenario 5b)', function () {
    [$t, $election, $ballot, [$a, $b, $c]] = candOrderSignedIn($this, 'scheduled');

    $response = $this->browser->put(candOrderUrl($ballot), ['candidates' => [$a, $b, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']]);

    $response->assertStatus(422);
    expect($response->json('error.fields.candidates'))->toContain('set_mismatch')
        ->and(array_column(Accounts::candidateRows($ballot), 'uuid'))->toBe([$a, $b, $c]);
});
