<?php

use App\Support\Credentials\AccessCode;

beforeEach(fn () => config()->set('credentials.hash_key', 'unit-test-key'));

it('generates codes of two groups of five from the alphabet with no look-alike', function () {
    for ($i = 0; $i < 200; $i++) {
        $code = AccessCode::generate();

        expect($code)->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');

        foreach (str_split(str_replace('-', '', $code)) as $char) {
            expect(str_contains(AccessCode::ALPHABET, $char))->toBeTrue();
        }
    }

    expect(AccessCode::ALPHABET)->not->toMatch('/[01OIL]/');
});

it('hashes the same code whatever its case or hyphen', function () {
    $hash = AccessCode::hash('ABCDE-FGH23');

    expect(AccessCode::normalize('abcde-fgh23'))->toBe('ABCDEFGH23')
        ->and(AccessCode::hash('abcde-fgh23'))->toBe($hash)
        ->and(AccessCode::hash('ABCDEFGH23'))->toBe($hash)
        ->and(AccessCode::hash('ABCDE-FGH24'))->not->toBe($hash);
});

it('gives a raw hash of 32 bytes', function () {
    expect(strlen(AccessCode::hash(AccessCode::generate())))->toBe(32);
});

it('refuses to hash without a key', function () {
    config()->set('credentials.hash_key', '');

    AccessCode::hash('ABCDE-FGH23');
})->throws(RuntimeException::class);
