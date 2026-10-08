<?php

use App\Support\Media\ImageReEncoder;
use Illuminate\Support\Facades\Storage;

/*
 * A large picture the rules allow (8 000 pixels on a side at most, 40 million pixels) is
 * re-encoded within the 256 MB the server gives every request, because the encoder raises
 * the ceiling around its own work and puts it back.
 */

it('re-encodes a picture of nearly 40 million pixels and restores the memory limit', function () {
    // Building the test picture takes the memory too: this process alone gets more.
    ini_set('memory_limit', '1G');

    $width = 7900;
    $height = 5000;
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 90, 160));
    $path = tempnam(sys_get_temp_dir(), 'big');
    imagepng($image, $path, 9);
    unset($image);

    expect(filesize($path))->toBeLessThan(ImageReEncoder::MAX_BYTES);

    // The ceiling the encoder must restore: the value of the server.
    ini_set('memory_limit', '256M');

    try {
        $uuid = app(ImageReEncoder::class)->store($path);
    } finally {
        @unlink($path);
    }

    expect(ini_get('memory_limit'))->toBe('256M');

    foreach (ImageReEncoder::SIZES as $size) {
        $name = ImageReEncoder::nameOf($uuid, $size);
        expect(Storage::disk(ImageReEncoder::DISK)->exists($name))->toBeTrue();
    }

    app(ImageReEncoder::class)->delete($uuid);
});
