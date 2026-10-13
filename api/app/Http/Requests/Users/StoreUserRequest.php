<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Models\User;
use App\Rules\Choice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** docs/api/users/POST-users.md (school mode). The owner's password, and their second factor when they have one. */
class StoreUserRequest extends FormRequest
{
    use ReportsRuleCodes;

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }

        if (is_string($this->input('name'))) {
            $merge['name'] = trim($this->input('name'));
        }

        $this->merge($merge);
    }

    /** @return array<string, list<ValidationRule|string|\Stringable>> */
    public function rules(): array
    {
        $rules = [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'email' => ['bail', 'required', 'string', 'max:255', 'email', Rule::unique('users', 'email')],
            'role' => ['bail', 'required', new Choice(['owner', 'manager'])],
            'password' => ['bail', 'required', 'string', 'max:1024'],
        ];

        $owner = $this->user();

        if ($owner instanceof User && $owner->hasTwoFactorEnabled()) {
            $rules['code'] = ['required_without:recovery_code'];
        }

        return $rules;
    }
}
