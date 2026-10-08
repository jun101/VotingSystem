<?php

namespace Tests\Support;

/**
 * An authenticator application in a few lines (RFC 6238: SHA-1, 6 digits, 30 seconds), written
 * without the library the API uses, so the tests do not trust the code they check.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** The 30-second period that contains this time (default: now). */
    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), 30);
    }

    /** The 6-digit code for a base32 secret and a period (`step() + 1` is the next period). */
    public static function code(string $secret, ?int $step = null): string
    {
        $step ??= self::step();
        $hash = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** A 6-digit string that is not the code of any of the periods around now. */
    public static function wrongCode(string $secret): string
    {
        $valid = array_map(fn (int $offset) => self::code($secret, self::step() + $offset), range(-3, 3));
        foreach (['000000', '123456', '654321', '111111'] as $candidate) {
            if (! in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }

        return '999999';
    }

    public static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(str_replace([' ', '='], '', $secret))) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
