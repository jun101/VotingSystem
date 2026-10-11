<?php

use App\Enums\ElectionStatus;
use App\Support\Voting\TypedCode;
use App\Support\Voting\VotePhase;
use Illuminate\Support\Carbon;

it('computes the phase from the status and the clock [FR-ELEC-05]', function (ElectionStatus $status, string $now, string $phase) {
    $starts = Carbon::parse('2026-10-12 12:00:00', 'UTC');
    $ends = Carbon::parse('2026-10-14 12:00:00', 'UTC');

    expect(VotePhase::of($status, $starts, $ends, Carbon::parse($now, 'UTC')))->toBe($phase);
})->with([
    'scheduled before start' => [ElectionStatus::Scheduled, '2026-10-12 11:59:59', 'before'],
    'scheduled at start' => [ElectionStatus::Scheduled, '2026-10-12 12:00:00', 'before'],
    'open before its start' => [ElectionStatus::Open, '2026-10-12 11:59:59', 'before'],
    'open at its start' => [ElectionStatus::Open, '2026-10-12 12:00:00', 'open'],
    'open inside' => [ElectionStatus::Open, '2026-10-13 00:00:00', 'open'],
    'open one second before its end' => [ElectionStatus::Open, '2026-10-14 11:59:59', 'open'],
    'open at its end' => [ElectionStatus::Open, '2026-10-14 12:00:00', 'closed'],
    'open after its end' => [ElectionStatus::Open, '2026-10-15 00:00:00', 'closed'],
    'scheduled after its end' => [ElectionStatus::Scheduled, '2026-10-15 00:00:00', 'closed'],
    'closed inside the period' => [ElectionStatus::Closed, '2026-10-13 00:00:00', 'closed'],
    'published' => [ElectionStatus::Published, '2026-10-13 00:00:00', 'closed'],
    'archived' => [ElectionStatus::Archived, '2026-10-13 00:00:00', 'closed'],
]);

it('is closed for a suspended institution whatever the status and the clock [FR-VOTE-02]', function () {
    $starts = Carbon::parse('2026-10-12 12:00:00', 'UTC');
    $ends = Carbon::parse('2026-10-14 12:00:00', 'UTC');

    expect(VotePhase::of(ElectionStatus::Open, $starts, $ends, Carbon::parse('2026-10-13 00:00:00', 'UTC'), suspended: true))->toBe('closed');
});

it('reads the code as the voter typed it: case, hyphen and spaces do not matter [FR-VOTE-01]', function (string $typed) {
    expect(TypedCode::normalise($typed))->toBe('K7MP4QX9RT');
})->with([
    'as shown' => ['K7MP4-QX9RT'],
    'lower case' => ['k7mp4-qx9rt'],
    'no hyphen' => ['K7MP4QX9RT'],
    'spaces' => [' k7mp4 qx9rt '],
    'tabs and a new line' => ["K7MP4\t-QX9RT\n"],
]);

it('cuts what is typed to a length no code reaches [NFR-SEC-05]', function () {
    expect(strlen(TypedCode::normalise(str_repeat('A', 5000))))->toBe(TypedCode::MAX);
});
