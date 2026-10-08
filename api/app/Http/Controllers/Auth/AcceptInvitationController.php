<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Users\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AcceptInvitationController extends Controller
{
    /**
     * Accept an invitation.
     *
     * The invited person chooses a name and a password and gets an account in the institution
     * that invited them, verified, with the invited role, and is signed in (the session id is
     * regenerated). An unknown, used or cancelled token is 404; an expired one is 410; the order
     * of the checks is in the endpoint file. Public. Limited to 10 requests per hour per IP address.
     *
     * @unauthenticated
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}
     */
    public function __invoke(AcceptInvitationRequest $request, AcceptInvitation $accept): JsonResponse
    {
        // A browser that is already signed in (as anyone) leaves that account first. The
        // session itself stays, so the CSRF token still holds; it is regenerated at sign-in.
        Auth::guard()->logout();

        /** @var array{token: string} $data */
        $data = $request->validated();

        $user = $accept($data['token'], $request->input('name'), $request->input('password'));

        Auth::guard()->login($user);
        $request->session()->regenerateToken();

        // A resource of a record just created answers 201; this answer is a sign-in.
        return (new UserResource($user))->response()->setStatusCode(200);
    }
}
