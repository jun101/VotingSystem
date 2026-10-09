<?php

/*
 * POST /api/v1/elections — docs/api/elections/POST-elections.md
 * Tenant suite, route api/v1/elections: the election is written in the caller's institution whatever
 * the body says.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const NEW_ELECTION = '/api/v1/elections';

function validElection(array $override = []): array
{
    return array_merge(['title' => 'Conseil des élèves 2026', 'starts_at' => '2026-10-12T12:00:00Z', 'ends_at' => '2026-10-16T19:00:00Z'], $override);
}

function electionOwner($test): array
{
    $t = Team::two();
    Team::signIn($test, $t['a']['owner']);

    return $t;
}

it('creates a draft with the defaults of the institution [FR-ELEC-01] (scenario 1)', function () {
    $t = Team::two();
    Accounts::updateInstitution($t['a']['owner']['institution'], ['timezone' => 'America/New_York', 'language' => 'en']);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post(NEW_ELECTION, validElection())->assertCreated();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'title', 'description', 'status', 'starts_at', 'ends_at', 'timezone', 'language', 'candidate_order', 'results_display', 'cover', 'ballots_count', 'voters_count', 'created_at'])
        ->and($response->json('data'))->toMatchArray([
            'title' => 'Conseil des élèves 2026',
            'description' => null,
            'status' => 'draft',
            'starts_at' => '2026-10-12T12:00:00Z',
            'ends_at' => '2026-10-16T19:00:00Z',
            'timezone' => 'America/New_York',
            'language' => 'en',
            'candidate_order' => 'manual',
            'results_display' => 'full',
            'cover' => null,
            'ballots_count' => 0,
            'voters_count' => 0,
        ])
        ->and($response->json('data.id'))->toMatch(UUID_V4)
        ->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $row = Accounts::electionRow($response->json('data.id'));
    expect($row['status'])->toBe('draft')
        ->and($row['starts_at'])->toBe('2026-10-12 12:00:00')
        ->and($row['opened_at'])->toBeNull();
});

it('creates a draft with every field given [FR-ELEC-01] (scenario 2)', function () {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection([
        'description' => 'Élection annuelle des représentants des élèves.',
        'timezone' => 'Europe/Paris',
        'language' => 'en',
        'candidate_order' => 'shuffled',
        'results_display' => 'winners',
    ]))->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'description' => 'Élection annuelle des représentants des élèves.',
        'timezone' => 'Europe/Paris',
        'language' => 'en',
        'candidate_order' => 'shuffled',
        'results_display' => 'winners',
    ]);
    $this->browser->get('/api/v1/elections/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.candidate_order', 'shuffled');
});

it('trims the title, stores an empty description as null and reads an offset as UTC [FR-ELEC-01, FR-ELEC-02] (scenario 1)', function () {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection([
        'title' => '  Conseil des élèves  ',
        'description' => '   ',
        'starts_at' => '2026-10-12T08:00:00-04:00',
        'ends_at' => '2026-10-16T15:00:00-04:00',
    ]))->assertCreated();

    expect($response->json('data.title'))->toBe('Conseil des élèves')
        ->and($response->json('data.description'))->toBeNull()
        ->and($response->json('data.starts_at'))->toBe('2026-10-12T12:00:00Z')
        ->and($response->json('data.ends_at'))->toBe('2026-10-16T19:00:00Z');
});

it('accepts a start in the past for a draft [FR-ELEC-03] (scenario 8)', function () {
    electionOwner($this);

    $this->browser->post(NEW_ELECTION, validElection(['starts_at' => '2019-05-01T12:00:00Z', 'ends_at' => '2019-05-02T12:00:00Z']))
        ->assertCreated()->assertJsonPath('data.status', 'draft');
});

it('lets a manager create one too [FR-ELEC-01] (scenario 1)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(NEW_ELECTION, validElection())->assertCreated();
});

it('answers 422 when the title or a date is missing, or the title is blank [FR-ELEC-01] (scenario 3)', function (array $body, string $field) {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, $body);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain('required')
        ->and(Accounts::electionRows())->toBe([]);
})->with([
    'no title' => [['starts_at' => '2026-10-12T12:00:00Z', 'ends_at' => '2026-10-16T19:00:00Z'], 'title'],
    'blank title' => [['title' => '   ', 'starts_at' => '2026-10-12T12:00:00Z', 'ends_at' => '2026-10-16T19:00:00Z'], 'title'],
    'no start' => [['title' => 'X', 'ends_at' => '2026-10-16T19:00:00Z'], 'starts_at'],
    'no end' => [['title' => 'X', 'starts_at' => '2026-10-12T12:00:00Z'], 'ends_at'],
]);

it('answers 422 when a text is too long, and accepts the limit itself [FR-ELEC-01] (scenario 4)', function (string $field, int $max) {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection([$field => str_repeat('é', $max + 1)]));
    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain('max');

    // Counted in characters, not bytes.
    $this->browser->post(NEW_ELECTION, validElection([$field => str_repeat('é', $max)]))->assertCreated();
})->with([['title', 200], ['description', 5000]]);

it('answers 422 when a date is not a date-time [FR-ELEC-02] (scenario 5)', function (string $field, mixed $value) {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection([$field => $value]));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain('date');
})->with([['starts_at', 'next monday'], ['starts_at', '2026-13-45T00:00:00Z'], ['ends_at', 'tomorrow'], ['starts_at', ['2026-10-12']]]);

it('answers 422 when the end is not after the start [FR-ELEC-02] (scenario 6)', function (string $end) {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection(['starts_at' => '2026-10-12T12:00:00Z', 'ends_at' => $end]));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.ends_at'))->toContain('after_start')
        ->and(Accounts::electionRows())->toBe([]);
})->with(['2026-10-12T12:00:00Z', '2026-10-12T11:59:59Z', '2026-10-11T12:00:00Z', '2026-10-12T08:00:00-04:00']);

it('answers 422 for an unknown time zone or a choice that is not offered [FR-ELEC-01] (scenario 7)', function (string $field, mixed $value, string $rule) {
    electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection([$field => $value]));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::electionRows())->toBe([]);
})->with([
    ['timezone', 'Mars/Olympus', 'timezone'],
    ['timezone', 'Haiti', 'timezone'],
    ['language', 'es', 'in'],
    ['language', 'FR', 'in'],
    ['candidate_order', 'random', 'in'],
    ['results_display', 'everything', 'in'],
]);

it('ignores what a request must not set: status, institution, id, cover [FR-ELEC-03, FR-INST-05] (notes)', function () {
    $t = electionOwner($this);

    $response = $this->browser->post(NEW_ELECTION, validElection([
        'status' => 'open',
        'institution' => $t['b']['owner']['institution'],
        'institution_id' => 2,
        'id' => $t['b']['owner']['institution'],
        'uuid' => $t['b']['owner']['institution'],
        'cover_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'opened_at' => '2026-01-01T00:00:00Z',
    ]))->assertCreated();

    $row = Accounts::electionRow($response->json('data.id'));
    expect($response->json('data.status'))->toBe('draft')
        ->and($row['cover_file'])->toBeNull()
        ->and($row['opened_at'])->toBeNull()
        ->and($response->json('data.id'))->not->toBe($t['b']['owner']['institution'])
        ->and(Accounts::electionRows($t['b']['owner']['institution']))->toBe([])
        ->and(Accounts::electionRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-01] (scenario 9)', function () {
    $this->browser->post(NEW_ELECTION, validElection())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::electionRows())->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    $t = electionOwner($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(NEW_ELECTION, validElection())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRows())->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    electionOwner($this);

    $this->browser->post(NEW_ELECTION, validElection(), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::electionRows())->toBe([]);
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 12)', function () {
    electionOwner($this);

    $this->browser->postRaw(NEW_ELECTION, '{"title": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 60 requests an hour from one user [NFR-SEC-05] (scenario 13)', function () {
    electionOwner($this);

    foreach (range(1, 60) as $i) {
        $this->browser->post(NEW_ELECTION, validElection(['title' => "E{$i}"]))->assertCreated();
    }

    $response = $this->browser->post(NEW_ELECTION, validElection(['title' => 'Trop']));

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::electionRows())->toHaveCount(60);
});

it('answers 405 to another method than GET, HEAD or POST [NFR-SEC-01] (scenario 14)', function (string $method) {
    electionOwner($this);

    $this->browser->other($method, NEW_ELECTION)->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PUT', 'PATCH', 'DELETE']);

it('logs neither the title nor the description [FR-ELEC-01]', function () {
    electionOwner($this);

    $this->browser->post(NEW_ELECTION, validElection(['title' => 'Titre très secret ZXQ', 'description' => 'Description très secrète ZXQ']))->assertCreated();

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        expect(file_get_contents($log))->not->toContain('ZXQ');
    }
});
