<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Images built by the tests, so no binary file lives in the repository (slice 04: the
 * logo). Needs PHP GD with WebP, which the API image gets in this slice.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Images
{
    /** A string that appears in the metadata of `jpegWithMetadata()` and must never survive. */
    public const SECRET = 'SECRET-GPS-MARKER-48.8566N';

    /** @return string PNG bytes of a plain picture, `$width` by `$height` */
    public static function png(int $width = 40, int $height = 20): string
    {
        return self::encode(self::picture($width, $height), 'png');
    }

    public static function jpeg(int $width = 40, int $height = 20): string
    {
        return self::encode(self::picture($width, $height), 'jpeg');
    }

    public static function webp(int $width = 40, int $height = 20): string
    {
        return self::encode(self::picture($width, $height), 'webp');
    }

    public static function gif(int $width = 40, int $height = 20): string
    {
        return self::encode(self::picture($width, $height), 'gif');
    }

    /**
     * A JPEG `$width` x `$height` with an EXIF orientation of 6 (the camera was turned: a
     * viewer shows it rotated a quarter turn) and a comment holding SECRET.
     */
    public static function jpegWithMetadata(int $width = 400, int $height = 200): string
    {
        $jpeg = self::jpeg($width, $height);

        // EXIF: "II" little endian, one IFD entry, tag 0x0112 (Orientation), SHORT, 1 value, 6.
        $tiff = "II\x2a\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"."\x00\x00\x00\x00";
        $exif = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $comment = "\xFF\xFE".pack('n', strlen(self::SECRET) + 2).self::SECRET;

        return substr($jpeg, 0, 2).$app1.$comment.substr($jpeg, 2);
    }

    /**
     * An animated WebP: the extended header with the animation flag, the animation chunk and two
     * frames (each the picture of a plain WebP).
     */
    public static function animatedWebp(int $width = 40, int $height = 20): string
    {
        $plain = self::webp($width, $height);
        $frame = substr($plain, 12);   // the chunk(s) after "RIFF....WEBP"
        $le24 = fn (int $n) => substr(pack('V', $n), 0, 3);
        $chunk = fn (string $type, string $data) => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\x00" : '');

        $anmf = $le24(0).$le24(0).$le24($width - 1).$le24($height - 1).$le24(100)."\x00".$frame;
        $body = 'WEBP'
            .$chunk('VP8X', "\x02\x00\x00\x00".$le24($width - 1).$le24($height - 1))
            .$chunk('ANIM', "\x00\x00\x00\x00".pack('v', 0))
            .$chunk('ANMF', $anmf)
            .$chunk('ANMF', $anmf);

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    /** An animated PNG: a valid PNG with an `acTL` chunk before the image data. */
    public static function animatedPng(int $width = 40, int $height = 20): string
    {
        $png = self::png($width, $height);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $position = 8 + 25;   // signature, then the 25 bytes of the IHDR chunk

        return substr($png, 0, $position).$chunk('acTL', pack('NN', 2, 0)).substr($png, $position);
    }

    /** A PDF: not an image. */
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** PHP code, to be sent under the name of a picture. */
    public static function script(): string
    {
        return "<?php echo 'not a picture'; ?>\n";
    }

    /** A PNG that stops after its first bytes: the signature is right, the picture is not there. */
    public static function truncatedPng(): string
    {
        return substr(self::png(200, 200), 0, 48);
    }

    /**
     * A PNG whose header claims `$width` x `$height` and that holds no picture: enough for a
     * size check done from the header, before any decoding.
     */
    public static function pngClaiming(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IEND', '');
    }

    /** A valid PNG of exactly `$bytes` bytes (padding after the end marker). */
    public static function pngOfSize(int $bytes): string
    {
        $png = self::png(40, 20);
        expect(strlen($png))->toBeLessThan($bytes);

        return $png.str_repeat("\x00", $bytes - strlen($png));
    }

    /** The pixel size `[width, height]` of image bytes, or null. */
    public static function size(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);

        return $info === false ? null : [$info[0], $info[1]];
    }

    public static function mime(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        return $info === false ? null : $info['mime'];
    }

    /** The bytes as a file part of a request, under this client-side name. */
    public static function upload(string $bytes, string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private static function picture(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 60, 140));
        imagefilledrectangle($image, 2, 2, (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 250, 200, 40));

        return $image;
    }

    private static function encode(\GdImage $image, string $format): string
    {
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image, null, 90),
            'webp' => imagewebp($image, null, 90),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }
}
