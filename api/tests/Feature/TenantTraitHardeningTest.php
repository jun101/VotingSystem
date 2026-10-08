<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Accounts;
use Tests\Support\Tenancy;
use Tests\Support\Tenancy\TenantProbe;

beforeEach(function () {
    Accounts::reset();
    Tenancy::installProbeTables();
    $this->t = Tenancy::twoInstitutions();
});

function hardeningUser(string $uuid): User
{
    // Test setup: nobody is signed in yet, so the scope is removed on purpose.
    return User::withoutInstitutionScope()->where('uuid', $uuid)->firstOrFail();
}

it('refuses a record created for another institution by a signed-in user [NFR-SEC-03]', function () {
    Auth::setUser(hardeningUser($this->t['a']['owner']['user']));
    $probe = new TenantProbe(['title' => 'Sneaky']);
    $probe->institution_id = Tenancy::institutionKey($this->t['b']['institution']);
    $before = Tenancy::probeCount();

    expect(fn () => $probe->save())->toThrow(LogicException::class)
        ->and(Tenancy::probeCount())->toBe($before);
});

it('keeps an institution named by code when the same institution is signed in, nobody is, or a platform admin is [NFR-SEC-03]', function () {
    $key = Tenancy::institutionKey($this->t['b']['institution']);

    Auth::setUser(hardeningUser($this->t['b']['owner']['user']));
    $same = new TenantProbe(['title' => 'Same']);
    $same->institution_id = $key;
    $same->save();

    Auth::forgetGuards();
    $anonymous = new TenantProbe(['title' => 'Anonymous']);
    $anonymous->institution_id = $key;
    $anonymous->save();

    $admin = Accounts::user(['role' => 'platform_admin']);
    Auth::setUser(hardeningUser($admin['user']));
    $byAdmin = new TenantProbe(['title' => 'Admin']);
    $byAdmin->institution_id = $key;
    $byAdmin->save();

    expect(Tenancy::probeInstitutionKey($same->uuid))->toBe($key)
        ->and(Tenancy::probeInstitutionKey($anonymous->uuid))->toBe($key)
        ->and(Tenancy::probeInstitutionKey($byAdmin->uuid))->toBe($key);
});

it('binds a child that is not a uuid to nothing, like a top-level route [NFR-SEC-08]', function () {
    Auth::setUser(hardeningUser($this->t['a']['owner']['user']));
    $probe = TenantProbe::query()->where('uuid', $this->t['a']['probe']['uuid'])->firstOrFail();

    expect($probe->resolveChildRouteBinding('note', '1', 'uuid'))->toBeNull()
        ->and($probe->resolveSoftDeletableChildRouteBinding('note', '1', 'uuid'))->toBeNull()
        ->and($probe->resolveChildRouteBinding('note', $this->t['a']['note']['uuid'], 'uuid')?->uuid)->toBe($this->t['a']['note']['uuid'])
        ->and($probe->resolveChildRouteBinding('note', $this->t['b']['note']['uuid'], 'uuid'))->toBeNull();
});

it('gives the institution of a user as an integer [NFR-SEC-03]', function () {
    $user = hardeningUser($this->t['a']['owner']['user']);

    expect($user->institution_id)->toBeInt();
});

it('answers 401 before 419 when nobody is signed in and no CSRF token is sent [NFR-SEC-03]', function () {
    foreach (['POST' => '/api/v1/auth/logout', 'PATCH' => '/api/v1/auth/me', 'POST ' => '/api/v1/auth/verify-email/resend'] as $method => $uri) {
        $this->json(trim($method), $uri)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }
});
