<?php

use App\Auth\SessionUserProvider;
use App\Models\User;
use App\Policies\TenantPolicy;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Accounts;

beforeEach(function () {
    Accounts::reset();
});

it('finds the user of a session without a signed-in user, through the guard provider [NFR-SEC-03]', function () {
    $made = Accounts::user();
    $provider = Auth::createUserProvider('users');

    expect($provider)->toBeInstanceOf(SessionUserProvider::class);

    $found = $provider->retrieveByCredentials(['email' => $made['email']]);
    expect($found)->toBeInstanceOf(User::class)
        ->and($found->uuid)->toBe($made['user']);
});

it('keeps the user hidden from a query when the scope applies and nobody is signed in [NFR-SEC-03]', function () {
    $made = Accounts::user();
    Auth::forgetGuards();

    expect(User::query()->where('uuid', $made['user'])->exists())->toBeFalse()
        ->and(User::withoutInstitutionScope()->where('uuid', $made['user'])->exists())->toBeTrue();
});

it('answers a policy question with false when the record has no institution [NFR-SEC-03]', function () {
    $made = Accounts::user();
    $policy = new class extends TenantPolicy {};
    // Test setup: nobody is signed in, so the scope is removed on purpose.
    $user = User::withoutInstitutionScope()->where('uuid', $made['user'])->firstOrFail();

    expect($policy->belongsToUsersInstitution($user, new User))->toBeFalse();
});

it('binds a route parameter that is not a uuid to nothing [NFR-SEC-08]', function () {
    $made = Accounts::user();
    // Test setup: reading a user across institutions to hand it to the binding.
    $user = User::withoutInstitutionScope()->where('uuid', $made['user'])->firstOrFail();
    Auth::setUser($user);

    expect($user->resolveRouteBinding('1'))->toBeNull()
        ->and($user->resolveRouteBinding($made['user'])?->uuid)->toBe($made['user']);
});
