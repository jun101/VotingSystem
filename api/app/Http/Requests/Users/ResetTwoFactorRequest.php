<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Auth\PasswordRequest;
use App\Models\User;

/**
 * The owner's password, and the owner's own second factor when the owner has two-factor on and
 * the user named is somebody else (a reset of oneself is refused with 409 after the password).
 */
class ResetTwoFactorRequest extends PasswordRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $owner = $this->user();

        $target = $this->route('user');

        if ($owner instanceof User && $owner->hasTwoFactorEnabled() && ! $owner->is($target)) {
            return parent::rules() + ['code' => ['required_without:recovery_code']];
        }

        return parent::rules();
    }
}
