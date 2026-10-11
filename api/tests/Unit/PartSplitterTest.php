<?php

use App\Support\Credentials\PartSplitter;

it('gives no part for no voter', function () {
    expect(PartSplitter::sizes(0))->toBe([]);
});

it('keeps up to 1 000 voters in one part', function () {
    expect(PartSplitter::sizes(1))->toBe([1])
        ->and(PartSplitter::sizes(1000))->toBe([1000]);
});

it('gives two parts for 1 001 voters', function () {
    expect(PartSplitter::sizes(1001))->toBe([1000, 1]);
});

it('keeps the order, full parts first and the rest last', function () {
    expect(PartSplitter::sizes(2500))->toBe([1000, 1000, 500])
        ->and(array_sum(PartSplitter::sizes(3017)))->toBe(3017);
});
