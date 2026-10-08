<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\LinkTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * Invites an address to the inviter's institution and queues the email
 * (docs/api/users/POST-invitations.md).
 *
 * One invitation that has not been accepted per address and institution: a new one replaces the
 * old, whose link then stops working. Only the SHA-256 hash of the token is stored; the token is
 * in the email and nowhere else (not in a log, not in an answer).
 */
final class CreateInvitation
{
    public const VALID_DAYS = 7;

    public function __invoke(User $inviter, string $email, Role $role): Invitation
    {
        $inviterKey = $inviter->getKey();

        if (! is_int($inviterKey)) {
            throw new LogicException('The inviter has no key.');
        }

        $token = LinkTokens::newToken();
        $institution = $inviter->institution;
        $now = Carbon::now('UTC');

        $invitation = DB::transaction(function () use ($inviterKey, $email, $role, $token, $now): Invitation {
            // The tenant scope limits this to the inviter's institution.
            Invitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

            $invitation = new Invitation;
            $invitation->email = $email;
            $invitation->role = $role;
            $invitation->token_hash = LinkTokens::hash($token);
            $invitation->invited_by_user_id = $inviterKey;
            $invitation->expires_at = $now->copy()->addDays(self::VALID_DAYS);
            $invitation->save();

            return $invitation;
        });

        if ($institution !== null) {
            Notification::route('mail', $email)->notify(
                (new InvitationNotification($token, $institution->name, $role))->locale($institution->language),
            );
        }

        Log::info('invitations.create', ['outcome' => 'created']);

        $invitation->setRelation('inviter', $inviter);

        return $invitation;
    }
}
