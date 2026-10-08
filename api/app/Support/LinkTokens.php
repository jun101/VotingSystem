<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The tokens of the links sent by email: email verification and password reset
 * (docs/design/database.md section 2.1).
 *
 * A token is 64 hexadecimal characters from the system's secure random source. Only its
 * SHA-256 hash is stored, so a copy of the database opens no link. A user has one live
 * token per table: a new one replaces the old. The table holds no state of the request;
 * this class holds none either.
 */
final class LinkTokens
{
    public const VERIFICATION = 'email_verification_tokens';

    public const RESET = 'password_reset_tokens';

    /** Creates the user's token in this table, replacing the previous one. Returns the token itself. */
    public function issue(string $table, User $user, int $minutes): string
    {
        $token = self::newToken();
        $now = Carbon::now('UTC');

        DB::table($table)->upsert(
            [[
                'user_id' => $user->getKey(),
                'token_hash' => self::hash($token),
                'expires_at' => $now->copy()->addMinutes($minutes),
                'created_at' => $now,
            ]],
            ['user_id'],
            ['token_hash', 'expires_at', 'created_at'],
        );

        return $token;
    }

    /**
     * The row of this token, or null when it is unknown, used, replaced or not of the right
     * shape. The row is found by the hash of the value received, then the two hashes are
     * compared in constant time. With `$lock`, the row is locked until the end of the
     * transaction, so a token cannot be spent twice.
     */
    public function find(string $table, string $token, bool $lock = false): ?stdClass
    {
        if (! self::isWellFormed($token)) {
            return null;
        }

        $hash = self::hash($token);
        $query = DB::table($table)->where('token_hash', $hash);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! $row instanceof stdClass || ! is_string($row->token_hash) || ! hash_equals($row->token_hash, $hash)) {
            return null;
        }

        return $row;
    }

    public function isExpired(stdClass $row): bool
    {
        $expiresAt = $row->expires_at;

        return ! is_string($expiresAt) || Carbon::parse($expiresAt, 'UTC')->isPast();
    }

    public function forget(string $table, stdClass $row): void
    {
        DB::table($table)->where('user_id', $row->user_id)->delete();
    }

    /** A new token: 64 hexadecimal characters from the system's secure random source. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** True when the value has the shape of a token (anything else cannot be one). */
    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    /** SHA-256, raw (32 bytes), as stored. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }
}
