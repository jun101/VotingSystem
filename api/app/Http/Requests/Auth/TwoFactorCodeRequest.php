<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The code of the authenticator application that confirms a setup. Only its presence is judged
 * here: anything that is not exactly 6 digits (a number, a list, a long text) is answered
 * `code: invalid` by the controller.
 */
class TwoFactorCodeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required'],
        ];
    }
}
