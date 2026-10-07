<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string'],
            'password' => ['bail', 'required', 'string'],
        ];
    }
}
