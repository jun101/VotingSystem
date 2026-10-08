<?php

use App\Rules\Choice;
use Illuminate\Support\Facades\Validator;

function choiceFails(mixed $value): bool
{
    return Validator::make(['x' => $value], ['x' => [new Choice(['fr', 'en'])]])->fails();
}

it('accepts one of the choices and nothing else, case included [FR-INST-02]', function () {
    expect(choiceFails('fr'))->toBeFalse()
        ->and(choiceFails('en'))->toBeFalse()
        ->and(choiceFails('FR'))->toBeTrue()
        ->and(choiceFails('es'))->toBeTrue()
        ->and(choiceFails(1))->toBeTrue();
});

it('judges an empty or null value, which the framework\'s `in` skips [FR-INST-02]', function () {
    expect(choiceFails(''))->toBeTrue()
        ->and(choiceFails(null))->toBeTrue();
});
