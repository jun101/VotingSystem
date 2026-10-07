<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'email', 'max:255'],
        ];
    }
}
