<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Sign in.
     *
     * Signs a user in with email and password. The session id is regenerated. A platform
     * admin signs in here too. Public. Limited to 10 requests per minute per IP address, and
     * 5 failed attempts per minute for one email address from one IP address.
     *
     * @unauthenticated
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}
     */
    public function __invoke(LoginRequest $request, AttemptLogin $attempt): UserResource
    {
        /** @var array{email: string, password: string} $data */
        $data = $request->validated();

        $user = $attempt($data['email'], $data['password'], (string) $request->ip());

        Auth::guard()->login($user);
        $request->session()->regenerateToken();

        return new UserResource($user);
    }
}
