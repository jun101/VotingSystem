<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two-factor authentication in the tests (slice 04b): turning it on through the real endpoints
 * (so the recovery codes are the real ones), and reading what the server stored.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class TwoFactor
{
    /**
     * Turns two-factor on for this account through the API, with a browser of its own, and
     * returns the secret and the recovery codes. Afterwards the stored "last used period" is
     * cleared, so a test can sign in with the code of the current period.
     *
     * @param  array{user: string, email: string, password: string}  $account
     * @return array{secret: string, codes: list<string>}
     */
    public static function enable(TestCase $test, array $account): array
    {
        $browser = new AuthClient($test);
        $browser->login($account['email'], $account['password'])->assertOk();

        $secret = $browser->post('/api/v1/auth/two-factor/setup', ['password' => $account['password']])->assertOk()->json('data.secret');
        $codes = $browser->post('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');
        $browser->post('/api/v1/auth/logout')->assertNoContent();

        self::clearLastStep($account['user']);

        return ['secret' => $secret, 'codes' => $codes];
    }

    /** Lets the next code of the current period be accepted again. */
    public static function clearLastStep(string $userUuid): void
    {
        DB::connection(useMigratorConnection())->table('users')->where('uuid', $userUuid)->update(['two_factor_last_step' => null]);
    }

    /** The first step of signing in: password right, two-factor on. Returns the browser, with its pending sign-in. */
    public static function pending(TestCase $test, array $account, ?AuthClient $browser = null): AuthClient
    {
        $browser ??= new AuthClient($test);
        $browser->login($account['email'], $account['password'])->assertOk()->assertJsonPath('data.two_factor_required', true);

        return $browser;
    }

    /** The decrypted secret stored for this user, or null. */
    public static function storedSecret(string $userUuid): ?string
    {
        $value = DB::connection(useMigratorConnection())->table('users')->where('uuid', $userUuid)->value('two_factor_secret');

        return $value === null ? null : Crypt::decryptString($value);
    }

    /**
     * The stored recovery codes, decrypted: what the database holds after the application key is
     * applied (a list of hashes, never the codes). Empty when there are none.
     *
     * @return list<string>
     */
    public static function storedRecoveryHashes(string $userUuid): array
    {
        $value = DB::connection(useMigratorConnection())->table('users')->where('uuid', $userUuid)->value('two_factor_recovery_codes');

        return $value === null ? [] : array_values((array) json_decode(Crypt::decryptString($value), true));
    }

    /** The row's two-factor columns, raw. */
    public static function row(string $userUuid): array
    {
        return (array) DB::connection(useMigratorConnection())->table('users')->where('uuid', $userUuid)
            ->first(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step', 'last_login_at']);
    }

    /** A test may plant a started-but-unconfirmed setup. */
    public static function plantUnconfirmed(string $userUuid, string $secret): void
    {
        DB::connection(useMigratorConnection())->table('users')->where('uuid', $userUuid)->update([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
