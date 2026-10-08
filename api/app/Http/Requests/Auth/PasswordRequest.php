<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** The password of the signed-in user, asked again to change the second factor. */
class PasswordRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['bail', 'required', 'string', 'max:1024'],
        ];
    }
}
