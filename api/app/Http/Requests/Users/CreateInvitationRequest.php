<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Rules\Choice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** docs/api/users/POST-invitations.md */
class CreateInvitationRequest extends FormRequest
{
    use ReportsRuleCodes;

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
        return [
            // `max` before `email`: an address that is far too long is told "too long".
            'email' => ['bail', 'required', 'string', 'max:255', 'email', Rule::unique('users', 'email')],
            'role' => ['bail', 'required', new Choice(['owner', 'manager'])],
        ];
    }
}
