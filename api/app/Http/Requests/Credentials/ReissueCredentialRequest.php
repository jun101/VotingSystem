<?php

namespace App\Http\Requests\Credentials;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/** docs/api/credentials/POST-voters-{voter}-credential.md: no body, or `{ "email": true }` (slice 11). */
class ReissueCredentialRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['bail', 'nullable', 'boolean']];
    }

    /** A value that is not a boolean is `email: invalid`, whatever the rule that refused it. */
    protected function failedValidation(Validator $validator): never
    {
        throw ValidationException::withMessages(['email' => ['invalid']]);
    }

    /** Whether the new code is also emailed. */
    public function wantsEmail(): bool
    {
        return $this->boolean('email');
    }
}
