<?php

/*
 * PUT and DELETE /api/v1/elections/{election}/cover —
 * docs/api/elections/PUT-elections-{election}-cover.md and DELETE-elections-{election}-cover.md
 * Tenant suite, routes api/v1/elections/{election}/cover: another institution's election answers 404,
 * the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

function coverUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/cover";
}

function coverDraftSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $id = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $id];
}

function putCover($test, string $id, string $bytes, string $name = 'cover.png')
{
    return $test->browser->upload('PUT', coverUrl($id), ['file' => Images::upload($bytes, $name)]);
}

it('accepts a PNG, a JPEG and a WebP and answers the election with its cover [FR-ELEC-01, FR-CAND-04] (scenario 1)', function (string $format) {
    [$t, $id] = coverDraftSignedIn($this);

    $response = putCover($this, $id, Images::$format(1200, 600), "cover.{$format}")->assertOk();

    $cover = $response->json('data.cover');
    expect($response->json('data.id'))->toBe($id)
        ->and(array_keys($cover))->toBe(['sm', 'md'])
        ->and($cover['sm'])->toMatch('#^/media/[0-9a-f-]{36}-480\.webp$#')
        ->and($cover['md'])->toMatch('#^/media/[0-9a-f-]{36}-960\.webp$#');
    $this->browser->get('/api/v1/elections/'.$id)->assertOk()->assertJsonPath('data.cover', $cover);
})->with(['png', 'jpeg', 'webp']);

it('replaces a cover that already exists and deletes the old files [FR-ELEC-01] (scenario 2)', function () {
    [$t, $id] = coverDraftSignedIn($this);
    $first = putCover($this, $id, Images::png(1200, 600))->assertOk()->json('data.cover');
    $firstFiles = Team::mediaFiles();
    expect($firstFiles)->toHaveCount(2);

    $second = putCover($this, $id, Images::jpeg(1200, 600), 'b.jpg')->assertOk()->json('data.cover');

    expect($second['sm'])->not->toBe($first['sm'])
        ->and(Team::mediaFiles())->toHaveCount(2)
        ->and(array_intersect(Team::mediaFiles(), $firstFiles))->toBe([]);
});

it('answers 422 when the file part is missing [FR-ELEC-01] (scenario 3)', function () {
    [$t, $id] = coverDraftSignedIn($this);

    $response = $this->browser->upload('PUT', coverUrl($id), []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('required')
        ->and(Team::mediaFiles())->toBe([]);
});

it('answers 413 above 5 MB and accepts exactly 5 MB [FR-CAND-04] (scenario 4)', function () {
    [$t, $id] = coverDraftSignedIn($this);

    putCover($this, $id, Images::pngOfSize(5 * 1024 * 1024 + 1))->assertStatus(413)->assertJsonPath('error.code', 'file_too_large');
    expect(Team::mediaFiles())->toBe([]);

    putCover($this, $id, Images::pngOfSize(5 * 1024 * 1024))->assertOk();
});

it('answers 415 when the content is not a JPEG, PNG or WebP, is undecodable, or is animated [NFR-SEC-06] (scenario 5)', function (string $bytes, string $name) {
    [$t, $id] = coverDraftSignedIn($this);

    putCover($this, $id, $bytes, $name)->assertStatus(415)->assertJsonPath('error.code', 'file_type_not_allowed');
    expect(Team::mediaFiles())->toBe([]);
})->with([
    'a GIF named .png' => fn () => [Images::gif(), 'cover.png'],
    'a PDF named .png' => fn () => [Images::pdf(), 'cover.png'],
    'a script named .php' => fn () => [Images::script(), 'cover.php'],
    'a truncated PNG' => fn () => [Images::truncatedPng(), 'cover.png'],
    'an animated WebP' => fn () => [Images::animatedWebp(), 'cover.webp'],
    'an animated PNG' => fn () => [Images::animatedPng(), 'cover.png'],
]);

it('answers 422 above 8 000 pixels on a side or 40 million pixels, from the header alone [NFR-SEC-06] (scenario 6)', function (int $width, int $height) {
    [$t, $id] = coverDraftSignedIn($this);

    $response = putCover($this, $id, Images::pngClaiming($width, $height));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('dimensions')
        ->and(Team::mediaFiles())->toBe([]);
})->with([[8001, 100], [100, 8001], [7000, 6000]]);

it('answers 409 for an election that is not a draft and stores nothing [FR-ELEC-03] (scenario 7)', function (string $status) {
    [$t, $id] = coverDraftSignedIn($this, ['status' => $status]);

    putCover($this, $id, Images::png())->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Team::mediaFiles())->toBe([]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape(putCover($this, '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa', Images::png()));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape(putCover($this, $target, Images::png())))->toBe($unknown);
    }
    expect(Team::shape($this->browser->delete(coverUrl($foreign))))->toBe(Team::shape($this->browser->delete(coverUrl('3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa'))))
        ->and(Team::mediaFiles())->toBe([])
        ->and(Accounts::electionRow($foreign)['cover_file'])->toBeNull();
});

it('checks the record, then the state, then the file [FR-ELEC-03] (scenario 8)', function () {
    [$t, $id] = coverDraftSignedIn($this, ['status' => 'open']);

    // A bad file on a non-draft is a 409 (the state is checked before the file), on another institution's a 404.
    putCover($this, $id, Images::pdf(), 'cover.png')->assertStatus(409);
});

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-01] (scenario 9)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    putCover($this, $id, Images::png())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    [$t, $id] = coverDraftSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    putCover($this, $id, Images::png())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    [$t, $id] = coverDraftSignedIn($this);

    $this->browser->upload('PUT', coverUrl($id), ['file' => Images::upload(Images::png())], [], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 429 above 20 uploads an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $id] = coverDraftSignedIn($this);

    foreach (range(1, 20) as $i) {
        putCover($this, $id, Images::png(300 + $i, 200))->assertOk();
    }

    $response = putCover($this, $id, Images::png());

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than PUT or DELETE [NFR-SEC-01] (scenario 13)', function (string $method) {
    [$t, $id] = coverDraftSignedIn($this);

    $response = $this->browser->other($method, coverUrl($id));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('PUT')->toContain('DELETE')->not->toContain($method);
})->with(['GET', 'POST', 'PATCH']);

it('lets a manager upload and remove a cover [FR-ELEC-01] (scenario 1)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    putCover($this, $id, Images::png(1200, 600))->assertOk();
    $this->browser->delete(coverUrl($id))->assertNoContent();
    expect(Team::mediaFiles())->toBe([]);
});

// ---- DELETE

it('removes the cover and its two files [FR-ELEC-01] (DELETE scenario 1)', function () {
    [$t, $id] = coverDraftSignedIn($this);
    putCover($this, $id, Images::png(1200, 600))->assertOk();

    $this->browser->delete(coverUrl($id))->assertNoContent();

    expect(Team::mediaFiles())->toBe([])
        ->and(Accounts::electionRow($id)['cover_file'])->toBeNull();
    $this->browser->get('/api/v1/elections/'.$id)->assertOk()->assertJsonPath('data.cover', null);
});

it('answers 204 when there is no cover, and again a second time [FR-ELEC-01] (DELETE scenario 2)', function () {
    [$t, $id] = coverDraftSignedIn($this);

    $this->browser->delete(coverUrl($id))->assertNoContent();
    $this->browser->delete(coverUrl($id))->assertNoContent();
});

it('answers 409 for an election that is not a draft and keeps the cover [FR-ELEC-03] (DELETE scenario 3)', function () {
    [$t, $id] = coverDraftSignedIn($this, ['status' => 'closed', 'cover_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7']);

    $this->browser->delete(coverUrl($id))->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::electionRow($id)['cover_file'])->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7');
});

it('answers 401, 403 and 419 on the removal like the upload [FR-ELEC-01] (DELETE scenarios 5 to 7)', function () {
    [$t, $id] = coverDraftSignedIn($this);

    $this->browser->delete(coverUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->delete(coverUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');

    $anonymous = new Tests\Support\AuthClient($this);
    $anonymous->delete(coverUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});
