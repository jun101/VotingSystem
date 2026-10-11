<?php

namespace App\Support\Voting;

use RuntimeException;

/**
 * The audit record of a vote (docs/design/database.md section 4.2): a fixed 89-byte content
 * sealed to the platform's public key, so the server can write it and cannot read it.
 *
 * Content: version `0x01`, then five UUIDs of 16 raw bytes (the record's own, the election's, the
 * vote's, the voter's, the ballot's), then the time in seconds as an unsigned 64-bit big-endian
 * integer. The sealed box adds 48 bytes: 137 are stored.
 *
 * Stateless: the key is passed in, read by the caller from `config('votes.audit_public_key')`.
 */
final class AuditSeal
{
    public const VERSION = 1;

    public const CONTENT_BYTES = 89;

    public const SEALED_BYTES = 137;

    /**
     * The 32 raw bytes of the public key.
     *
     * @throws AuditKeyUnusable when the key is missing or not 64 hexadecimal characters; the message
     *                          never holds the value
     */
    public static function publicKey(mixed $hex): string
    {
        if (! is_string($hex) || preg_match('/\A[0-9a-fA-F]{64}\z/', $hex) !== 1) {
            throw new AuditKeyUnusable('The audit public key is missing or malformed.');
        }

        $key = hex2bin($hex);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new AuditKeyUnusable('The audit public key is missing or malformed.');
        }

        return $key;
    }

    /** The clear content, before sealing. The five arguments are UUID strings. */
    public static function content(string $record, string $election, string $vote, string $voter, string $ballot, int $at): string
    {
        $content = chr(self::VERSION);

        foreach ([$record, $election, $vote, $voter, $ballot] as $uuid) {
            $bytes = hex2bin(str_replace('-', '', $uuid));

            if ($bytes === false || strlen($bytes) !== 16) {
                throw new RuntimeException('An audit record needs UUIDs.');
            }

            $content .= $bytes;
        }

        return $content.pack('J', $at);
    }

    /** The sealed box of the content, to the 32-byte public key. */
    public static function seal(string $content, string $publicKey): string
    {
        return sodium_crypto_box_seal($content, $publicKey);
    }
}
