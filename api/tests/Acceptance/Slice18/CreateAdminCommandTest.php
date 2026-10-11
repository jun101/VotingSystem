<?php

/*
 * php artisan platform:create-admin {email} — docs/api/platform/COMMAND-platform-create-admin.md
 */

use Illuminate\Support\Facades\Hash;
use Tests\Support\Accounts;

it('creates a platform admin with a hashed password, verified, with no institution [FR-ADM-01] (scenario 1)', function () {
    $this->artisan('platform:create-admin', ['email' => 'Ops@Platform.test'])
        ->expectsQuestion('Password', 'a long enough password')
        ->expectsQuestion('Confirm password', 'a long enough password')
        ->assertExitCode(0);

    $row = Accounts::userRow('ops@platform.test');
    expect($row['role'])->toBe('platform_admin')
        ->and($row['institution_id'])->toBeNull()
        ->and($row['email_verified_at'])->not->toBeNull()
        ->and($row['password'])->not->toContain('a long enough password')
        ->and(Hash::check('a long enough password', $row['password']))->toBeTrue();
    $this->browser->login('ops@platform.test', 'a long enough password')->assertOk()->assertJsonPath('data.role', 'platform_admin');
});

it('creates nothing when the passwords differ or the password is short [NFR-SEC-02] (scenario 2)', function (string $one, string $two) {
    $this->artisan('platform:create-admin', ['email' => 'ops@platform.test'])
        ->expectsQuestion('Password', $one)
        ->expectsQuestion('Confirm password', $two)
        ->assertExitCode(1);

    expect(Accounts::userRow('ops@platform.test'))->toBeNull();
})->with([
    'different' => ['a long enough password', 'another long password'],
    'short' => ['short', 'short'],
]);

it('creates nothing for an invalid email [FR-ADM-01] (scenario 3)', function () {
    $this->artisan('platform:create-admin', ['email' => 'not an email'])->assertExitCode(1);

    expect(Accounts::userRow('not an email'))->toBeNull();
});

it('creates nothing when the email already belongs to a user, removed or not [FR-ADM-01] (scenario 4)', function () {
    Accounts::user(['email' => 'taken@example.test']);
    Accounts::user(['email' => 'gone@example.test', 'removed' => true]);

    foreach (['taken@example.test', 'gone@example.test'] as $email) {
        $this->artisan('platform:create-admin', ['email' => $email])->assertExitCode(1);
    }

    expect(Accounts::userRow('taken@example.test')['role'])->toBe('owner')
        ->and(Accounts::userRow('gone@example.test')['role'])->toBe('owner');
});

it('reads the password from an environment variable with --password-from-env, and refuses an unset or short one [FR-ADM-01] (options)', function () {
    putenv('PLATFORM_ADMIN_PASSWORD=a long enough password');
    $this->artisan('platform:create-admin', ['email' => 'env@platform.test', '--password-from-env' => 'PLATFORM_ADMIN_PASSWORD'])->assertExitCode(0);
    putenv('PLATFORM_ADMIN_PASSWORD=short');
    $this->artisan('platform:create-admin', ['email' => 'short@platform.test', '--password-from-env' => 'PLATFORM_ADMIN_PASSWORD'])->assertExitCode(1);
    putenv('PLATFORM_ADMIN_PASSWORD');
    $this->artisan('platform:create-admin', ['email' => 'unset@platform.test', '--password-from-env' => 'PLATFORM_ADMIN_PASSWORD'])->assertExitCode(1);

    expect(Accounts::userRow('env@platform.test')['role'])->toBe('platform_admin')
        ->and(Accounts::userRow('short@platform.test'))->toBeNull()
        ->and(Accounts::userRow('unset@platform.test'))->toBeNull();
});
