<?php

/*
 * POST /api/v1/elections/{election}/duplicate — docs/api/elections/POST-elections-{election}-duplicate.md
 * Tenant suite, route api/v1/elections/{election}/duplicate: another institution's election answers
 * 404, the same as an unknown one (scenario 5).
 */

use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

function duplicateUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/duplicate";
}

function duplicateSource($test, array $options = []): array
{
    $t = Team::two();
    $id = Accounts::plantElection($options + [
        'institution' => $t['a']['owner']['institution'],
        'title' => 'Conseil des élèves 2025',
        'description' => 'Élection annuelle.',
        'starts_at' => '2025-10-13 12:00:00',
        'ends_at' => '2025-10-17 19:00:00',
        'timezone' => 'Europe/Paris',
        'language' => 'en',
        'candidate_order' => 'shuffled',
        'results_display' => 'winners',
    ]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $id];
}

it('creates a draft copy of the settings with a new id, "Copie de" in front of the title [FR-ELEC-06] (scenario 1)', function () {
    [$t, $id] = duplicateSource($this, ['language' => 'fr']);

    $response = $this->browser->post(duplicateUrl($id))->assertCreated();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and($response->json('data.id'))->not->toBe($id)
        ->and($response->json('data.id'))->toMatch(UUID_V4)
        ->and($response->json('data'))->toMatchArray([
            'title' => 'Copie de Conseil des élèves 2025',
            'description' => 'Élection annuelle.',
            'status' => 'draft',
            'starts_at' => '2025-10-13T12:00:00Z',
            'ends_at' => '2025-10-17T19:00:00Z',
            'timezone' => 'Europe/Paris',
            'language' => 'fr',
            'candidate_order' => 'shuffled',
            'results_display' => 'winners',
            'cover' => null,
            'ballots_count' => 0,
            'voters_count' => 0,
        ]);
    expect(Accounts::electionRows($t['a']['owner']['institution']))->toHaveCount(2);
});

it('writes "Copy of" for a source whose language is English [FR-ELEC-06] (scenario 1)', function () {
    [$t, $id] = duplicateSource($this, ['language' => 'en']);

    $this->browser->post(duplicateUrl($id))->assertCreated()->assertJsonPath('data.title', 'Copy of Conseil des élèves 2025');
});

it('cuts the default title to 200 characters [FR-ELEC-06] (scenario 1)', function () {
    [$t, $id] = duplicateSource($this, ['title' => str_repeat('é', 200), 'language' => 'fr']);

    $title = $this->browser->post(duplicateUrl($id))->assertCreated()->json('data.title');

    expect(mb_strlen($title))->toBe(200)
        ->and($title)->toStartWith('Copie de é');
});

it('duplicates an election in any status, as a draft [FR-ELEC-06] (scenario 2)', function (string $status) {
    [$t, $id] = duplicateSource($this, [
        'status' => $status,
        'opened_at' => '2025-10-13 12:00:00',
        'closed_at' => '2025-10-17 19:00:00',
        'published_at' => '2025-10-20 12:00:00',
        'archived_at' => '2025-11-20 12:00:00',
    ]);

    $response = $this->browser->post(duplicateUrl($id))->assertCreated();

    $row = Accounts::electionRow($response->json('data.id'));
    expect($response->json('data.status'))->toBe('draft')
        ->and($row['opened_at'])->toBeNull()
        ->and($row['closed_at'])->toBeNull()
        ->and($row['published_at'])->toBeNull()
        ->and($row['archived_at'])->toBeNull();
    // The source is untouched.
    expect(Accounts::electionRow($id)['status'])->toBe($status);
})->with(['draft', 'scheduled', 'open', 'closed', 'published', 'archived']);

it('uses the title that is given [FR-ELEC-06] (scenario 3)', function () {
    [$t, $id] = duplicateSource($this);

    $this->browser->post(duplicateUrl($id), ['title' => '  Conseil des élèves 2026  '])->assertCreated()
        ->assertJsonPath('data.title', 'Conseil des élèves 2026');
});

it('does not copy the cover, nor the link to a first round [FR-ELEC-06] (scenario 1)', function () {
    $t = Team::two();
    $parent = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Premier tour']);
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Second tour', 'parent' => $parent]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->upload('PUT', "/api/v1/elections/{$id}/cover", ['file' => Images::upload(Images::png(1200, 600))])->assertOk();
    expect(Team::mediaFiles())->toHaveCount(2);

    $copy = $this->browser->post(duplicateUrl($id))->assertCreated();

    $row = Accounts::electionRow($copy->json('data.id'));
    expect($copy->json('data.cover'))->toBeNull()
        ->and($row['cover_file'])->toBeNull()
        ->and($row['parent_election_id'])->toBeNull()
        ->and(Team::mediaFiles())->toHaveCount(2);

    // Deleting the copy never touches the source's files.
    $this->browser->delete('/api/v1/elections/'.$copy->json('data.id'))->assertNoContent();
    expect(Team::mediaFiles())->toHaveCount(2);
});

it('lets a manager duplicate [FR-ELEC-06] (scenario 1)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(duplicateUrl($id))->assertCreated();
});

it('answers 422 when the title is blank or longer than 200 [FR-ELEC-06] (scenario 4)', function (string $title, string $rule) {
    [$t, $id] = duplicateSource($this);

    $response = $this->browser->post(duplicateUrl($id), ['title' => $title]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.title'))->toContain($rule)
        ->and(Accounts::electionRows($t['a']['owner']['institution']))->toHaveCount(1);
})->with([['', 'required'], ['   ', 'required'], [str_repeat('é', 201), 'max']]);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 5)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(duplicateUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(duplicateUrl($target))))->toBe($unknown);
    }
    expect(Accounts::electionRows($t['b']['owner']['institution']))->toHaveCount(1)
        ->and(Accounts::electionRows($t['a']['owner']['institution']))->toBe([]);
});

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-06] (scenario 6)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->post(duplicateUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 7)', function () {
    [$t, $id] = duplicateSource($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->post(duplicateUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 8)', function () {
    [$t, $id] = duplicateSource($this);

    $this->browser->post(duplicateUrl($id), [], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
});

it('answers 400 when the body is not valid JSON [NFR-SEC-01] (scenario 9)', function () {
    [$t, $id] = duplicateSource($this);

    $this->browser->postRaw(duplicateUrl($id), '{"title": "X"')->assertStatus(400)->assertJsonPath('error.code', 'malformed_request');
});

it('answers 429 above 60 requests an hour from one user [NFR-SEC-05] (scenario 10)', function () {
    [$t, $id] = duplicateSource($this);

    foreach (range(1, 60) as $i) {
        $this->browser->post(duplicateUrl($id))->assertCreated();
    }

    $this->browser->post(duplicateUrl($id))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('answers 405 to another method than POST [NFR-SEC-01] (scenario 11)', function (string $method) {
    [$t, $id] = duplicateSource($this);

    $response = $this->browser->other($method, duplicateUrl($id));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('POST');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);
