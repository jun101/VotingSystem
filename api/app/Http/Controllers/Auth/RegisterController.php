<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterInstitution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    /**
     * Register an institution and its owner.
     *
     * Creates the institution and its first user, the owner, in one step, queues the
     * verification email and signs the owner in. Public. Limited to 10 requests per hour
     * per IP address.
     */
    #[Response(status: 201, type: "array{data: array{id: string, name: string, email: string, role: 'owner'|'manager'|'platform_admin', email_verified: bool, language: 'fr'|'en', institution: array{id: string, name: string, type: 'school'|'university'|'association'|'other'}|null}}")]
    public function __invoke(RegisterRequest $request, RegisterInstitution $register): JsonResponse
    {
        /** @var array{institution_name: string, name: string, email: string, password: string, language?: string|null} $data */
        $data = $request->validated();

        $user = $register(
            $data['institution_name'],
            $data['name'],
            $data['email'],
            $data['password'],
            $data['language'] ?? $request->getPreferredLanguage(['fr', 'en']) ?? 'fr',
        );

        Auth::guard()->login($user);
        $request->session()->regenerateToken();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
