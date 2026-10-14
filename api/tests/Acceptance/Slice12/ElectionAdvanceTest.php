<?php

/*
 * `php artisan elections:advance`, run every minute (docs/slices/12-voting-flow.md "Opening and closing"):
 * scheduled elections whose start has passed open, open ones whose end has passed close. Idempotent.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Accounts;
use Tests\Support\Voting;

it('opens a scheduled election whose start has passed and sets opened_at [FR-ELEC-03] (scenario 1)', function () {
    $e = Voting::election(['status' => 'scheduled', 'starts_at' => now('UTC')->subMinute()->toDateTimeString()]);

    expect(Artisan::call('elections:advance'))->toBe(0);

    $row = Accounts::electionRow($e['election']);
    expect($row['status'])->toBe('open')
        ->and($row['opened_at'])->not->toBeNull();
});

it('leaves a scheduled election whose start is still ahead [FR-ELEC-03] (scenario 2)', function () {
    $e = Voting::election(['status' => 'scheduled', 'starts_at' => now('UTC')->addHour()->toDateTimeString()]);

    Artisan::call('elections:advance');

    expect(Accounts::electionRow($e['election'])['status'])->toBe('scheduled');
});

it('closes an open election whose end has passed and sets closed_at [FR-ELEC-03] (scenario 3)', function () {
    $e = Voting::election(['status' => 'open', 'ends_at' => now('UTC')->subMinute()->toDateTimeString()]);

    Artisan::call('elections:advance');

    $row = Accounts::electionRow($e['election']);
    expect($row['status'])->toBe('closed')
        ->and($row['closed_at'])->not->toBeNull();
});

it('does not touch drafts, closed, published or archived elections, and does it again without effect [FR-ELEC-03] (scenario 4)', function () {
    $ids = [];
    foreach (['draft', 'closed', 'published', 'archived'] as $status) {
        $ids[$status] = Voting::election(['status' => $status, 'starts_at' => now('UTC')->subDays(3)->toDateTimeString(), 'ends_at' => now('UTC')->subDay()->toDateTimeString()])['election'];
    }

    Artisan::call('elections:advance');
    Artisan::call('elections:advance');

    foreach ($ids as $status => $id) {
        expect(Accounts::electionRow($id)['status'])->toBe($status);
    }
});

it('opens and closes across institutions in one run and is scheduled every minute [NFR-OPS-03] (scenario 5)', function () {
    $a = Voting::election(['status' => 'scheduled', 'starts_at' => now('UTC')->subMinute()->toDateTimeString()]);
    $b = Voting::election(['status' => 'open', 'ends_at' => now('UTC')->subMinute()->toDateTimeString()]);

    Artisan::call('elections:advance');
    Artisan::call('schedule:list');

    expect(Accounts::electionRow($a['election'])['status'])->toBe('open')
        ->and(Accounts::electionRow($b['election'])['status'])->toBe('closed')
        ->and(Artisan::output())->toContain('elections:advance');
});
