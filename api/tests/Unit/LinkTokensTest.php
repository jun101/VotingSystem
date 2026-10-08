<?php

use App\Models\User;
use App\Support\LinkTokens;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;

beforeEach(fn () => Accounts::reset());

function userRow(): User
{
    $made = Accounts::user();

    // Test setup: nobody is signed in, so the institution scope is removed on purpose.

    return User::withoutInstitutionScope()->where('uuid', $made['user'])->firstOrFail();
}

it('issues a 64-hex token from a secure random source and stores only its SHA-256 hash [NFR-SEC-02]', function () {
    $user = userRow();

    $first = (new LinkTokens)->issue(LinkTokens::RESET, $user, 60);
    $second = (new LinkTokens)->issue(LinkTokens::VERIFICATION, $user, 60);

    $row = DB::connection(useMigratorConnection())->table('password_reset_tokens')->first();

    expect($first)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second)->not->toBe($first)
        ->and($row->token_hash)->toBe(hash('sha256', $first, true))
        ->and(strlen($row->token_hash))->toBe(32);
});

it('keeps one live token per user: a new one replaces the old [FR-INST-04]', function () {
    $user = userRow();
    $tokens = new LinkTokens;

    $old = $tokens->issue(LinkTokens::RESET, $user, 60);
    $new = $tokens->issue(LinkTokens::RESET, $user, 60);

    expect(DB::connection(useMigratorConnection())->table('password_reset_tokens')->count())->toBe(1)
        ->and($tokens->find(LinkTokens::RESET, $old))->toBeNull()
        ->and($tokens->find(LinkTokens::RESET, $new))->not->toBeNull();
});

it('finds nothing for a value that is not a 64-character hexadecimal string [NFR-SEC-02]', function () {
    $user = userRow();
    $tokens = new LinkTokens;
    $tokens->issue(LinkTokens::RESET, $user, 60);

    foreach (['', 'abc', str_repeat('z', 64), str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65)] as $value) {
        expect($tokens->find(LinkTokens::RESET, $value))->toBeNull();
    }
});

it('tells when a token has expired, and keeps it [FR-INST-04]', function () {
    $user = userRow();
    $tokens = new LinkTokens;
    $token = $tokens->issue(LinkTokens::RESET, $user, 60);

    expect($tokens->isExpired($tokens->find(LinkTokens::RESET, $token)))->toBeFalse();

    Accounts::expireToken('password_reset_tokens', $user->email);

    expect($tokens->isExpired($tokens->find(LinkTokens::RESET, $token)))->toBeTrue();
});

it('forgets a token once spent [FR-INST-04]', function () {
    $user = userRow();
    $tokens = new LinkTokens;
    $token = $tokens->issue(LinkTokens::VERIFICATION, $user, 60);

    $tokens->forget(LinkTokens::VERIFICATION, $tokens->find(LinkTokens::VERIFICATION, $token));

    expect($tokens->find(LinkTokens::VERIFICATION, $token))->toBeNull();
});

it('keeps its two tables apart [FR-INST-04]', function () {
    $user = userRow();
    $tokens = new LinkTokens;
    $token = $tokens->issue(LinkTokens::VERIFICATION, $user, 60);

    expect($tokens->find(LinkTokens::RESET, $token))->toBeNull();
});
