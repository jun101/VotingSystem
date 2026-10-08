<?php

/*
 * PATCH /api/v1/elections/{election} — docs/api/elections/PATCH-elections-{election}.md
 * Tenant suite, route api/v1/elections/{election}: another institution's election answers 404, the
 * same as an unknown one (scenario 7).
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const OTHER_UNKNOWN_ELECTION = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function patchUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}";
}

/** An owner of A signed in, and a draft of A. Returns [t, id]. */
function draftSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $id = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution'], 'title' => 'Avant']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $id];
}

it('changes the fields that are sent and only those [FR-ELEC-01] (scenario 1)', function () {
    [$t, $id] = draftSignedIn($this, ['description' => 'Avant aussi', 'language' => 'fr', 'candidate_order' => 'manual']);
    $before = Accounts::electionRow($id);

    $response = $this->browser->patch(patchUrl($id), ['title' => '  Après  ', 'language' => 'en', 'candidate_order' => 'shuffled', 'ends_at' => '2026-10-16T21:00:00Z'])->assertOk();

    $after = Accounts::electionRow($id);
    expect($response->json('data'))->toMatchArray(['id' => $id, 'title' => 'Après', 'language' => 'en', 'candidate_order' => 'shuffled', 'ends_at' => '2026-10-16T21:00:00Z', 'description' => 'Avant aussi', 'starts_at' => '2026-10-12T12:00:00Z', 'status' => 'draft'])
        ->and($after['id'])->toBe($before['id'])
        ->and($after['institution_id'])->toBe($before['institution_id'])
        ->and($after['created_at'])->toBe($before['created_at']);
    $this->browser->get(patchUrl($id))->assertOk()->assertJsonPath('data.title', 'Après');
});

it('lets a manager edit a draft too [FR-ELEC-01] (scenario 1)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->patch(patchUrl($id), ['title' => 'Par le gestionnaire'])->assertOk()->assertJsonPath('data.title', 'Par le gestionnaire');
});

it('answers 200 and changes nothing for an empty body or no known field [FR-ELEC-01] (scenario 2)', function (array $body) {
    [$t, $id] = draftSignedIn($this);
    $before = Accounts::electionRow($id);

    $this->browser->patch(patchUrl($id), $body)->assertOk()->assertJsonPath('data.title', 'Avant');

    $after = Accounts::electionRow($id);
    unset($before['updated_at'], $after['updated_at']);
    expect($after)->toBe($before);
})->with([[[]], [['colour' => 'blue']]]);

it('stores a description sent as an empty string or null as null [FR-ELEC-01] (scenario 3)', function (mixed $value) {
    [$t, $id] = draftSignedIn($this, ['description' => 'À effacer']);

    $this->browser->patch(patchUrl($id), ['description' => $value])->assertOk()->assertJsonPath('data.description', null);

    expect(Accounts::electionRow($id)['description'])->toBeNull();
})->with(['', '   ', null]);

it('answers 422 when the title is blank or null [FR-ELEC-01] (scenario 4)', function (mixed $value) {
    [$t, $id] = draftSignedIn($this);

    $response = $this->browser->patch(patchUrl($id), ['title' => $value]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.title'))->toContain('required')
        ->and(Accounts::electionRow($id)['title'])->toBe('Avant');
})->with([[''], ['   '], [null]]);

it('answers 422 for the other rules of the creation [FR-ELEC-01] (scenario 5)', function (array $body, string $field, string $rule) {
    [$t, $id] = draftSignedIn($this);

    $response = $this->browser->patch(patchUrl($id), $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::electionRow($id)['title'])->toBe('Avant');
})->with([
    'title too long' => [['title' => str_repeat('é', 201)], 'title', 'max'],
    'description too long' => [['description' => str_repeat('é', 5001)], 'description', 'max'],
    'start not a date' => [['starts_at' => 'next monday'], 'starts_at', 'date'],
    'unknown time zone' => [['timezone' => 'Mars/Olympus'], 'timezone', 'timezone'],
    'language' => [['language' => 'es'], 'language', 'in'],
    'candidate order' => [['candidate_order' => 'random'], 'candidate_order', 'in'],
    'results display' => [['results_display' => 'everything'], 'results_display', 'in'],
]);

it('checks the end against the stored start, and the start against the stored end [FR-ELEC-02] (scenario 5)', function () {
    [$t, $id] = draftSignedIn($this, ['starts_at' => '2026-10-12 12:00:00', 'ends_at' => '2026-10-16 19:00:00']);

    $end = $this->browser->patch(patchUrl($id), ['ends_at' => '2026-10-11T12:00:00Z']);
    $end->assertStatus(422);
    expect($end->json('error.fields.ends_at'))->toContain('after_start');

    $start = $this->browser->patch(patchUrl($id), ['starts_at' => '2026-10-17T12:00:00Z']);
    $start->assertStatus(422);
    expect($start->json('error.fields'))->toHaveKey('ends_at')
        ->and(Accounts::electionRow($id)['starts_at'])->toBe('2026-10-12 12:00:00');

    // Both together, valid as a pair, even if each is wrong against the stored other.
    $this->browser->patch(patchUrl($id), ['starts_at' => '2026-11-01T12:00:00Z', 'ends_at' => '2026-11-03T12:00:00Z'])->assertOk();
});

it('answers 409 for an election that is not a draft, and changes nothing [FR-ELEC-03] (scenario 6)', function (string $status) {
    [$t, $id] = draftSignedIn($this, ['status' => $status]);

    $response = $this->browser->patch(patchUrl($id), ['title' => 'Après']);

    $response->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Accounts::electionRow($id)['title'])->toBe('Avant');
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the body before the state: an invalid body on a non-draft is a 422 [FR-ELEC-03] (scenario 5)', function () {
    [$t, $id] = draftSignedIn($this, ['status' => 'open']);

    $this->browser->patch(patchUrl($id), ['title' => ''])->assertStatus(422);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'title' => 'De B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->patch(patchUrl(OTHER_UNKNOWN_ELECTION), ['title' => 'X']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->patch(patchUrl($target), ['title' => 'X'])))->toBe($unknown);
    }
    // Even with an invalid body: the record is checked first.
    expect(Team::shape($this->browser->patch(patchUrl($foreign), ['title' => ''])))->toBe($unknown)
        ->and(Accounts::electionRow($foreign)['title'])->toBe('De B');
});

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-01] (scenario 8)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Avant']);

    $this->browser->patch(patchUrl($id), ['title' => 'Après'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::electionRow($id)['title'])->toBe('Avant');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 9)', function () {
    [$t, $id] = draftSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->patch(patchUrl($id), ['title' => 'Après'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($id)['title'])->toBe('Avant');
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 10)', function () {
    [$t, $id] = draftSignedIn($this);

    $this->browser->patch(patchUrl($id), ['title' => 'Après'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::electionRow($id)['title'])->toBe('Avant');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 11)', function () {
    [$t, $id] = draftSignedIn($this);

    $this->browser->rawBody('PATCH', patchUrl($id), '{"title": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $id] = draftSignedIn($this);

    foreach (range(1, 120) as $i) {
        $this->browser->patch(patchUrl($id), ['title' => "T{$i}"])->assertOk();
    }

    $response = $this->browser->patch(patchUrl($id), ['title' => 'Trop']);

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::electionRow($id)['title'])->toBe('T120');
});

it('ignores what a request must not change: status, cover, institution, id [FR-ELEC-03, FR-INST-05] (notes)', function () {
    [$t, $id] = draftSignedIn($this);
    $before = Accounts::electionRow($id);

    $this->browser->patch(patchUrl($id), [
        'title' => 'Après',
        'status' => 'open',
        'cover_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'institution' => $t['b']['owner']['institution'],
        'institution_id' => 2,
        'id' => $t['b']['owner']['institution'],
        'uuid' => $t['b']['owner']['institution'],
        'opened_at' => '2026-01-01T00:00:00Z',
    ])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'draft');

    $after = Accounts::electionRow($id);
    expect($after['title'])->toBe('Après')
        ->and($after['status'])->toBe('draft')
        ->and($after['cover_file'])->toBeNull()
        ->and($after['opened_at'])->toBeNull()
        ->and($after['uuid'])->toBe($before['uuid'])
        ->and($after['institution_id'])->toBe($before['institution_id']);
});
