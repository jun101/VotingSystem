<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendVerificationEmail;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ResendVerificationController extends Controller
{
    /**
     * Send the verification email again.
     *
     * Sends a new verification email to the signed-in user; the previous link stops working.
     * Signed-in user. Limited to 3 requests per minute per user.
     */
    public function __invoke(Request $request, SendVerificationEmail $send): Response
    {
        /** @var User $user */
        $user = $request->user();

        $send($user);

        return response()->noContent();
    }
}
