<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ClearTwoFactor;
use App\Actions\Auth\ConfirmOwnPassword;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordRequest;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own two-factor authentication (docs/api/auth/GET-auth-two-factor.md and
 * the four POST files beside it). Every change asks for the password again: a wrong one is a
 * 422 `password: incorrect`; the fifth wrong one in 15 minutes ends the session (401), see
 * ConfirmOwnPassword. Nothing is logged but the outcome.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly ConfirmOwnPassword $confirmPassword) {}

    /**
     * The state of the signed-in user's two-factor authentication.
     *
     * `enabled`: confirmed and asked at sign-in. `setup_started`: a secret was issued and not
     * confirmed. `recovery_codes_left`: how many unused recovery codes remain, `null` when not
     * enabled. Neither the secret nor a code is returned. Signed-in user.
     *
     * @response array{data: array{enabled: bool, setup_started: bool, recovery_codes_left: int|null}}
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactorEnabled(),
            'setup_started' => ! $user->hasTwoFactorEnabled() && $user->two_factor_secret !== null,
            'recovery_codes_left' => $user->hasTwoFactorEnabled() ? count($this->twoFactor->recoveryHashes($user)) : null,
        ]]);
    }

    /**
     * Start turning two-factor on.
     *
     * Issues a new secret (a setup not yet confirmed is replaced) after the password is checked.
     * Not active until confirmed. The answer holds the secret and the `otpauth` link; the web
     * page draws the QR code in the browser. Signed-in user. Limited to 10 requests per minute
     * per user, shared with confirm, disable and recovery codes.
     *
     * @response array{data: array{secret: string, otpauth_url: string}}
     */
    public function setup(PasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->checkPassword($request, $user);

        // Checked here too, so the generated contract keeps the 409; read again under the lock.
        if ($user->hasTwoFactorEnabled()) {
            Log::info('auth.two_factor_setup', ['outcome' => 'already_enabled']);

            throw new ApiException(409, 'two_factor_already_enabled');
        }

        // Under the row lock, like confirm: a setup cannot replace the secret of a confirmation
        // that is running.
        $secret = DB::transaction(function () use ($user): string {
            $locked = $user->newModelQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                throw new ApiException(401, 'unauthenticated');
            }

            if ($locked->hasTwoFactorEnabled()) {
                Log::info('auth.two_factor_setup', ['outcome' => 'already_enabled']);

                throw new ApiException(409, 'two_factor_already_enabled');
            }

            $secret = $this->twoFactor->newSecret();
            $locked->two_factor_secret = $secret;
            $locked->two_factor_recovery_codes = null;
            $locked->two_factor_last_step = null;
            $locked->save();

            return $secret;
        });

        Log::info('auth.two_factor_setup', ['outcome' => 'started']);

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_url' => $this->twoFactor->otpauthUrl($secret, $user->email),
        ]]);
    }

    /**
     * Turn two-factor on.
     *
     * Confirms the setup with a first code from the authenticator application (6 digits; the
     * previous and the next period are accepted; a period already used is not) and returns
     * the eight recovery codes, shown only here and in the renewal. Open sessions stay open. A
     * wrong code does not end the setup. Signed-in user. Limited to 10 requests per minute per
     * user, shared with setup, disable and recovery codes.
     *
     * @response array{data: array{recovery_codes: list<string>}}
     */
    public function confirm(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $given = $request->input('code');

        // Cheap checks first, so the generated contract keeps both 409s; they are made again
        // under the lock below.
        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException(409, 'two_factor_already_enabled');
        }

        if (! is_string($user->two_factor_secret)) {
            throw new ApiException(409, 'two_factor_not_started');
        }

        // The row is locked and its state read again inside the transaction: two confirmations
        // at once cannot both turn it on and return two different sets of recovery codes.
        $codes = DB::transaction(function () use ($user, $given): array {
            $locked = $user->newModelQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                throw new ApiException(401, 'unauthenticated');
            }

            if ($locked->hasTwoFactorEnabled()) {
                throw new ApiException(409, 'two_factor_already_enabled');
            }

            if (! is_string($locked->two_factor_secret)) {
                throw new ApiException(409, 'two_factor_not_started');
            }

            $step = is_string($given) && strlen($given) <= 32
                ? $this->twoFactor->matchingStep($locked->two_factor_secret, $given, $locked->two_factor_last_step)
                : null;

            if ($step === null || ! $this->twoFactor->claimStep($locked, $step)) {
                Log::info('auth.two_factor_confirm', ['outcome' => 'invalid']);

                throw ValidationException::withMessages(['code' => ['invalid']]);
            }

            $codes = $this->twoFactor->newRecoveryCodes();
            $locked->two_factor_recovery_codes = null;
            $this->twoFactor->storeRecoveryHashes($locked, $codes['hashes']);
            $locked->two_factor_confirmed_at = now();
            $locked->save();

            return $codes;
        });

        Log::info('auth.two_factor_confirm', ['outcome' => 'enabled']);

        return response()->json(['data' => ['recovery_codes' => $codes['plain']]]);
    }

    /**
     * Turn two-factor off.
     *
     * Clears the secret, the recovery codes, the confirmation time and the stored period after
     * the password is checked. A setup that was never confirmed counts as not turned on.
     * Signed-in user. Limited to 10 requests per minute per user, shared with setup, confirm and
     * recovery codes.
     */
    public function disable(PasswordRequest $request, ClearTwoFactor $clear): Response
    {
        $user = $this->user($request);
        $this->checkPassword($request, $user);

        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException(409, 'two_factor_not_enabled');
        }

        $clear($user);

        Log::info('auth.two_factor_disable', ['outcome' => 'disabled']);

        return response()->noContent();
    }

    /**
     * Renew the recovery codes.
     *
     * Replaces the recovery codes with eight new ones after the password is checked; the old
     * ones stop working. Shown only here. Signed-in user. Limited to 10 requests per minute per
     * user, shared with setup, confirm and disable.
     *
     * @response array{data: array{recovery_codes: list<string>}}
     */
    public function recoveryCodes(PasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->checkPassword($request, $user);

        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException(409, 'two_factor_not_enabled');
        }

        // Under the same row lock as the sign-in with a recovery code: a renewal and a
        // recovery sign-in cannot both write the list.
        $codes = DB::transaction(function () use ($user): array {
            $locked = $user->newModelQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                throw new ApiException(401, 'unauthenticated');
            }

            if (! $locked->hasTwoFactorEnabled()) {
                throw new ApiException(409, 'two_factor_not_enabled');
            }

            $codes = $this->twoFactor->newRecoveryCodes();
            $this->twoFactor->storeRecoveryHashes($locked, $codes['hashes']);
            $locked->save();

            return $codes;
        });

        Log::info('auth.two_factor_recovery_codes', ['outcome' => 'renewed']);

        return response()->json(['data' => ['recovery_codes' => $codes['plain']]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    private function checkPassword(PasswordRequest $request, User $user): void
    {
        ($this->confirmPassword)($request, $user, $request->string('password')->toString());
    }
}
