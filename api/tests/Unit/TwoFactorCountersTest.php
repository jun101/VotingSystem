<?php

use App\Auth\PendingSignIn;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function pendingSession(string $password = 'hash-one'): array
{
    $session = new Store('test', new ArraySessionHandler(120));
    $user = (new User)->forceFill(['uuid' => '6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa', 'password' => $password]);
    PendingSignIn::start($session, $user);

    return [$session, $user];
}

it('counts the guesses of a pending sign-in one by one, with the one being made included', function () {
    [$session] = pendingSession();
    $pending = PendingSignIn::current($session);

    expect(array_map(fn () => PendingSignIn::reserveGuess($pending), range(1, 6)))->toBe([1, 2, 3, 4, 5, 6]);

    PendingSignIn::clearGuesses($pending);
    expect(PendingSignIn::reserveGuess($pending))->toBe(1);
});

it('keeps the counter of one pending sign-in apart from the next one', function () {
    [$first] = pendingSession();
    [$second] = pendingSession();

    PendingSignIn::reserveGuess(PendingSignIn::current($first));
    PendingSignIn::reserveGuess(PendingSignIn::current($first));

    expect(PendingSignIn::reserveGuess(PendingSignIn::current($second)))->toBe(1);
});

it('records a fingerprint that changes with the password hash and shows neither', function () {
    [$session, $user] = pendingSession('hash-one');
    $pending = PendingSignIn::current($session);
    $user->forceFill(['password' => 'hash-two']);

    expect($pending['pw'])->not->toBe(PendingSignIn::passwordFingerprint($user))
        ->and($pending['pw'])->not->toContain('hash-one')
        ->and($pending)->not->toHaveKey('wrong');
});

it('ends a pending sign-in that is older than five minutes or damaged', function () {
    [$session] = pendingSession();
    $this->travel(301)->seconds();

    expect(PendingSignIn::current($session))->toBeNull();

    $session->put('two_factor_pending', ['user' => 'x']);
    expect(PendingSignIn::current($session))->toBeNull()->and($session->has('two_factor_pending'))->toBeFalse();
});

it('puts the issuer and the account in the otpauth link apart, joined by a plain colon', function () {
    $url = (new TwoFactor)->otpauthUrl('JBSWY3DPEHPK3PXP', 'marie@flamboyants.example');

    expect($url)->toStartWith('otpauth://totp/New%20Voting%20System:marie%40flamboyants.example?');
});
