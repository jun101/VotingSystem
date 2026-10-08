<?php

namespace App\Actions\Users;

use App\Exceptions\ApiException;
use App\Models\Invitation;
use App\Models\User;
use App\Rules\SameAsEmail;
use App\Support\LinkTokens;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Spends an invitation token: creates the user it was meant for, verified, with the invited
 * role and the language of the institution (docs/api/auth/POST-auth-accept-invitation.md).
 *
 * The order of the checks is part of the contract: the token (404), its expiry (410), the
 * name and the password (422), the institution's state (403), the address still being free
 * (409). Everything runs in one transaction with the invitation locked, so a token cannot be
 * spent twice. The caller opens the session.
 */
final class AcceptInvitation
{
    public function __invoke(string $token, mixed $name, mixed $password): User
    {
        try {
            $user = DB::transaction(function () use ($token, $name, $password): User {
                $invitation = $this->find($token);

                if ($invitation === null) {
                    Log::info('invitations.accept', ['outcome' => 'invalid']);

                    throw new ApiException(404, 'not_found');
                }

                if ($invitation->isExpired()) {
                    Log::info('invitations.accept', ['outcome' => 'expired']);

                    throw new ApiException(410, 'expired');
                }

                /** @var array{name: string, password: string} $data */
                $data = Validator::make(
                    ['name' => is_string($name) ? trim($name) : $name, 'password' => $password],
                    [
                        'name' => ['bail', 'required', 'string', 'max:150'],
                        'password' => ['bail', 'required', 'string', 'min:12', 'max:128', new SameAsEmail($invitation->email)],
                    ],
                )->validate();

                $institution = $invitation->institution;

                if ($institution === null) {
                    throw new ApiException(404, 'not_found');
                }

                if ($institution->isSuspended()) {
                    Log::info('invitations.accept', ['outcome' => 'suspended']);

                    throw new ApiException(403, 'institution_suspended');
                }

                // The address is unique across every institution, and nobody is signed in.
                if (User::withoutInstitutionScope()->where('email', $invitation->email)->exists()) {
                    Log::info('invitations.accept', ['outcome' => 'email_taken']);

                    throw new ApiException(409, 'email_taken');
                }

                $user = new User([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => Hash::make($data['password']),
                    'language' => $institution->language,
                ]);
                $user->role = $invitation->role;
                $user->institution()->associate($institution);
                // Opening the link proved the person reads the mailbox.
                $user->email_verified_at = now();
                $user->last_login_at = now();
                $user->save();
                $user->setRelation('institution', $institution);

                $invitation->accepted_at = now();
                $invitation->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // The address was taken between the check and the insert.
            throw new ApiException(409, 'email_taken');
        }

        Log::info('invitations.accept', ['outcome' => 'accepted']);

        return $user;
    }

    /** The invitation of this token that has not been accepted, locked until the end of the transaction. */
    private function find(string $token): ?Invitation
    {
        if (! LinkTokens::isWellFormed($token)) {
            return null;
        }

        $hash = LinkTokens::hash($token);

        // The link is opened before anyone is signed in, so the scope has no institution to read:
        // the token is looked up across every institution.
        $invitation = Invitation::withoutInstitutionScope()
            ->where('token_hash', $hash)
            ->whereNull('accepted_at')
            ->lockForUpdate()
            ->first();

        return $invitation !== null && hash_equals($invitation->token_hash, $hash) ? $invitation : null;
    }
}
