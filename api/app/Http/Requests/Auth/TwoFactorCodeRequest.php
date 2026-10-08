<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** The code of the authenticator application that confirms a setup. */
class TwoFactorCodeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['bail', 'required', 'string', 'max:32'],
        ];
    }
}
