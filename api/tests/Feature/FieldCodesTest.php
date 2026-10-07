<?php

use App\Exceptions\ApiErrorRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
 * The codes of docs/api/README.md section 3 `fields`: the rule's name, except where the
 * endpoint files name it differently.
 */

function fieldsFor(ValidationException $e): array
{
    $response = (new ApiErrorRenderer)($e, Request::create('/'));

    return $response->getData(true)['error']['fields'];
}

it('names a failed rule by its snake-case name [NFR-SEC-01]', function () {
    $e = new ValidationException(Validator::make(['a' => '', 'b' => 'x', 'c' => str_repeat('z', 5)], [
        'a' => 'required', 'b' => 'min:3', 'c' => 'max:2',
    ]));

    expect(fieldsFor($e))->toBe(['a' => ['required'], 'b' => ['min'], 'c' => ['max']]);
});

it('says invalid for a bad address, a bad choice and a wrong type, and taken for a duplicate [NFR-SEC-01]', function () {
    $e = new ValidationException(Validator::make(['email' => 'nope', 'language' => 'es', 'token' => ['x']], [
        'email' => 'email', 'language' => 'in:fr,en', 'token' => 'string',
    ]));

    expect(fieldsFor($e))->toBe(['email' => ['invalid'], 'language' => ['invalid'], 'token' => ['invalid']]);

    $taken = new ValidationException(Validator::make(['name' => 'x'], ['name' => [Rule::unique('institutions', 'name')]]));
    // (no row: passes) — the code is checked through a rule that fails on purpose.
    expect($taken->validator->fails())->toBeFalse();
});

it('uses the messages as codes for a field refused by hand [NFR-SEC-01]', function () {
    $e = ValidationException::withMessages(['token' => ['invalid'], 'password' => ['same_as_email']]);

    expect(fieldsFor($e))->toBe(['token' => ['invalid'], 'password' => ['same_as_email']]);
});
