<?php

/*
 * What the logo upload writes — docs/api/institution/PUT-institution-logo.md (Side effects,
 * Notes), docs/design/database.md section 5, FR-CAND-04: the server re-encodes the picture and
 * the original file is never served.
 * Route in the tenant suite: api/v1/institution/logo.
 */

use Illuminate\Support\Facades\Storage;
use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

function storageSignedIn($test): array
{
    $owner = Team::two()['a']['owner'];
    Team::signIn($test, $owner);

    return $owner;
}

function storageUpload($test, string $bytes, string $name = 'logo.png')
{
    return $test->browser->upload('PUT', '/api/v1/institution/logo', ['file' => Images::upload($bytes, $name)])->assertOk();
}

it('writes three WebP files named after one new UUID, and stores that UUID on the institution [FR-CAND-04]', function () {
    $owner = storageSignedIn($this);

    storageUpload($this, Images::png(1000, 500));

    $files = Team::mediaFiles();
    expect($files)->toHaveCount(3);

    $uuids = array_unique(array_map(fn ($f) => substr($f, 0, 36), $files));
    expect($uuids)->toHaveCount(1)
        ->and($uuids[0])->toMatch(UUID_V4)
        ->and(array_map(fn ($f) => substr($f, 36), $files))->toEqualCanonicalizing(['-160.webp', '-480.webp', '-64.webp'])
        ->and(Accounts::institutionRow($owner['institution'])['logo_file'])->toBe($uuids[0]);

    foreach ($files as $file) {
        expect(Images::mime(Storage::disk('media')->get($file)))->toBe('image/webp');
    }
});

it('resizes to 64, 160 and 480 pixels on the longest side and keeps the ratio [FR-CAND-04]', function () {
    storageSignedIn($this);

    storageUpload($this, Images::png(1000, 500));

    $sizes = [];
    foreach (Team::mediaFiles() as $file) {
        $sizes[(int) substr($file, 37)] = Images::size(Storage::disk('media')->get($file));
    }
    ksort($sizes);

    expect($sizes)->toBe([64 => [64, 32], 160 => [160, 80], 480 => [480, 240]]);
});

it('never enlarges a small picture [FR-CAND-04]', function () {
    storageSignedIn($this);

    storageUpload($this, Images::png(100, 50));

    $sizes = [];
    foreach (Team::mediaFiles() as $file) {
        $sizes[(int) substr($file, 37)] = Images::size(Storage::disk('media')->get($file));
    }
    ksort($sizes);

    expect($sizes)->toBe([64 => [64, 32], 160 => [100, 50], 480 => [100, 50]]);
});

it('applies the rotation of the EXIF data, then drops every piece of metadata [FR-CAND-04, NFR-SEC-06]', function () {
    storageSignedIn($this);
    $jpeg = Images::jpegWithMetadata(400, 200);
    expect(str_contains($jpeg, Images::SECRET))->toBeTrue();

    storageUpload($this, $jpeg, 'photo.jpg');

    // Orientation 6: the 400 x 200 picture is shown 200 x 400, so the longest side is the height.
    $largest = null;
    foreach (Team::mediaFiles() as $file) {
        $bytes = Storage::disk('media')->get($file);
        expect(str_contains($bytes, Images::SECRET))->toBeFalse()
            ->and(str_contains($bytes, 'Exif'))->toBeFalse()
            ->and(str_contains($bytes, 'EXIF'))->toBeFalse()
            ->and(str_contains($bytes, 'XMP'))->toBeFalse();
        if (str_ends_with($file, '-480.webp')) {
            $largest = Images::size($bytes);
        }
    }

    expect($largest)->toBe([200, 400]);
});

it('keeps neither the original name nor the original bytes anywhere on disk [FR-CAND-04]', function () {
    $owner = storageSignedIn($this);
    $bytes = Images::png(300, 300);

    storageUpload($this, $bytes, 'photo-de-famille-personnelle.png');

    foreach (Team::mediaFiles() as $file) {
        expect($file)->not->toContain('photo')->not->toContain('famille');
        expect(Storage::disk('media')->get($file))->not->toBe($bytes);
    }

    // The only copy of the upload is the three re-encoded files: nothing else was written to the disk,
    // and no other disk of the application holds the bytes.
    foreach (['local', 'public'] as $name) {
        if (config("filesystems.disks.{$name}") === null) {
            continue;
        }
        foreach (Storage::disk($name)->allFiles() as $file) {
            expect(Storage::disk($name)->get($file))->not->toBe($bytes);
        }
    }

    expect(Accounts::institutionRow($owner['institution'])['logo_file'])->toMatch(UUID_V4);
});

it('keeps the previous logo when the new file is refused [FR-CAND-04]', function () {
    $owner = storageSignedIn($this);
    storageUpload($this, Images::png(100, 100));
    $files = Team::mediaFiles();
    $stored = Accounts::institutionRow($owner['institution'])['logo_file'];

    $this->browser->upload('PUT', '/api/v1/institution/logo', ['file' => Images::upload(Images::pdf(), 'logo.png')])->assertStatus(415);

    expect(Team::mediaFiles())->toBe($files)
        ->and(Accounts::institutionRow($owner['institution'])['logo_file'])->toBe($stored);
});

it('keeps each institution\'s files apart and deletes only its own [FR-INST-05]', function () {
    $t = Team::two();

    Team::signIn($this, $t['b']['owner']);
    storageUpload($this, Images::png(100, 100));
    $bFiles = Team::mediaFiles();

    $second = new Tests\Support\AuthClient($this);
    $second->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();
    $second->upload('PUT', '/api/v1/institution/logo', ['file' => Images::upload(Images::png(100, 100))])->assertOk();
    $second->upload('PUT', '/api/v1/institution/logo', ['file' => Images::upload(Images::jpeg(100, 100), 'b.jpg')])->assertOk();

    expect(array_intersect($bFiles, Team::mediaFiles()))->toBe($bFiles)
        ->and(Team::mediaFiles())->toHaveCount(6)
        ->and(Accounts::institutionRow($t['b']['owner']['institution'])['logo_file'])->not->toBeNull();
});

it('writes nothing to the log but the request id and the outcome [FR-CAND-04, NFR-SEC-06]', function () {
    storageSignedIn($this);
    storageUpload($this, Images::png(100, 100), 'secret-name.png');

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        expect(file_get_contents($log))->not->toContain('secret-name');
    }
});
