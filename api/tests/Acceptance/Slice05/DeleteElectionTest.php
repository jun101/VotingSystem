<?php

/*
 * DELETE /api/v1/elections/{election} — docs/api/elections/DELETE-elections-{election}.md
 * Tenant suite, route api/v1/elections/{election}: another institution's election answers 404, the
 * same as an unknown one (scenario 3).
 */

use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

function deleteUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}";
}

function deleteDraftSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $id = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $id];
}

it('deletes a draft for good [FR-ELEC-03] (scenario 1)', function () {
    [$t, $id] = deleteDraftSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Reste']);

    $this->browser->delete(deleteUrl($id))->assertNoContent();

    expect(Accounts::electionRow($id))->toBeNull()
        ->and(Accounts::electionRow($other))->not->toBeNull();
    $this->browser->get(deleteUrl($id))->assertStatus(404);
});

it('deletes the cover files with the election, and no other election\'s [FR-ELEC-03] (scenario 1)', function () {
    [$t, $id] = deleteDraftSignedIn($this);
    $this->browser->upload('PUT', deleteUrl($id).'/cover', ['file' => Images::upload(Images::png(1200, 600))])->assertOk();
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre']);
    $this->browser->upload('PUT', deleteUrl($other).'/cover', ['file' => Images::upload(Images::png(800, 400), 'b.png')])->assertOk();
    expect(Team::mediaFiles())->toHaveCount(4);

    $this->browser->delete(deleteUrl($id))->assertNoContent();

    expect(Team::mediaFiles())->toHaveCount(2);
});

it('lets a manager delete a draft too [FR-ELEC-03] (scenario 1)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->delete(deleteUrl($id))->assertNoContent();
});

it('answers 409 for an election that is not a draft, and keeps it [FR-ELEC-03] (scenario 2)', function (string $status) {
    [$t, $id] = deleteDraftSignedIn($this, ['status' => $status]);

    $response = $this->browser->delete(deleteUrl($id));

    $response->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Accounts::electionRow($id))->not->toBeNull();
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, already deleted, of another institution or not a UUID [FR-INST-05] (scenario 3)', function () {
    [$t, $id] = deleteDraftSignedIn($this);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $this->browser->delete(deleteUrl($id))->assertNoContent();

    $unknown = Team::shape($this->browser->delete(deleteUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa')));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$id, $foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->delete(deleteUrl($target))))->toBe($unknown);
    }
    expect(Accounts::electionRow($foreign))->not->toBeNull();
});

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-03] (scenario 4)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->delete(deleteUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Accounts::electionRow($id))->not->toBeNull();
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 5)', function () {
    [$t, $id] = deleteDraftSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->delete(deleteUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::electionRow($id))->not->toBeNull();
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 6)', function () {
    [$t, $id] = deleteDraftSignedIn($this);

    $this->browser->delete(deleteUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Accounts::electionRow($id))->not->toBeNull();
});

it('answers 429 above 60 requests an hour from one user [NFR-SEC-05] (scenario 7)', function () {
    $t = Team::two();
    $ids = array_map(fn () => Accounts::plantElection(['institution' => $t['a']['owner']['institution']]), range(1, 61));
    Team::signIn($this, $t['a']['owner']);

    foreach (array_slice($ids, 0, 60) as $id) {
        $this->browser->delete(deleteUrl($id))->assertNoContent();
    }

    $response = $this->browser->delete(deleteUrl($ids[60]));

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(Accounts::electionRow($ids[60]))->not->toBeNull();
});
