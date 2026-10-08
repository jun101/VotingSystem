<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * docs/api/auth/POST-auth-accept-invitation.md. Only the token is judged here: the rest of
 * the body is judged after the token has been found (an unknown token answers 404 before any
 * 422, so a bad body never reveals whether a token is valid).
 */
class AcceptInvitationRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'token' => ['bail', 'required', 'string', 'max:1024'],
        ];
    }
}
