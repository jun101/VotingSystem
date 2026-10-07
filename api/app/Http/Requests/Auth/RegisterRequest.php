<?php

namespace App\Http\Requests\Auth;

use App\Rules\SameAsEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /** @return array<string, list<ValidationRule|string|\Stringable>> */
    public function rules(): array
    {
        $email = $this->input('email');

        return [
            'institution_name' => ['bail', 'required', 'string', 'max:150'],
            'name' => ['bail', 'required', 'string', 'max:150'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['bail', 'required', 'string', 'min:12', 'max:128', new SameAsEmail(is_string($email) ? $email : null)],
            'language' => ['nullable', 'string', 'in:fr,en'],
        ];
    }
}
