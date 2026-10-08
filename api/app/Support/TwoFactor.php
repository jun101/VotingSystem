<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use OTPHP\InternalClock;
use OTPHP\TOTP;

/**
 * Time-based one-time codes (RFC 6238: SHA-1, 6 digits, 30 seconds) and the recovery codes
 * (docs/slices/04b-two-factor.md). It holds no state: everything it needs is passed in.
 *
 * - A code is accepted for the period that contains now and the one before and after; a period
 *   at or below the one stored for the user (`two_factor_last_step`) is refused, and claiming a
 *   period is one atomic statement, so two requests cannot both use the same code.
 * - A recovery code is ten lowercase letters and digits with a dash in the middle. Only an
 *   HMAC-SHA-256 of it, keyed with the application key, is stored; it is compared in constant
 *   time and normalised (lower case, no dash or space) before hashing.
 */
final class TwoFactor
{
    public const ISSUER = 'New Voting System';

    public const PERIOD = 30;

    public const RECOVERY_CODE_COUNT = 8;

    private const RECOVERY_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** A new secret of 160 bits, in base32 (32 characters). */
    public function newSecret(): string
    {
        return TOTP::generate(new InternalClock, 20)->getSecret();
    }

    /** The link an authenticator application reads from a QR code. */
    public function otpauthUrl(string $secret, string $email): string
    {
        $label = rawurlencode(self::ISSUER.':'.$email);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => self::ISSUER,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The period of the code if it is right for the secret now (or one period before or after)
     * and newer than the last period used; null otherwise. All three periods are always
     * compared, in constant time.
     */
    public function matchingStep(string $secret, string $input, ?int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $input) ?? '';

        if ($secret === '' || preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return null;
        }

        $totp = TOTP::createFromSecret($secret, new InternalClock);
        $current = intdiv(now()->getTimestamp(), self::PERIOD);
        $found = null;

        foreach ([-1, 0, 1] as $offset) {
            $step = $current + $offset;

            if ($step >= 0 && hash_equals($totp->at($step * self::PERIOD), $code) && ($lastStep === null || $step > $lastStep)) {
                $found = $step;
            }
        }

        return $found;
    }

    /**
     * Records the period as used, unless another request used it or a later one first.
     * True when this request holds it.
     */
    public function claimStep(User $user, int $step): bool
    {
        // The query is on the user's own row, by key, and not through the institution scope: a
        // platform admin has no institution, and the key was read from the session.
        return $user->newModelQuery()
            ->whereKey($user->getKey())
            ->where(fn ($query) => $query->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]) === 1;
    }

    /**
     * @return array{plain: list<string>, hashes: list<string>}
     */
    public function newRecoveryCodes(): array
    {
        $plain = [];

        while (count($plain) < self::RECOVERY_CODE_COUNT) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::RECOVERY_ALPHABET[random_int(0, 35)];
            }
            $code = substr($code, 0, 5).'-'.substr($code, 5);

            if (! in_array($code, $plain, true)) {
                $plain[] = $code;
            }
        }

        return ['plain' => $plain, 'hashes' => array_map($this->hashRecoveryCode(...), $plain)];
    }

    public function hashRecoveryCode(string $code): string
    {
        $normalized = strtolower(preg_replace('/[\s-]+/', '', $code) ?? '');

        return hash_hmac('sha256', 'two-factor-recovery|'.$normalized, Config::string('app.key'));
    }

    /**
     * The position of the code among the stored hashes, or null. Every hash is compared.
     *
     * @param  list<string>  $hashes
     */
    public function findRecoveryCode(array $hashes, string $input): ?int
    {
        $hash = $this->hashRecoveryCode($input);
        $found = null;

        foreach ($hashes as $position => $stored) {
            if (hash_equals($stored, $hash)) {
                $found = $position;
            }
        }

        return $found;
    }

    /**
     * The stored recovery hashes of a user.
     *
     * @return list<string>
     */
    public function recoveryHashes(User $user): array
    {
        $value = $user->two_factor_recovery_codes;

        if (! is_string($value) || $value === '') {
            return [];
        }

        $list = json_decode($value, true);

        return is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
    }

    /** @param  list<string>  $hashes */
    public function storeRecoveryHashes(User $user, array $hashes): void
    {
        $user->two_factor_recovery_codes = json_encode($hashes, JSON_THROW_ON_ERROR);
    }
}
