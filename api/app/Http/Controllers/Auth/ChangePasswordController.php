<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ChangeOwnPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\Response;

class ChangePasswordController extends Controller
{
    /**
     * Change my password.
     *
     * Asks for the current password again (and the second factor if on), replaces the password, ends the user's other
     * sessions and clears a "must change" flag. Limited to 10 requests per hour per user.
     */
    public function __invoke(ChangePasswordRequest $request, ChangeOwnPassword $change): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $change(
            $request,
            $user,
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
            $request->input('code'),
            $request->input('recovery_code'),
        );

        return response()->noContent();
    }
}
