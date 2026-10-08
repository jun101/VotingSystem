<?php

namespace App\Http\Requests\Auth;

/**
 * The password and a current second factor (`code` or `recovery_code`), asked to turn
 * two-factor off and to renew the recovery codes. Only the presence of the second factor is
 * judged here: anything that is not a right value (a short code, a list, a long text) is answered
 * `invalid` by the action, and counted.
 */
class SecondFactorRequest extends PasswordRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'code' => ['required_without:recovery_code'],
        ];
    }
}
