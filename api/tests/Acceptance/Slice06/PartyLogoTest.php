<?php

/*
 * PUT and DELETE /api/v1/parties/{party}/logo —
 * docs/api/parties/PUT-parties-{party}-logo.md and DELETE-parties-{party}-logo.md
 * Tenant suite, routes api/v1/parties/{party}/logo: another institution's party answers 404, the same
 * as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Images;
use Tests\Support\Team;

const LOGO_UNKNOWN_PARTY = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function logoUrl(string $uuid): string
{
    return "/api/v1/parties/{$uuid}/logo";
}

/** A party of A in a draft (or the given status) and the owner of A signed in. Returns [team, election, party]. */
function logoSignedIn($test, array $election = [], array $party = []): array
{
    $t = Team::two();
    $e = Accounts::plantElection($election + ['institution' => $t['a']['owner']['institution']]);
    $p = Accounts::plantParty($party + ['election' => $e, 'name' => 'Avant']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $e, $p];
}

function putLogo($test, string $id, string $bytes, string $name = 'logo.png')
{
    return $test->browser->upload('PUT', logoUrl($id), ['file' => Images::upload($bytes, $name)]);
}

it('accepts a PNG, a JPEG and a WebP and answers the party with its logo [FR-CAND-01, FR-CAND-04] (scenario 1)', function (string $format) {
    [$t, $election, $id] = logoSignedIn($this);

    $response = putLogo($this, $id, Images::$format(600, 600), "logo.{$format}")->assertOk();

    $logo = $response->json('data.logo');
    expect($response->json('data.id'))->toBe($id)
        ->and(array_keys($logo))->toBe(['sm', 'md'])
        ->and($logo['sm'])->toMatch('#^/media/[0-9a-f-]{36}-96\.webp$#')
        ->and($logo['md'])->toMatch('#^/media/[0-9a-f-]{36}-192\.webp$#')
        ->and(Team::mediaFiles())->toHaveCount(2);
    $this->browser->get("/api/v1/elections/{$election}/parties")->assertOk()->assertJsonPath('data.0.logo', $logo);
})->with(['png', 'jpeg', 'webp']);

it('replaces a logo that already exists and deletes the old files [FR-CAND-01] (scenario 2)', function () {
    [$t, $election, $id] = logoSignedIn($this);
    $first = putLogo($this, $id, Images::png(600, 600))->assertOk()->json('data.logo');
    $firstFiles = Team::mediaFiles();

    $second = putLogo($this, $id, Images::jpeg(600, 600), 'b.jpg')->assertOk()->json('data.logo');

    expect($second['sm'])->not->toBe($first['sm'])
        ->and(Team::mediaFiles())->toHaveCount(2)
        ->and(array_intersect(Team::mediaFiles(), $firstFiles))->toBe([]);
});

it('answers 422 when the file part is missing [FR-CAND-01] (scenario 3)', function () {
    [$t, $election, $id] = logoSignedIn($this);

    $response = $this->browser->upload('PUT', logoUrl($id), []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('required')
        ->and(Team::mediaFiles())->toBe([]);
});

it('answers 413 above 5 MB and accepts exactly 5 MB [FR-CAND-04] (scenario 4)', function () {
    [$t, $election, $id] = logoSignedIn($this);

    putLogo($this, $id, Images::pngOfSize(5 * 1024 * 1024 + 1))->assertStatus(413)->assertJsonPath('error.code', 'file_too_large');
    expect(Team::mediaFiles())->toBe([]);
    putLogo($this, $id, Images::pngOfSize(5 * 1024 * 1024))->assertOk();
});

it('answers 415 when the content is not a JPEG, PNG or WebP, is undecodable, or is animated [NFR-SEC-06] (scenario 5)', function (string $bytes, string $name) {
    [$t, $election, $id] = logoSignedIn($this);

    putLogo($this, $id, $bytes, $name)->assertStatus(415)->assertJsonPath('error.code', 'file_type_not_allowed');
    expect(Team::mediaFiles())->toBe([]);
})->with([
    'a GIF named .png' => fn () => [Images::gif(), 'logo.png'],
    'a PDF named .png' => fn () => [Images::pdf(), 'logo.png'],
    'a script named .php' => fn () => [Images::script(), 'logo.php'],
    'a truncated PNG' => fn () => [Images::truncatedPng(), 'logo.png'],
    'an animated WebP' => fn () => [Images::animatedWebp(), 'logo.webp'],
    'an animated PNG' => fn () => [Images::animatedPng(), 'logo.png'],
]);

it('answers 422 above 8 000 pixels on a side or 40 million pixels, from the header alone [NFR-SEC-06] (scenario 6)', function (int $width, int $height) {
    [$t, $election, $id] = logoSignedIn($this);

    $response = putLogo($this, $id, Images::pngClaiming($width, $height));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('dimensions')
        ->and(Team::mediaFiles())->toBe([]);
})->with([[8001, 100], [100, 8001], [7000, 6000]]);

it('answers 409 for an election that is not a draft and stores nothing [FR-SEC-06] (scenario 7)', function (string $status) {
    [$t, $election, $id] = logoSignedIn($this, ['status' => $status]);

    putLogo($this, $id, Images::png())->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Team::mediaFiles())->toBe([]);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('checks the record, then the state, then the file [FR-SEC-06] (scenario 7)', function () {
    [$t, $election, $id] = logoSignedIn($this, ['status' => 'open']);

    putLogo($this, $id, Images::pdf(), 'logo.png')->assertStatus(409);
});

it('answers 404, the same for every case, for a party that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    $t = Team::two();
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantParty(['election' => $foreignElection]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape(putLogo($this, LOGO_UNKNOWN_PARTY, Images::png()));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape(putLogo($this, $target, Images::png())))->toBe($unknown);
    }
    expect(Team::shape($this->browser->delete(logoUrl($foreign))))->toBe(Team::shape($this->browser->delete(logoUrl(LOGO_UNKNOWN_PARTY))))
        ->and(Team::mediaFiles())->toBe([])
        ->and(Accounts::partyRow($foreign)['logo_file'])->toBeNull();
});

it('answers 401 when nobody is signed in [FR-CAND-01] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantParty(['election' => $election]);

    putLogo($this, $id, Images::png())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    [$t, $election, $id] = logoSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    putLogo($this, $id, Images::png())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    [$t, $election, $id] = logoSignedIn($this);

    $this->browser->upload('PUT', logoUrl($id), ['file' => Images::upload(Images::png())], [], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 429 above 20 uploads an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    [$t, $election, $id] = logoSignedIn($this);

    foreach (range(1, 20) as $i) {
        putLogo($this, $id, Images::png(300 + $i, 300))->assertOk();
    }

    $response = putLogo($this, $id, Images::png());

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than PUT or DELETE [NFR-SEC-01] (scenario 13)', function (string $method) {
    [$t, $election, $id] = logoSignedIn($this);

    $response = $this->browser->other($method, logoUrl($id));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('PUT')->toContain('DELETE')->not->toContain($method);
})->with(['GET', 'POST', 'PATCH']);

it('lets a manager upload and remove a logo [FR-CAND-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $id = Accounts::plantParty(['election' => $election]);
    Team::signIn($this, $t['a']['manager']);

    putLogo($this, $id, Images::png(600, 600))->assertOk();
    $this->browser->delete(logoUrl($id))->assertNoContent();
    expect(Team::mediaFiles())->toBe([]);
});

it('is not copied by a duplicate of the election [FR-ELEC-06] (notes)', function () {
    [$t, $election, $id] = logoSignedIn($this);
    putLogo($this, $id, Images::png(600, 600))->assertOk();

    $copy = $this->browser->post("/api/v1/elections/{$election}/duplicate", [])->assertCreated()->json('data.id');

    expect(Accounts::partyRows($copy)[0]['logo_file'])->toBeNull()
        ->and(Team::mediaFiles())->toHaveCount(2);
});

// ---- DELETE

it('removes the logo and its two files [FR-CAND-01] (DELETE scenario 1)', function () {
    [$t, $election, $id] = logoSignedIn($this);
    putLogo($this, $id, Images::png(600, 600))->assertOk();

    $this->browser->delete(logoUrl($id))->assertNoContent();

    expect(Team::mediaFiles())->toBe([])
        ->and(Accounts::partyRow($id)['logo_file'])->toBeNull();
    $this->browser->get("/api/v1/elections/{$election}/parties")->assertOk()->assertJsonPath('data.0.logo', null);
});

it('answers 204 when there is no logo, and again a second time [FR-CAND-01] (DELETE scenario 2)', function () {
    [$t, $election, $id] = logoSignedIn($this);

    $this->browser->delete(logoUrl($id))->assertNoContent();
    $this->browser->delete(logoUrl($id))->assertNoContent();
});

it('answers 409 for an election that is not a draft and keeps the logo [FR-SEC-06] (DELETE scenario 3)', function () {
    [$t, $election, $id] = logoSignedIn($this, ['status' => 'closed'], ['logo_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7']);

    $this->browser->delete(logoUrl($id))->assertStatus(409)->assertJsonPath('error.code', 'election_not_editable');
    expect(Accounts::partyRow($id)['logo_file'])->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7');
});

it('answers 401, 403 and 419 on the removal like the upload [FR-CAND-01] (DELETE scenarios 5 to 7)', function () {
    [$t, $election, $id] = logoSignedIn($this);

    $this->browser->delete(logoUrl($id), [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->delete(logoUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');

    $anonymous = new AuthClient($this);
    $anonymous->delete(logoUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

// ---- the party itself

it('removes the logo files when the party is deleted [FR-CAND-01] (side effect of DELETE /parties/{party})', function () {
    [$t, $election, $id] = logoSignedIn($this);
    putLogo($this, $id, Images::png(600, 600))->assertOk();
    expect(Team::mediaFiles())->toHaveCount(2);

    $this->browser->delete("/api/v1/parties/{$id}")->assertNoContent();

    expect(Team::mediaFiles())->toBe([]);
});
