<?php

/*
 * What the cover upload writes — docs/api/elections/PUT-elections-{election}-cover.md (Side effects),
 * docs/design/database.md section 5, FR-CAND-04: the server re-encodes the picture and the original
 * is never kept.
 * Routes in the tenant suite: api/v1/elections/{election}/cover.
 */

use Illuminate\Support\Facades\Storage;
use Tests\Support\Accounts;
use Tests\Support\Images;
use Tests\Support\Team;

function storageElection($test): array
{
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $id];
}

function storageCover($test, string $id, string $bytes, string $name = 'cover.png')
{
    return $test->browser->upload('PUT', "/api/v1/elections/{$id}/cover", ['file' => Images::upload($bytes, $name)])->assertOk();
}

/** @return array<int, array{int, int}> pixel sizes by the number in the file name */
function coverSizes(): array
{
    $sizes = [];
    foreach (Team::mediaFiles() as $file) {
        $sizes[(int) substr($file, 37)] = Images::size(Storage::disk('media')->get($file));
    }
    ksort($sizes);

    return $sizes;
}

it('writes two WebP files named after one new UUID and stores that UUID on the election [FR-CAND-04]', function () {
    [$t, $id] = storageElection($this);

    storageCover($this, $id, Images::png(2000, 1000));

    $files = Team::mediaFiles();
    $uuids = array_unique(array_map(fn ($f) => substr($f, 0, 36), $files));
    expect($files)->toHaveCount(2)
        ->and($uuids)->toHaveCount(1)
        ->and($uuids[0])->toMatch(UUID_V4)
        ->and(array_map(fn ($f) => substr($f, 36), $files))->toEqualCanonicalizing(['-480.webp', '-960.webp'])
        ->and(Accounts::electionRow($id)['cover_file'])->toBe($uuids[0]);
    foreach ($files as $file) {
        expect(Images::mime(Storage::disk('media')->get($file)))->toBe('image/webp');
    }
});

it('resizes to 480 and 960 pixels wide and keeps the ratio [FR-CAND-04]', function () {
    [$t, $id] = storageElection($this);

    storageCover($this, $id, Images::png(2000, 1000));

    expect(coverSizes())->toBe([480 => [480, 240], 960 => [960, 480]]);
});

it('never enlarges a small picture [FR-CAND-04]', function () {
    [$t, $id] = storageElection($this);

    storageCover($this, $id, Images::png(700, 350));

    expect(coverSizes())->toBe([480 => [480, 240], 960 => [700, 350]]);
});

it('applies the rotation of the EXIF data, then drops every piece of metadata [FR-CAND-04, NFR-SEC-06]', function () {
    [$t, $id] = storageElection($this);
    $jpeg = Images::jpegWithMetadata(1600, 800);

    storageCover($this, $id, $jpeg, 'photo.jpg');

    foreach (Team::mediaFiles() as $file) {
        $bytes = Storage::disk('media')->get($file);
        expect(str_contains($bytes, Images::SECRET))->toBeFalse()
            ->and(str_contains($bytes, 'Exif'))->toBeFalse()
            ->and(str_contains($bytes, 'EXIF'))->toBeFalse();
    }
    // Orientation 6: the 1600 x 800 picture is shown 800 x 1600; reduced to 480 wide it is 480 x 960.
    expect(coverSizes()[480])->toBe([480, 960]);
});

it('keeps neither the original name nor the original bytes anywhere on disk [FR-CAND-04]', function () {
    [$t, $id] = storageElection($this);
    $bytes = Images::png(1300, 700);

    storageCover($this, $id, $bytes, 'affiche-secrete-du-conseil.png');

    foreach (Team::mediaFiles() as $file) {
        expect($file)->not->toContain('affiche')->not->toContain('conseil');
        expect(Storage::disk('media')->get($file))->not->toBe($bytes);
    }
});

it('keeps the previous cover when the new file is refused [FR-CAND-04]', function () {
    [$t, $id] = storageElection($this);
    storageCover($this, $id, Images::png(1300, 700));
    $files = Team::mediaFiles();
    $stored = Accounts::electionRow($id)['cover_file'];

    $this->browser->upload('PUT', "/api/v1/elections/{$id}/cover", ['file' => Images::upload(Images::pdf(), 'cover.png')])->assertStatus(415);

    expect(Team::mediaFiles())->toBe($files)
        ->and(Accounts::electionRow($id)['cover_file'])->toBe($stored);
});

it('keeps one election\'s files apart from another\'s [FR-INST-05]', function () {
    [$t, $id] = storageElection($this);
    $second = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    storageCover($this, $id, Images::png(1300, 700));
    storageCover($this, $second, Images::png(1300, 700), 'b.png');
    $files = Team::mediaFiles();
    storageCover($this, $id, Images::jpeg(1300, 700), 'c.jpg');

    expect(Team::mediaFiles())->toHaveCount(4)
        ->and(array_intersect($files, Team::mediaFiles()))->toHaveCount(2);
    expect(Accounts::electionRow($second)['cover_file'])->not->toBe(Accounts::electionRow($id)['cover_file']);
});

it('writes nothing to the log but the request id and the outcome [FR-CAND-04, NFR-SEC-06]', function () {
    [$t, $id] = storageElection($this);

    storageCover($this, $id, Images::png(1300, 700), 'secret-cover-name.png');

    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        expect(file_get_contents($log))->not->toContain('secret-cover-name');
    }
});
