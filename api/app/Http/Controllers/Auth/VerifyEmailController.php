<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use Illuminate\Http\Response;

class VerifyEmailController extends Controller
{
    /**
     * Verify an email address.
     *
     * Verifies the address with the token of the link sent by email. The person may open the
     * link in another browser than the one they registered with, so no session is needed and
     * nobody is signed in by it. Public. Limited to 10 requests per minute per IP address.
     *
     * @unauthenticated
     */
    public function __invoke(VerifyEmailRequest $request, VerifyEmail $verify): Response
    {
        /** @var array{token: string} $data */
        $data = $request->validated();

        $verify($data['token']);

        return response()->noContent();
    }
}
