<?php

namespace App\Support\Media;

use App\Exceptions\ApiException;
use finfo;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Turns an uploaded picture into the files the application serves, and nothing else: it
 * knows no logo and no candidate (docs/slices/04-profile-and-users.md; slice 06 reuses it).
 *
 * - The file is judged by its content: its first bytes (`finfo`), then its header, then a
 *   real decode. The size in pixels is read from the header, before anything is decoded.
 * - The rotation of the EXIF data is applied, then every piece of metadata is dropped: the
 *   files are written again from the pixels alone, as WebP.
 * - Three sizes (64, 160 and 480 pixels on the longest side), ratio kept, never enlarged.
 * - The files are named `{uuid}-{size}.webp` on the `media` disk. The upload itself is never
 *   written anywhere and nothing about it (name, path, bytes) is kept or logged.
 *
 * It holds no state of a request: the application stays in memory between requests.
 */
final class ImageReEncoder
{
    public const DISK = 'media';

    /** Pixels on the longest side of each version. */
    public const SIZES = [64, 160, 480];

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_SIDE = 8000;

    public const MAX_PIXELS = 40_000_000;

    private const QUALITY = 82;

    /** The memory ceiling while a picture is decoded and reduced. */
    private const DECODE_MEMORY = '512M';

    private const ALLOWED = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Re-encodes the picture at this path and writes the three versions. Returns the UUID
     * that names them. When anything fails, nothing is left on the disk.
     *
     * @throws ApiException 413 `file_too_large`, 415 `file_type_not_allowed`
     * @throws ValidationException `file: dimensions`
     */
    public function store(string $path): string
    {
        // More memory than the usual request gets, for this work only (40 million pixels at
        // 4 bytes each, and the copies GD makes); the previous ceiling is restored.
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', self::DECODE_MEMORY);

        try {
            return $this->reEncode($path);
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    private function reEncode(string $path): string
    {
        $source = $this->decode($path);
        $uuid = Str::uuid()->toString();
        $written = [];

        try {
            $large = $this->orient($this->fit($source['image'], max(self::SIZES)), $source['orientation']);
            $versions = [];
            $current = $large;

            // Each version is made from the next larger one: a gentle reduction each time.
            foreach (array_reverse(self::SIZES) as $size) {
                $current = $this->fit($current, $size);
                $versions[$size] = $current;
            }

            foreach ($versions as $size => $image) {
                $name = self::nameOf($uuid, $size);

                if (! $this->disk()->put($name, $this->encode($image))) {
                    throw new RuntimeException('The picture could not be written.');
                }

                $written[] = $name;
            }
        } catch (Throwable $e) {
            foreach ($written as $name) {
                $this->disk()->delete($name);
            }

            throw $e;
        }

        return $uuid;
    }

    /** Deletes the three files of a picture. A value that is not a UUID names nothing. */
    public function delete(?string $uuid): void
    {
        if ($uuid === null || ! Str::isUuid($uuid)) {
            return;
        }

        foreach (self::SIZES as $size) {
            $this->disk()->delete(self::nameOf($uuid, $size));
        }
    }

    /** The file name of one version. */
    public static function nameOf(string $uuid, int $size): string
    {
        return "{$uuid}-{$size}.webp";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Judges the file and decodes it.
     *
     * @return array{image: GdImage, orientation: int}
     */
    private function decode(string $path): array
    {
        $bytes = @filesize($path);

        if ($bytes === false) {
            throw new ApiException(415, 'file_type_not_allowed');
        }

        if ($bytes > self::MAX_BYTES) {
            throw new ApiException(413, 'file_too_large');
        }

        // 1. The first bytes say what it is, whatever the name or the declared type say.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($mime === false || ! in_array($mime, self::ALLOWED, true)) {
            throw new ApiException(415, 'file_type_not_allowed');
        }

        // 2. The header says how large it is, without decoding a pixel.
        $header = @getimagesize($path);

        if ($header === false || $header['mime'] !== $mime || ! isset(self::ALLOWED[$header[2]])) {
            throw new ApiException(415, 'file_type_not_allowed');
        }

        [$width, $height] = $header;

        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => ['dimensions']]);
        }

        // 2b. An animated picture is refused rather than reduced to its first frame.
        $contents = @file_get_contents($path);

        if ($contents === false || $this->isAnimated($contents, $mime)) {
            throw new ApiException(415, 'file_type_not_allowed');
        }

        // 3. Only now is it decoded; a file that does not decode is not a picture.
        $image = @imagecreatefromstring($contents);
        unset($contents);

        if ($image instanceof GdImage) {
            imagepalettetotruecolor($image);
        }

        if (! $image instanceof GdImage) {
            throw new ApiException(415, 'file_type_not_allowed');
        }

        return [
            'image' => $image,
            'orientation' => $mime === 'image/jpeg' ? $this->orientationOf($path) : 1,
        ];
    }

    /** Whether the picture holds more than one frame (animated WebP, APNG, GIF). */
    private function isAnimated(string $contents, string $mime): bool
    {
        if ($mime === 'image/webp') {
            // A WebP with animation is an extended file (`VP8X`) whose flag byte has bit 2 set.
            return substr($contents, 12, 4) === 'VP8X' && (ord($contents[20] ?? "\0") & 0x02) !== 0;
        }

        if ($mime === 'image/png') {
            // An `acTL` chunk before the first `IDAT` marks an animated PNG.
            $offset = 8;
            $length = strlen($contents);

            while ($offset + 8 <= $length) {
                $unpacked = unpack('Nsize', substr($contents, $offset, 4));
                $size = is_array($unpacked) && is_int($unpacked['size']) ? $unpacked['size'] : 0;
                $type = substr($contents, $offset + 4, 4);

                if ($type === 'acTL') {
                    return true;
                }

                if ($type === 'IDAT' || $type === 'IEND') {
                    return false;
                }

                $offset += 12 + $size;
            }
        }

        return false;
    }

    /** The EXIF orientation (1 to 8) of a JPEG; 1 when there is none. */
    private function orientationOf(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0');
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** The picture reduced so that its longest side is `$longest`; the same picture when it is smaller. */
    private function fit(GdImage $image, int $longest): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $longest) {
            return $image;
        }

        $scale = $longest / max($width, $height);

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);

        if (! $scaled instanceof GdImage) {
            throw new RuntimeException('The picture could not be resized.');
        }

        return $scaled;
    }

    /** Turns the picture the way its EXIF orientation says it is meant to be seen. */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => $this->turned($image, 180),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->turned($this->flipped($image, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->turned($image, -90),
            7 => $this->turned($this->flipped($image, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->turned($image, 90),
            default => $image,
        };
    }

    private function flipped(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /** `$degrees` counter-clockwise. */
    private function turned(GdImage $image, int $degrees): GdImage
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $turned = imagerotate($image, $degrees, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        if (! $turned instanceof GdImage) {
            throw new RuntimeException('The picture could not be turned.');
        }

        imagesavealpha($turned, true);

        return $turned;
    }

    private function encode(GdImage $image): string
    {
        imagesavealpha($image, true);

        ob_start();
        $done = imagewebp($image, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        if (! $done || $bytes === '') {
            throw new RuntimeException('The picture could not be encoded.');
        }

        return $bytes;
    }
}
