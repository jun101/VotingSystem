<?php

namespace App\Http\Requests\Users;

/** docs/api/users/POST-users-{user}-password-reset.md: the same two factors as the two-factor reset. */
class ResetUserPasswordRequest extends ResetTwoFactorRequest {}
