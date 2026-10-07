<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    /**
     * The rule "not equal to the user's email" needs the user, who is found by the token: it
     * is checked by the action, after these rules and before the token is spent.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'token' => ['bail', 'required', 'string'],
            'password' => ['bail', 'required', 'string', 'min:12', 'max:128'],
        ];
    }
}
