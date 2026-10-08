<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateMeRequest;
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

    /**
     * Change the signed-in user's settings.
     *
     * In this slice, the language of the admin area (`fr` or `en`). It also becomes the language of
     * the emails sent to this user from then on. Only the signed-in user's own row changes; any
     * other field of the body is ignored. Signed-in user.
     *
     * @response array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}
     */
    public function update(UpdateMeRequest $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->language = $request->string('language')->toString();
        $user->save();

        return new UserResource($user);
    }
}
