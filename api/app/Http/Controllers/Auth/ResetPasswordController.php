<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\Response;

class ResetPasswordController extends Controller
{
    /**
     * Set a new password.
     *
     * Sets a new password with the token of the reset link. The user is not signed in, and
     * their other sessions stop working. Public. Limited to 10 requests per hour per IP address.
     *
     * @unauthenticated
     */
    public function __invoke(ResetPasswordRequest $request, ResetPassword $reset): Response
    {
        /** @var array{token: string, password: string} $data */
        $data = $request->validated();

        $reset($data['token'], $data['password']);

        return response()->noContent();
    }
}
