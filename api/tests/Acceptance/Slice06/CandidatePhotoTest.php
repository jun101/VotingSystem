<?php

/*
 * PUT and DELETE /api/v1/candidates/{candidate}/photo —
 * docs/api/candidates/PUT-candidates-{candidate}-photo.md and DELETE-candidates-{candidate}-photo.md
 * Tenant suite, routes api/v1/candidates/{candidate}/photo: another institution's candidate answers 404, the same
 * as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Images;
use Tests\Support\Team;

const PHOTO_UNKNOWN_CANDIDATE = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function candPhotoUrl(string $uuid): string
{
    return "/api/v1/candidates/{$uuid}/photo";
}

/** A candidate of A in a draft (or the given status) and the owner of A signed in. Returns [team, election, candidate]. */
function candPhotoSignedIn($test, array $election = [], array $candidate = []): array
{
    $t = Team::two();
    $e = Accounts::plantElection($election + ['institution' => $t['a']['owner']['institution']]);
    $p = Accounts::plantCandidate($candidate + ['ballot' => Accounts::plantBallot(['election' => $e]), 'first_name' => 'Avant']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $e, $p];
}

function putCandPhoto($test, string $id, string $bytes, string $name = 'photo.png')
{
    return $test->browser->upload('PUT', candPhotoUrl($id), ['file' => Images::upload($bytes, $name)]);
}

it('accepts a PNG, a JPEG and a WebP and answers the candidate with its photo [FR-CAND-02, FR-CAND-04] (scenario 1)', function (string $format) {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $response = putCandPhoto($this, $id, Images::$format(600, 600), "photo.{$format}")->assertOk();

    $photo = $response->json('data.photo');
    expect($response->json('data.id'))->toBe($id)
        ->and(array_keys($photo))->toBe(['sm', 'md'])
        ->and($photo['sm'])->toMatch('#^/media/[0-9a-f-]{36}-160\.webp$#')
        ->and($photo['md'])->toMatch('#^/media/[0-9a-f-]{36}-480\.webp$#')
        ->and(Team::mediaFiles())->toHaveCount(2);
    $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk()->assertJsonPath('data.0.candidates.0.photo', $photo);
})->with(['png', 'jpeg', 'webp']);

it('replaces a photo that already exists and deletes the old files [FR-CAND-02] (scenario 2)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    $first = putCandPhoto($this, $id, Images::png(600, 600))->assertOk()->json('data.photo');
    $firstFiles = Team::mediaFiles();

    $second = putCandPhoto($this, $id, Images::jpeg(600, 600), 'b.jpg')->assertOk()->json('data.photo');

    expect($second['sm'])->not->toBe($first['sm'])
        ->and(Team::mediaFiles())->toHaveCount(2)
        ->and(array_intersect(Team::mediaFiles(), $firstFiles))->toBe([]);
});

it('answers 422 when the file part is missing [FR-CAND-02] (scenario 3)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $response = $this->browser->upload('PUT', candPhotoUrl($id), []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('required')
        ->and(Team::mediaFiles())->toBe([]);
});

it('answers 413 above 5 MB and accepts exactly 5 MB [FR-CAND-04] (scenario 4)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);

    putCandPhoto($this, $id, Images::pngOfSize(5 * 1024 * 1024 + 1))->assertStatus(413)->assertJsonPath('error.code', 'file_too_large');
    expect(Team::mediaFiles())->toBe([]);
    putCandPhoto($this, $id, Images::pngOfSize(5 * 1024 * 1024))->assertOk();
});

it('answers 415 when the content is not a JPEG, PNG or WebP, is undecodable, or is animated [NFR-SEC-06] (scenario 5)', function (string $bytes, string $name) {
    [$t, $election, $id] = candPhotoSignedIn($this);

    putCandPhoto($this, $id, $bytes, $name)->assertStatus(415)->assertJsonPath('error.code', 'file_type_not_allowed');
    expect(Team::mediaFiles())->toBe([]);
})->with([
    'a GIF named .png' => fn () => [Images::gif(), 'photo.png'],
    'a PDF named .png' => fn () => [Images::pdf(), 'photo.png'],
    'a script named .php' => fn () => [Images::script(), 'photo.php'],
    'a truncated PNG' => fn () => [Images::truncatedPng(), 'photo.png'],
    'an animated WebP' => fn () => [Images::animatedWebp(), 'photo.webp'],
    'an animated PNG' => fn () => [Images::animatedPng(), 'photo.png'],
]);

it('answers 422 above 8 000 pixels on a side or 40 million pixels, from the header alone [NFR-SEC-06] (scenario 6)', function (int $width, int $height) {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $response = putCandPhoto($this, $id, Images::pngClaiming($width, $height));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('dimensions')
        ->and(Team::mediaFiles())->toBe([]);
})->with([[8001, 100], [100, 8001], [7000, 6000]]);

it('answers 409 for an election that is not a draft and stores nothing [FR-SEC-06] (scenario 7)', function (string $status) {
    [$t, $election, $id] = candPhotoSignedIn($this, ['status' => $status]);

    putCandPhoto($this, $id, Images::png())->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Team::mediaFiles())->toBe([]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the record, then the state, then the file [FR-SEC-06] (scenario 7)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this, ['status' => 'open']);

    putCandPhoto($this, $id, Images::pdf(), 'photo.png')->assertStatus(409);
});

it('answers 404, the same for every case, for a candidate that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $foreignElection])]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape(putCandPhoto($this, PHOTO_UNKNOWN_CANDIDATE, Images::png()));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape(putCandPhoto($this, $target, Images::png())))->toBe($unknown);
    }
    expect(Team::shape($this->browser->delete(candPhotoUrl($foreign))))->toBe(Team::shape($this->browser->delete(candPhotoUrl(PHOTO_UNKNOWN_CANDIDATE))))
        ->and(Team::mediaFiles())->toBe([])
        ->and(Accounts::candidateRow($foreign)['photo_file'])->toBeNull();
});

it('answers 401 when nobody is signed in [FR-CAND-02] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);

    putCandPhoto($this, $id, Images::png())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    putCandPhoto($this, $id, Images::png())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $this->browser->upload('PUT', candPhotoUrl($id), ['file' => Images::upload(Images::png())], [], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 429 above 20 uploads an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);

    foreach (range(1, 20) as $i) {
        putCandPhoto($this, $id, Images::png(300 + $i, 300))->assertOk();
    }

    $response = putCandPhoto($this, $id, Images::png());

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than PUT or DELETE [NFR-SEC-01] (scenario 13)', function (string $method) {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $response = $this->browser->other($method, candPhotoUrl($id));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('PUT')->toContain('DELETE')->not->toContain($method);
})->with(['GET', 'POST', 'PATCH']);

it('lets a manager upload and remove a photo [FR-CAND-02] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);
    Team::signIn($this, $t['a']['manager']);

    putCandPhoto($this, $id, Images::png(600, 600))->assertOk();
    $this->browser->delete(candPhotoUrl($id))->assertNoContent();
    expect(Team::mediaFiles())->toBe([]);
});

it('is not copied by a duplicate of the election, even with copy_candidates [FR-ELEC-06] (notes)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    putCandPhoto($this, $id, Images::png(600, 600))->assertOk();

    $copy = $this->browser->post("/api/v1/elections/{$election}/duplicate", ['copy_candidates' => true])->assertCreated()->json('data.id');

    $copied = Accounts::candidateRows(Accounts::ballotRows($copy)[0]['uuid']);
    expect($copied)->toHaveCount(1)
        ->and($copied[0]['photo_file'])->toBeNull()
        ->and(Team::mediaFiles())->toHaveCount(2);
});

// ---- DELETE

it('removes the photo and its two files [FR-CAND-02] (DELETE scenario 1)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    putCandPhoto($this, $id, Images::png(600, 600))->assertOk();

    $this->browser->delete(candPhotoUrl($id))->assertNoContent();

    expect(Team::mediaFiles())->toBe([])
        ->and(Accounts::candidateRow($id)['photo_file'])->toBeNull();
    $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk()->assertJsonPath('data.0.candidates.0.photo', null);
});

it('answers 204 when there is no photo, and again a second time [FR-CAND-02] (DELETE scenario 2)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $this->browser->delete(candPhotoUrl($id))->assertNoContent();
    $this->browser->delete(candPhotoUrl($id))->assertNoContent();
});

it('answers 409 for an election that is not a draft and keeps the photo [FR-SEC-06] (DELETE scenario 3)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this, ['status' => 'closed'], ['photo_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7']);

    $this->browser->delete(candPhotoUrl($id))->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::candidateRow($id)['photo_file'])->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7');
});

it('answers 401, 403 and 419 on the removal like the upload [FR-CAND-02] (DELETE scenarios 5 to 7)', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);

    $this->browser->delete(candPhotoUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->delete(candPhotoUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');

    $anonymous = new AuthClient($this);
    $anonymous->delete(candPhotoUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

// ---- what deletes the files

it('removes the photo files when the candidate is deleted [FR-CAND-02] (side effect of DELETE /candidates/{candidate})', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    putCandPhoto($this, $id, Images::png(600, 600))->assertOk();
    expect(Team::mediaFiles())->toHaveCount(2);

    $this->browser->delete("/api/v1/candidates/{$id}")->assertNoContent();

    expect(Team::mediaFiles())->toBe([]);
});

it('removes the photo files of its candidates when a ballot is deleted [FR-BAL-01] (side effect of DELETE /ballots/{ballot})', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    putCandPhoto($this, $id, Images::png(600, 600))->assertOk();
    $ballot = Accounts::ballotRows($election)[0]['uuid'];

    $this->browser->delete("/api/v1/ballots/{$ballot}")->assertNoContent();

    expect(Team::mediaFiles())->toBe([])
        ->and(Accounts::candidateRow($id))->toBeNull();
});

it('removes candidate photos and party logos when the election is deleted [FR-ELEC-03] (side effect of DELETE /elections/{election})', function () {
    [$t, $election, $id] = candPhotoSignedIn($this);
    $party = Accounts::plantParty(['election' => $election]);
    putCandPhoto($this, $id, Images::png(600, 600))->assertOk();
    $this->browser->upload('PUT', "/api/v1/parties/{$party}/logo", ['file' => Images::upload(Images::png(300, 300), 'logo.png')])->assertOk();
    expect(Team::mediaFiles())->toHaveCount(4);

    $this->browser->delete("/api/v1/elections/{$election}")->assertNoContent();

    expect(Team::mediaFiles())->toBe([]);
});
