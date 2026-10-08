<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteTwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TwoFactorChallengeController extends Controller
{
    /**
     * Finish signing in with a code.
     *
     * The second step of signing in for a person who has two-factor authentication turned on:
     * one `code` from the authenticator application (6 digits; the previous and the next
     * 30-second period are accepted, a period already used is not) or one `recovery_code`
     * (checked first when both are sent; used up by a success). It only ever finishes the
     * sign-in that the password step recorded in this browser's session, for five minutes;
     * five wrong codes end it. Five wrong codes in 15 minutes for one account, from any browser or
     * address, answer 429 even for a right code. The session id is regenerated. Public. Limited to 10 requests
     * per minute per IP address.
     *
     * @unauthenticated
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}
     */
    public function __invoke(Request $request, CompleteTwoFactorChallenge $complete): UserResource
    {
        $user = $complete($request->session(), $request->input('code'), $request->input('recovery_code'), (string) $request->ip());

        Auth::guard()->login($user);
        $request->session()->regenerateToken();

        return new UserResource($user);
    }
}
