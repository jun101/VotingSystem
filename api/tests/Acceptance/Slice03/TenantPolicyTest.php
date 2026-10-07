<?php

/*
 * The base policy every resource policy extends — docs/slices/03-admin-shell-and-tenant-isolation.md,
 * section 1, "Policies".
 */

use App\Models\User;
use App\Policies\TenantPolicy;
use Tests\Support\Accounts;
use Tests\Support\Tenancy;
use Tests\Support\Tenancy\TenantProbe;

beforeEach(function () {
    $this->t = Tenancy::twoInstitutions();
    $this->policy = new class extends TenantPolicy {};
});

function userFor(string $uuid): User
{
    // Test setup: nobody is signed in, so the scope is removed on purpose.
    return User::withoutInstitutionScope()->where('uuid', $uuid)->firstOrFail();
}

function probeFor(string $uuid): TenantProbe
{
    // Test setup: reading a record of any institution to hand it to the policy.
    return TenantProbe::withoutInstitutionScope()->where('uuid', $uuid)->firstOrFail();
}

it('is abstract, so a resource policy must extend it [NFR-SEC-03] (rule 1)', function () {
    expect((new ReflectionClass(TenantPolicy::class))->isAbstract())->toBeTrue();
});

it('says a record belongs to the user when their institutions match [NFR-SEC-03] (rule 1)', function () {
    $probe = probeFor($this->t['a']['probe']['uuid']);

    expect($this->policy->belongsToUsersInstitution(userFor($this->t['a']['owner']['user']), $probe))->toBeTrue()
        ->and($this->policy->belongsToUsersInstitution(userFor($this->t['a']['manager']['user']), $probe))->toBeTrue();
});

it('says it does not when the record is another institution\'s [NFR-SEC-03] (rule 2)', function () {
    $probe = probeFor($this->t['a']['probe']['uuid']);

    expect($this->policy->belongsToUsersInstitution(userFor($this->t['b']['owner']['user']), $probe))->toBeFalse()
        ->and($this->policy->belongsToUsersInstitution(userFor($this->t['b']['manager']['user']), $probe))->toBeFalse();
});

it('never says it to a platform admin [NFR-SEC-03] (rule 3)', function () {
    $admin = Accounts::user(['role' => 'platform_admin']);

    expect($this->policy->belongsToUsersInstitution(userFor($admin['user']), probeFor($this->t['a']['probe']['uuid'])))->toBeFalse();
});
