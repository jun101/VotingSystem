<?php

use App\Support\Credentials\SlipFileName;

it('slugs a group name without accents or symbols', function () {
    expect(SlipFileName::for('4e Année', 1, 1))->toBe('coupons-4e-annee.pdf')
        ->and(SlipFileName::for('Élèves & Parents / Été!', 1, 1))->toBe('coupons-eleves-parents-ete.pdf');
});

it('names the voters with no group sans-groupe', function () {
    expect(SlipFileName::for(null, 1, 1))->toBe('coupons-sans-groupe.pdf');
});

it('adds the part only when there are several', function () {
    expect(SlipFileName::for('4e Année', 2, 3))->toBe('coupons-4e-annee-partie-2.pdf')
        ->and(SlipFileName::for(null, 1, 2))->toBe('coupons-sans-groupe-partie-1.pdf');
});

it('falls back to a plain word when nothing is left of the name', function () {
    expect(SlipFileName::for('???', 1, 1))->toBe('coupons-groupe.pdf');
});

it('names a file of codes with the group or tous', function () {
    expect(SlipFileName::codes('4e Année', 'xlsx'))->toBe('codes-4e-annee.xlsx')
        ->and(SlipFileName::codes(null, 'csv'))->toBe('codes-tous.csv')
        ->and(SlipFileName::codes('???', 'csv'))->toBe('codes-groupe.csv');
});
