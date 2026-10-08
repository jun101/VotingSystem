<?php

/*
 * PUT /api/v1/institution/logo — docs/api/institution/PUT-institution-logo.md
 * Route in the tenant suite: api/v1/institution/logo (the tenant itself, no record named).
 */

use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

const LOGO = '/api/v1/institution/logo';

function logoSignedIn($test, string $role = 'owner'): array
{
    $t = Team::two();
    $account = $role === 'owner' ? $t['a']['owner'] : $t['a']['manager'];
    Team::signIn($test, $account);

    return $account;
}

function putLogo($test, string $bytes, string $name = 'logo.png')
{
    return $test->browser->upload('PUT', LOGO, ['file' => Images::upload($bytes, $name)]);
}

it('accepts a PNG, a JPEG and a WebP and answers the new profile [FR-INST-02, FR-CAND-04] (scenario 1)', function (string $format) {
    $owner = logoSignedIn($this);

    $response = putLogo($this, Images::$format(120, 60), "logo.{$format}")->assertOk();

    $logo = $response->json('data.logo');
    expect(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'name', 'type', 'description', 'address', 'city', 'phone', 'contact_email', 'timezone', 'language', 'logo'])
        ->and($response->json('data.id'))->toBe($owner['institution'])
        ->and(array_keys($logo))->toBe(['sm', 'md', 'lg'])
        ->and($logo['sm'])->toMatch('#^/media/[0-9a-f-]{36}-64\.webp$#')
        ->and($logo['md'])->toMatch('#^/media/[0-9a-f-]{36}-160\.webp$#')
        ->and($logo['lg'])->toMatch('#^/media/[0-9a-f-]{36}-480\.webp$#');

    $this->browser->get('/api/v1/institution')->assertOk()->assertJsonPath('data.logo', $logo);
})->with(['png', 'jpeg', 'webp']);

it('replaces a logo that already exists and deletes the old files [FR-INST-02] (scenario 2)', function () {
    logoSignedIn($this);

    $first = putLogo($this, Images::png(100, 100))->assertOk()->json('data.logo');
    $firstFiles = Team::mediaFiles();
    expect($firstFiles)->toHaveCount(3);

    $second = putLogo($this, Images::jpeg(100, 100), 'second.jpg')->assertOk()->json('data.logo');

    expect($second['sm'])->not->toBe($first['sm']);
    $files = Team::mediaFiles();
    expect($files)->toHaveCount(3)
        ->and(array_intersect($files, $firstFiles))->toBe([]);
});

it('answers 422 when the file part is missing [FR-INST-02] (scenario 3)', function () {
    logoSignedIn($this);

    $response = $this->browser->upload('PUT', LOGO, []);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('required')
        ->and(Team::mediaFiles())->toBe([]);
});

it('answers 413 above 5 MB and accepts exactly 5 MB [FR-CAND-04] (scenario 4)', function () {
    logoSignedIn($this);

    $response = putLogo($this, Images::pngOfSize(5 * 1024 * 1024 + 1));
    $response->assertStatus(413)->assertJsonPath('error.code', 'file_too_large');
    expect(array_keys($response->json('error')))->toEqualCanonicalizing(['code', 'message'])
        ->and(Team::mediaFiles())->toBe([]);

    putLogo($this, Images::pngOfSize(5 * 1024 * 1024))->assertOk();
    expect(Team::mediaFiles())->toHaveCount(3);
});

it('answers 415 when the content is not a JPEG, PNG or WebP, whatever the name says [NFR-SEC-06] (scenario 5)', function (string $bytes, string $name) {
    logoSignedIn($this);

    $response = putLogo($this, $bytes, $name);

    $response->assertStatus(415)->assertJsonPath('error.code', 'file_type_not_allowed');
    expect(Team::mediaFiles())->toBe([]);
})->with([
    'a GIF named .png' => fn () => [Images::gif(), 'logo.png'],
    'a PDF named .png' => fn () => [Images::pdf(), 'logo.png'],
    'a script named .png' => fn () => [Images::script(), 'logo.png'],
    'a script named .php' => fn () => [Images::script(), 'logo.php'],
    'text named .jpg' => fn () => ['hello', 'logo.jpg'],
    'an animated WebP' => fn () => [Images::animatedWebp(), 'logo.webp'],
    'an animated PNG' => fn () => [Images::animatedPng(), 'logo.png'],
]);

it('answers 415 for a PNG that cannot be decoded [NFR-SEC-06] (scenario 6)', function () {
    logoSignedIn($this);

    putLogo($this, Images::truncatedPng())->assertStatus(415)->assertJsonPath('error.code', 'file_type_not_allowed');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 422 above 8 000 pixels on a side or 40 million pixels, from the header alone [NFR-SEC-06] (scenario 7)', function (int $width, int $height) {
    logoSignedIn($this);

    $response = putLogo($this, Images::pngClaiming($width, $height));

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect($response->json('error.fields.file'))->toContain('dimensions')
        ->and(Team::mediaFiles())->toBe([]);
})->with([[8001, 100], [100, 8001], [7000, 6000]]);

it('answers 403 to a manager and stores nothing [FR-INST-03] (scenario 8)', function () {
    logoSignedIn($this, 'manager');

    putLogo($this, Images::png())->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 401 when nobody is signed in or the session has gone [FR-INST-02] (scenario 9)', function () {
    putLogo($this, Images::png())->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 10)', function () {
    $owner = logoSignedIn($this);
    Accounts::suspend($owner['institution']);

    putLogo($this, Images::png())->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 419 when the CSRF token is missing or wrong [NFR-SEC-04] (scenario 11)', function () {
    logoSignedIn($this);

    $this->browser->upload('PUT', LOGO, ['file' => Images::upload(Images::png())], [], false)
        ->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    expect(Team::mediaFiles())->toBe([]);
});

it('answers 429 above 10 uploads an hour from one user [NFR-SEC-05] (scenario 12)', function () {
    logoSignedIn($this);

    foreach (range(1, 10) as $i) {
        putLogo($this, Images::png(30 + $i, 30))->assertOk();
    }

    $response = putLogo($this, Images::png());

    $response->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('answers 405 to another method than PUT or DELETE [NFR-SEC-01] (scenario 13)', function (string $method) {
    logoSignedIn($this);

    $response = $this->browser->other($method, LOGO);

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('PUT')->toContain('DELETE')->not->toContain($method);
})->with(['GET', 'POST', 'PATCH']);

it('checks the role before the rate limit: a manager gets 403 every time, never 429 [NFR-SEC-05] (scenario 8)', function () {
    logoSignedIn($this, 'manager');

    foreach (range(1, 12) as $i) {
        putLogo($this, Images::png())->assertStatus(403);
    }
});
