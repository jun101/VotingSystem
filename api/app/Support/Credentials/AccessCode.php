<?php

namespace App\Support\Credentials;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * An access code of a voter: ten characters of an alphabet with no look-alike (no `0 O 1 I L`),
 * drawn from the system's CSPRNG and shown in two groups of five. Only its HMAC-SHA-256 is
 * stored. Stateless: the key is read from the configuration on each call.
 */
final class AccessCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 10;

    /** A new code as it is shown to the voter, `XXXXX-XXXXX`. */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    /** What is compared and hashed: upper case, no hyphen. */
    public static function normalize(string $code): string
    {
        return strtoupper(str_replace('-', '', $code));
    }

    /** The 32 raw bytes stored for a code. */
    public static function hash(string $code): string
    {
        $key = Config::get('credentials.hash_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The credential hash key is not set.');
        }

        return hash_hmac('sha256', self::normalize($code), $key, true);
    }
}
