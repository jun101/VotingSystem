<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptLogin;
use App\Auth\PendingSignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Sign in.
     *
     * Signs a user in with email and password. The session id is regenerated. A platform
     * admin signs in here too. A user with two-factor authentication is not signed in yet: the
     * answer is `{"data":{"two_factor_required":true}}` and the sign-in is finished by
     * `POST /auth/two-factor-challenge`. Public. Limited to 10 requests per minute per IP address, and
     * 5 failed attempts per minute for one email address from one IP address.
     *
     * @unauthenticated
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}|array{two_factor_required: true}}
     */
    public function __invoke(LoginRequest $request, AttemptLogin $attempt): UserResource|JsonResponse
    {
        /** @var array{email: string, password: string} $data */
        $data = $request->validated();

        $user = $attempt($data['email'], $data['password'], (string) $request->ip());

        if ($user->hasTwoFactorEnabled()) {
            // Nobody stays signed in in this browser while another sign-in waits for its code:
            // the guard is logged out and the session id is renewed with the old session
            // destroyed, so nothing of the previous one is carried over.
            Auth::guard()->logout();
            $request->session()->regenerate(true);
            PendingSignIn::start($request->session(), $user, AttemptLogin::failureKeyForUser($user, (string) $request->ip()));
            $request->session()->regenerateToken();

            return response()->json(['data' => ['two_factor_required' => true]]);
        }

        PendingSignIn::end($request->session());
        Auth::guard()->login($user);
        $request->session()->regenerateToken();

        return new UserResource($user);
    }
}
