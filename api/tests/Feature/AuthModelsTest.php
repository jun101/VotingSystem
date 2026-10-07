<?php

use App\Enums\InstitutionType;
use App\Enums\Role;
use App\Http\Resources\UserResource;
use App\Models\Institution;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Accounts;

beforeEach(fn () => Accounts::reset());

it('hashes with Argon2id and reads the cost parameters from the configuration [NFR-SEC-02]', function () {
    $hash = Hash::make('a long enough password');

    expect(config('hashing.driver'))->toBe('argon2id')
        ->and($hash)->toStartWith('$argon2id$')
        ->and(password_get_info($hash)['options'])->toMatchArray([
            'memory_cost' => config('hashing.argon.memory'),
            'time_cost' => config('hashing.argon.time'),
            'threads' => config('hashing.argon.threads'),
        ])
        ->and(Hash::needsRehash($hash))->toBeFalse();

    config(['hashing.argon.time' => config('hashing.argon.time') + 1]);
    Hash::forgetDrivers();

    expect(Hash::needsRehash($hash))->toBeTrue();
});

it('checks a hash made by another algorithm instead of refusing it, and asks for a rehash [NFR-SEC-02]', function () {
    $bcrypt = password_hash('a long enough password', PASSWORD_BCRYPT);

    expect(Hash::check('a long enough password', $bcrypt))->toBeTrue()
        ->and(Hash::check('another password, long enough', $bcrypt))->toBeFalse()
        ->and(Hash::needsRehash($bcrypt))->toBeTrue();
});

it('builds a verified owner of a new institution with the factories, and an unverified one on request [FR-INST-01]', function () {
    $owner = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $admin = User::factory()->platformAdmin()->create();

    expect($owner->role)->toBe(Role::Owner)
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->institution)->toBeInstanceOf(Institution::class)
        ->and($owner->institution->type)->toBe(InstitutionType::Other)
        ->and($owner->uuid)->toMatch(UUID_V4)
        ->and($unverified->hasVerifiedEmail())->toBeFalse()
        ->and($admin->role)->toBe(Role::PlatformAdmin)
        ->and($admin->institution_id)->toBeNull()
        ->and(Hash::check(UserFactory::PASSWORD, $owner->password))->toBeTrue();
});

it('hides the password and the two-factor columns from any array or JSON form [NFR-SEC-08]', function () {
    $user = User::factory()->create(['two_factor_secret' => 'secret-value']);

    $array = $user->toArray();

    expect(array_keys($array))->not->toContain('password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'id')
        ->and($user->toJson())->not->toContain('secret-value')->not->toContain('argon2id');
});

it('keeps no remember token: there is no remember-me [FR-INST-04]', function () {
    $user = User::factory()->create();

    expect($user->getRememberTokenName())->toBe('')
        ->and(Schema::hasColumn('users', 'remember_token'))->toBeFalse();
});

it('outputs the current user with a uuid as id and no database key [NFR-SEC-08]', function () {
    $user = User::factory()->create();
    $resource = (new UserResource($user))->toResponse(Request::create('/'))->getData(true);

    expect(array_keys($resource['data']))->toEqualCanonicalizing(['id', 'name', 'email', 'role', 'email_verified', 'language', 'institution'])
        ->and($resource['data']['id'])->toBe($user->uuid)
        ->and(array_keys($resource['data']['institution']))->toEqualCanonicalizing(['id', 'name', 'type'])
        ->and($resource['data']['institution']['id'])->toBe($user->institution->uuid);

    $admin = User::factory()->platformAdmin()->create();
    expect((new UserResource($admin))->toResponse(Request::create('/'))->getData(true)['data']['institution'])->toBeNull();
});

it('removes a user with deleted_at and then treats them as nobody [FR-INST-06]', function () {
    $user = User::factory()->create();
    $user->delete();

    expect(User::query()->where('email', $user->email)->exists())->toBeFalse()
        ->and(User::withTrashed()->where('email', $user->email)->exists())->toBeTrue();
});

it('refuses a user with an institution and the role platform_admin, and the reverse [FR-INST-01]', function () {
    $institution = Institution::factory()->create();

    expect(fn () => User::factory()->platformAdmin()->create(['institution_id' => $institution->getKey()]))->toThrow(QueryException::class)
        ->and(fn () => User::factory()->create(['institution_id' => null]))->toThrow(QueryException::class);
});

it('keeps the email unique, and the token tables to one row per user and one hash [FR-INST-01]', function () {
    User::factory()->create(['email' => 'same@example.test']);

    expect(fn () => User::factory()->create(['email' => 'same@example.test']))->toThrow(QueryException::class);

    $user = User::factory()->create();
    $other = User::factory()->create();
    $db = DB::connection(useMigratorConnection());
    $row = ['token_hash' => hash('sha256', 'x', true), 'expires_at' => now(), 'created_at' => now()];
    $db->table('password_reset_tokens')->insert($row + ['user_id' => $user->getKey()]);

    expect(fn () => $db->table('password_reset_tokens')->insert($row + ['user_id' => $other->getKey()]))->toThrow(QueryException::class)
        ->and(fn () => $db->table('password_reset_tokens')->insert(['token_hash' => hash('sha256', 'y', true)] + $row + ['user_id' => $user->getKey()]))->toThrow(QueryException::class);
});

it('gives the app account insert, select and update on institutions and users, and no delete [NFR-SEC-06]', function () {
    $user = User::factory()->create();
    $code = function (string $sql): ?int {
        try {
            DB::statement($sql);
        } catch (QueryException $e) {
            return (int) $e->errorInfo[1];
        }

        return null;
    };

    expect($code("UPDATE users SET name = 'Changed' WHERE id = {$user->getKey()}"))->toBeNull()
        ->and($code('DELETE FROM users'))->toBe(1142)
        ->and($code('DELETE FROM institutions'))->toBe(1142)
        ->and($code('DELETE FROM password_reset_tokens'))->toBeNull()
        ->and($code('DELETE FROM email_verification_tokens'))->toBeNull();
});
