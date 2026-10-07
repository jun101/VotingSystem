<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RequestPasswordReset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\Response;

class ForgotPasswordController extends Controller
{
    /**
     * Ask for a password reset link.
     *
     * Sends a reset link to the address, if it belongs to an account. The answer is the same
     * either way, so the endpoint does not tell whether an account exists. Public. Limited
     * to 5 requests per hour per IP address, and 3 per hour per email address.
     *
     * @unauthenticated
     */
    public function __invoke(ForgotPasswordRequest $request, RequestPasswordReset $reset): Response
    {
        /** @var array{email: string} $data */
        $data = $request->validated();

        $reset($data['email']);

        return response()->noContent();
    }
}
