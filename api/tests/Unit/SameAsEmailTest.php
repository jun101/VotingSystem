<?php

use App\Rules\SameAsEmail;
use Illuminate\Support\Facades\Validator;

function passwordFails(string $password, ?string $email): bool
{
    return Validator::make(['password' => $password], ['password' => [new SameAsEmail($email)]])->fails();
}

it('refuses a password equal to the email, in any letter case [NFR-SEC-02]', function () {
    expect(passwordFails('marie@example.test', 'marie@example.test'))->toBeTrue()
        ->and(passwordFails('MARIE@Example.Test', 'marie@example.test'))->toBeTrue()
        ->and(passwordFails(' marie@example.test ', 'marie@example.test'))->toBeTrue();
});

it('accepts any other password, and any password when there is no email [NFR-SEC-02]', function () {
    expect(passwordFails('marie@example.test and more', 'marie@example.test'))->toBeFalse()
        ->and(passwordFails('something else entirely', 'marie@example.test'))->toBeFalse()
        ->and(passwordFails('marie@example.test', null))->toBeFalse();
});

it('reports the code same_as_email [NFR-SEC-02]', function () {
    $validator = Validator::make(['password' => 'a@b.test'], ['password' => [new SameAsEmail('a@b.test')]]);

    expect($validator->fails())->toBeTrue()
        ->and(array_keys($validator->failed()['password']))->toBe([SameAsEmail::class]);
});
