<?php

namespace App\Actions\Auth;

use App\Enums\InstitutionType;
use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Support\LinkTokens;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates an institution and its first user, the owner, in one transaction, and queues the
 * verification email (docs/api/auth/POST-auth-register.md).
 */
final class RegisterInstitution
{
    public const VERIFICATION_MINUTES = 24 * 60;

    public function __construct(private readonly LinkTokens $tokens) {}

    public function __invoke(string $institutionName, string $name, string $email, string $password, string $language): User
    {
        try {
            [$user, $token] = DB::transaction(function () use ($institutionName, $name, $email, $password, $language): array {
                $institution = Institution::create([
                    'name' => $institutionName,
                    'type' => InstitutionType::Other,
                    'timezone' => 'America/Port-au-Prince',
                    'language' => $language,
                ]);

                $user = new User([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'language' => $language,
                ]);
                $user->role = Role::Owner;
                $user->institution()->associate($institution);
                $user->save();
                $user->setRelation('institution', $institution);

                return [$user, $this->tokens->issue(LinkTokens::VERIFICATION, $user, self::VERIFICATION_MINUTES)];
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups with the same address at the same moment: the second one loses.
            throw ValidationException::withMessages(['email' => ['taken']]);
        }

        $user->notify(new VerifyEmailNotification($token));

        Log::info('auth.register', ['outcome' => 'created']);

        return $user;
    }
}
