<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Access codes and the private `credentials` disk, for the slice 10 tests (docs/slices/10-codes-and-slips.md).
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Codes
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** A code as shown: ten characters of the alphabet in two groups of five. */
    public const PATTERN = '/^[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}$/';

    /** The same, to find codes in text. */
    public const FIND = '/[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}/';

    /** A random valid code (for planting). */
    public static function fresh(): string
    {
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($out, 0, 5).'-'.substr($out, 5);
    }

    /** What the database holds for a code: the HMAC-SHA-256 with the credential key. */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $code)), (string) env('CREDENTIAL_HASH_KEY'), true);
    }

    /** Points the `credentials` disk at an empty temporary folder and returns the folder. */
    public static function useTempDisk(): string
    {
        $folder = sys_get_temp_dir().'/credentials-'.bin2hex(random_bytes(6));
        mkdir($folder, 0700, true);
        config(['filesystems.disks.credentials.root' => $folder]);
        Storage::forgetDisk('credentials');

        return $folder;
    }

    /** Makes every write to the `credentials` disk fail: its root sits under a regular file. */
    public static function breakDisk(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'credentials-broken');
        config(['filesystems.disks.credentials.root' => $file.'/inside']);
        Storage::forgetDisk('credentials');
    }

    /**
     * The names of the files kept on the disk.
     *
     * @return list<string>
     */
    public static function storedFiles(): array
    {
        $root = (string) config('filesystems.disks.credentials.root');

        return array_values(array_map('basename', glob($root.'/*') ?: []));
    }
}
