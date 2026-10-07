<?php

use App\Jobs\SendPasswordResetLink;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\Browser;
use Tests\Support\Accounts;

/*
 * The fixes of the review of slice 02 (docs/slices/02-sign-up-and-sign-in.review.md).
 */

beforeEach(fn () => Accounts::reset());

/** `config/auth.php` as it reads with the given environment. */
function authConfigWith(string $env, string $factor): array
{
    $before = [$_ENV['APP_ENV'] ?? null, $_ENV['AUTH_RATE_LIMIT_FACTOR'] ?? null];

    foreach (['APP_ENV' => $env, 'AUTH_RATE_LIMIT_FACTOR' => $factor] as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require base_path('config/auth.php');
    } finally {
        foreach (['APP_ENV' => $before[0], 'AUTH_RATE_LIMIT_FACTOR' => $before[1]] as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
}

it('honours the rate limit factor only in local and testing [S2]', function () {
    expect(authConfigWith('local', '100')['rate_limit_factor'])->toBe(100)
        ->and(authConfigWith('testing', '100')['rate_limit_factor'])->toBe(100)
        ->and(authConfigWith('production', '100')['rate_limit_factor'])->toBe(1)
        ->and(authConfigWith('staging', '100')['rate_limit_factor'])->toBe(1);
});

it('counts failed sign-ins by account, whatever the accents and the case of the address [S4]', function () {
    $user = Accounts::user(['email' => 'jose@example.test']);
    $browser = new Browser($this);

    foreach (['jose@example.test', 'josé@example.test', 'JOSE@example.test', 'josé@example.test', 'jose@example.test'] as $email) {
        $browser->login($email, 'a wrong password, long enough')->assertStatus(401);
    }

    $browser->login($user['email'], $user['password'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('counts forgot-password requests by account, whatever the accents of the address [S4]', function () {
    Accounts::user(['email' => 'jose@example.test']);
    $browser = new Browser($this);

    foreach (['jose@example.test', 'josé@example.test', 'JOSE@example.test'] as $email) {
        $browser->post('/api/v1/auth/forgot-password', ['email' => $email])->assertNoContent();
    }

    $browser->post('/api/v1/auth/forgot-password', ['email' => 'josé@example.test'])->assertStatus(429);
});

it('does the same work for a known and an unknown address: one encrypted job each [S5]', function () {
    Queue::fake();
    $user = Accounts::user();
    $browser = new Browser($this);

    $browser->post('/api/v1/auth/forgot-password', ['email' => $user['email']])->assertNoContent();
    $browser->post('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertNoContent();

    Queue::assertPushed(SendPasswordResetLink::class, 2);
    expect(new SendPasswordResetLink('a@example.test'))->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and(Accounts::tokenRows('password_reset_tokens', $user['email']))->toBe([])
        ->and(Accounts::mail())->toBe([]);
});

it('sends nothing and issues nothing for an unknown address, in the job [S5]', function () {
    app()->call([new SendPasswordResetLink('nobody@example.test'), 'handle']);

    expect(Accounts::mail())->toBe([]);
});

it('does not let a caller set the role, the institution or the suspension by mass assignment [S7]', function () {
    $institution = Institution::factory()->create();
    $user = new User(['name' => 'A', 'role' => 'platform_admin', 'institution_id' => $institution->getKey()]);
    $copy = new Institution(['name' => 'B', 'suspended_at' => now()]);

    expect($user->getAttributes())->toHaveKey('name')->not->toHaveKey('role')->not->toHaveKey('institution_id')
        ->and($copy->getAttributes())->not->toHaveKey('suspended_at');
});

it('refuses a sign-in with a very long email or password [S8]', function () {
    $browser = new Browser($this);

    $browser->login(str_repeat('a', 250).'@example.test', 'x')->assertStatus(422)->assertJsonPath('error.fields.email', ['max']);
    $browser->login('a@example.test', str_repeat('p', 1025))->assertStatus(422)->assertJsonPath('error.fields.password', ['max']);
});

it('refuses, at reset, a password equal to the email once trimmed, and leaves the link usable [C4]', function () {
    $user = Accounts::user(['email' => 'marie@example.test']);
    $token = Accounts::token();
    Accounts::plantToken('password_reset_tokens', $user['email'], $token);
    $browser = new Browser($this);

    $browser->post('/api/v1/auth/reset-password', ['token' => $token, 'password' => '  Marie@Example.test  '])
        ->assertStatus(422)->assertJsonPath('error.fields.password', ['same_as_email']);

    expect(Accounts::tokenRows('password_reset_tokens', $user['email']))->toHaveCount(1);
});
