<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * The signed-in user.
     *
     * The user and their institution (`null` for a platform admin). The web application reads
     * it on every page of the admin area. A user whose institution was suspended since
     * sign-in is refused, and the session is ended. Signed-in user.
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}
     */
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }
}
