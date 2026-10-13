<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ReportsRuleCodes;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** docs/api/auth/POST-auth-change-password.md */
class ChangePasswordRequest extends FormRequest
{
    use ReportsRuleCodes;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'current_password' => ['bail', 'required', 'string', 'max:1024'],
            'password' => ['bail', 'required', 'string', 'min:12', 'max:128'],
        ];

        $user = $this->user();

        if ($user instanceof User && $user->hasTwoFactorEnabled()) {
            $rules['code'] = ['required_without:recovery_code'];
        }

        return $rules;
    }
}
