<?php

/*
 * php artisan auth:reset-two-factor {email} — docs/slices/04b-two-factor.md (Operator command).
 * The only path outside the web application for a locked-out last owner.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\TwoFactor;

function runReset(string $email): array
{
    $code = Artisan::call('auth:reset-two-factor', ['email' => $email]);

    return [$code, Artisan::output()];
}

it('turns off the two-factor of a live user, naming the uuid and never the address [FR-INST-04]', function () {
    $user = Accounts::user(['email' => 'locked.owner@example.test']);
    TwoFactor::enable($this, $user);

    [$code, $output] = runReset('locked.owner@example.test');

    expect($code)->toBe(0)
        ->and($output)->toContain($user['user'])
        ->and($output)->not->toContain('locked.owner@example.test');
    $row = TwoFactor::row($user['user']);
    expect($row['two_factor_secret'])->toBeNull()
        ->and($row['two_factor_recovery_codes'])->toBeNull()
        ->and($row['two_factor_confirmed_at'])->toBeNull()
        ->and($row['two_factor_last_step'])->toBeNull();
    (new AuthClient($this))->login('locked.owner@example.test', $user['password'])->assertOk()->assertJsonPath('data.id', $user['user']);
});

it('matches the address in any letter case [FR-INST-04]', function () {
    $user = Accounts::user(['email' => 'mixed.case@example.test']);
    TwoFactor::enable($this, $user);

    [$code] = runReset('Mixed.Case@Example.TEST');

    expect($code)->toBe(0)
        ->and(TwoFactor::row($user['user'])['two_factor_confirmed_at'])->toBeNull();
});

it('exits 1 with a neutral line for an unknown address, for a user without two-factor and for a removed user [FR-INST-04]', function () {
    $without = Accounts::user(['email' => 'without@example.test']);
    $removed = Accounts::user(['email' => 'gone@example.test', 'role' => 'manager', 'institution' => $without['institution'], 'removed' => true]);
    TwoFactor::plantUnconfirmed($removed['user'], 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

    foreach (['nobody@example.test', 'without@example.test', 'gone@example.test'] as $email) {
        [$code, $output] = runReset($email);

        expect($code)->toBe(1)
            ->and($output)->not->toContain($email);
    }
    // The answer for an unknown address and for a user without it is the same line.
    expect(runReset('nobody@example.test')[1])->toBe(runReset('without@example.test')[1]);
});

it('writes one log line with the uuid and the outcome, never the address [FR-INST-04, NFR-SEC-05]', function () {
    $user = Accounts::user(['email' => 'logged.owner@example.test']);
    TwoFactor::enable($this, $user);

    runReset('logged.owner@example.test');

    $found = false;
    foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
        $content = file_get_contents($log);
        expect($content)->not->toContain('logged.owner@example.test');
        $found = $found || (str_contains($content, 'two_factor.operator_reset') && str_contains($content, $user['user']));
    }
    expect($found)->toBeTrue();
});
