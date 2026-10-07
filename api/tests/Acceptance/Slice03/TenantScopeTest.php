<?php

/*
 * Tenant isolation, the scope itself — docs/slices/03-admin-shell-and-tenant-isolation.md,
 * section 1 and "Rules checked by tests" 3, 4 and 6.
 */

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Tenancy;
use Tests\Support\Tenancy\TenantProbe;

beforeEach(function () {
    Tenancy::registerProbeRoutes();
    $this->t = Tenancy::twoInstitutions();
});

/** The user as a model, read the way sign-in reads one (nobody is signed in yet). */
function userModel(string $uuid): User
{
    // Test setup: nobody is signed in yet, so the scope is removed on purpose.
    return User::withoutInstitutionScope()->where('uuid', $uuid)->firstOrFail();
}

it('scopes every query to the signed-in user\'s institution [NFR-SEC-03] (rule 1)', function () {
    Auth::setUser(userModel($this->t['a']['owner']['user']));

    expect(TenantProbe::query()->pluck('uuid')->all())->toEqualCanonicalizing([$this->t['a']['probe']['uuid'], $this->t['a']['second']['uuid']])
        ->and(TenantProbe::query()->count())->toBe(2)
        ->and(TenantProbe::query()->where('uuid', $this->t['b']['probe']['uuid'])->exists())->toBeFalse()
        ->and(TenantProbe::query()->whereKey(DB::connection(useMigratorConnection())->table('tenant_probes')->where('uuid', $this->t['b']['probe']['uuid'])->value('id'))->first())->toBeNull();
});

it('scopes the users of the application too [NFR-SEC-03] (rule 1)', function () {
    Auth::setUser(userModel($this->t['a']['owner']['user']));

    expect(User::query()->pluck('uuid')->all())->toEqualCanonicalizing([$this->t['a']['owner']['user'], $this->t['a']['manager']['user']])
        ->and(User::query()->where('uuid', $this->t['b']['owner']['user'])->first())->toBeNull();
});

it('scopes a relation as well [NFR-SEC-03] (rule 1)', function () {
    Auth::setUser(userModel($this->t['b']['owner']['user']));

    $probe = TenantProbe::query()->where('uuid', $this->t['b']['probe']['uuid'])->firstOrFail();
    expect($probe->notes()->pluck('uuid')->all())->toBe([$this->t['b']['note']['uuid']]);
});

it('returns nothing when nobody is signed in: the scope fails closed [NFR-SEC-03] (rule 3)', function () {
    Auth::forgetGuards();

    expect(TenantProbe::query()->get())->toHaveCount(0)
        ->and(TenantProbe::query()->count())->toBe(0)
        ->and(User::query()->get())->toHaveCount(0)
        ->and(Tenancy::probeCount())->toBe(4);
});

it('returns nothing to a platform admin, who has no institution [NFR-SEC-03] (rule 3)', function () {
    $admin = Accounts::user(['role' => 'platform_admin']);
    Auth::setUser(userModel($admin['user']));

    expect(TenantProbe::query()->get())->toHaveCount(0)
        ->and(User::query()->get())->toHaveCount(0);
});

it('refuses to create a tenant record when nobody can own it [NFR-SEC-03] (rule 3)', function () {
    Auth::forgetGuards();
    $before = Tenancy::probeCount();

    expect(fn () => TenantProbe::create(['title' => 'Orphan']))->toThrow(LogicException::class);
    expect(Tenancy::probeCount())->toBe($before);
});

it('fills the institution of the signed-in user on create [NFR-SEC-03] (rule 1)', function () {
    Auth::setUser(userModel($this->t['a']['manager']['user']));

    $probe = TenantProbe::create(['title' => 'Mine']);

    expect(Tenancy::probeInstitutionKey($probe->uuid))->toBe(Tenancy::institutionKey($this->t['a']['institution']));
});

it('refuses to move a saved record to another institution [NFR-SEC-03] (rule 1)', function () {
    Auth::setUser(userModel($this->t['a']['owner']['user']));
    $probe = TenantProbe::query()->where('uuid', $this->t['a']['probe']['uuid'])->firstOrFail();

    $probe->institution_id = Tenancy::institutionKey($this->t['b']['institution']);

    expect(fn () => $probe->save())->toThrow(LogicException::class);
    expect(Tenancy::probeInstitutionKey($this->t['a']['probe']['uuid']))->toBe(Tenancy::institutionKey($this->t['a']['institution']));
});

it('lets the scope be removed only by name, and shows the rows when it is [NFR-SEC-03] (rule 4)', function () {
    Auth::setUser(userModel($this->t['a']['owner']['user']));

    // Test of the removal itself: the one named method.
    expect(TenantProbe::withoutInstitutionScope()->count())->toBe(4);
});

it('keeps nothing of one request\'s user for the next on the same worker [NFR-SEC-03] (rule 6)', function () {
    $a = $this->t['a'];
    $b = $this->t['b'];

    $this->browser->login($a['owner']['email'], $a['owner']['password'])->assertOk();
    $listA = $this->browser->get('/api/v1/probes')->assertOk();
    expect(array_column($listA->json('data'), 'id'))->toEqualCanonicalizing([$a['probe']['uuid'], $a['second']['uuid']]);

    // A request with no session, right after: it must see nothing, not A's rows.
    $stranger = new Tests\Support\AuthClient($this);
    $stranger->get('/api/v1/probes')->assertStatus(401);
    $stranger->get('/api/v1/probes-open')->assertOk()->assertExactJson(['data' => []]);

    // Another institution's user, right after: only their own rows.
    $other = new Tests\Support\AuthClient($this);
    $other->login($b['owner']['email'], $b['owner']['password'])->assertOk();
    expect(array_column($other->get('/api/v1/probes')->assertOk()->json('data'), 'id'))
        ->toEqualCanonicalizing([$b['probe']['uuid'], $b['second']['uuid']]);

    // And a request with no session again.
    $stranger->get('/api/v1/probes-open')->assertOk()->assertExactJson(['data' => []]);
    // The first browser still sees only its own.
    expect($this->browser->get('/api/v1/probes-open')->assertOk()->json('data'))
        ->toEqualCanonicalizing([$a['probe']['uuid'], $a['second']['uuid']]);
});
