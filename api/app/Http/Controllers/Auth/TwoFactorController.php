<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ClearTwoFactor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordRequest;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's own two-factor authentication (docs/api/auth/GET-auth-two-factor.md and
 * the four POST files beside it). Every change asks for the password again: a wrong one is a
 * 422 `password: incorrect` (the person is signed in, so never a 401). Nothing is logged but
 * the outcome.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

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

        if ($user->hasTwoFactorEnabled()) {
            Log::info('auth.two_factor_setup', ['outcome' => 'already_enabled']);

            throw new ApiException(409, 'two_factor_already_enabled');
        }

        $secret = $this->twoFactor->newSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_last_step = null;
        $user->save();

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

        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException(409, 'two_factor_already_enabled');
        }

        if (! is_string($user->two_factor_secret)) {
            throw new ApiException(409, 'two_factor_not_started');
        }

        $step = $this->twoFactor->matchingStep($user->two_factor_secret, $request->string('code')->toString(), $user->two_factor_last_step);

        if ($step === null || ! $this->twoFactor->claimStep($user, $step)) {
            Log::info('auth.two_factor_confirm', ['outcome' => 'invalid']);

            throw ValidationException::withMessages(['code' => ['invalid']]);
        }

        $codes = $this->twoFactor->newRecoveryCodes();
        $user->two_factor_recovery_codes = null;
        $this->twoFactor->storeRecoveryHashes($user, $codes['hashes']);
        $user->two_factor_confirmed_at = now();
        $user->save();

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

        $codes = $this->twoFactor->newRecoveryCodes();

        $this->twoFactor->storeRecoveryHashes($user, $codes['hashes']);
        $user->save();

        Log::info('auth.two_factor_recovery_codes', ['outcome' => 'renewed']);

        return response()->json(['data' => ['recovery_codes' => $codes['plain']]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    /** The same hash check as sign-in. A wrong password is a validation error, not a 401. */
    private function checkPassword(PasswordRequest $request, User $user): void
    {
        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            Log::info('auth.two_factor_password', ['outcome' => 'incorrect']);

            throw ValidationException::withMessages(['password' => ['incorrect']]);
        }
    }
}
