<?php

/*
 * The clean-up of slip files (FR-CRED-05: "downloadable until the election closes, and is then deleted"):
 * `php artisan files:purge`, run daily by the scheduler. docs/slices/10-codes-and-slips.md "Rules" and "Purge".
 */

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Team;

/** One election of A in the given status with one ready file. Returns [team, election, file, stored name]. */
function purgeFile(string $status, array $team = []): array
{
    $t = $team ?: Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status, 'title' => "Purge {$status}"]);
    $file = Accounts::plantGeneratedFile(['election' => $election]);
    $stored = Accounts::generatedFileRows($election)[0]['stored_file'];

    return [$t, $election, $file, $stored];
}

it('deletes the files, the rows and the stored copies of a closed, published or archived election [FR-CRED-05] (scenario 1)', function (string $status) {
    [$t, $election, $file, $stored] = purgeFile($status);
    expect(Codes::storedFiles())->toBe([$stored]);

    expect(Artisan::call('files:purge'))->toBe(0);

    expect(Accounts::generatedFileRows($election))->toBe([])
        ->and(Codes::storedFiles())->toBe([]);
})->with(['closed', 'published', 'archived']);

it('keeps the files of a draft, scheduled or open election [FR-CRED-05] (scenario 2)', function (string $status) {
    [$t, $election, $file, $stored] = purgeFile($status);

    expect(Artisan::call('files:purge'))->toBe(0);

    expect(Accounts::generatedFileRows($election))->toHaveCount(1)
        ->and(Codes::storedFiles())->toBe([$stored]);
})->with(['draft', 'scheduled', 'open']);

it('purges across institutions and keeps the others [FR-CRED-05] (scenario 3)', function () {
    [$t, $closed] = purgeFile('closed');
    $open = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'open']);
    Accounts::plantGeneratedFile(['election' => $open, 'user' => $t['b']['owner']['user']]);

    Artisan::call('files:purge');

    expect(Accounts::generatedFileRows($closed))->toBe([])
        ->and(Accounts::generatedFileRows($open))->toHaveCount(1)
        ->and(Codes::storedFiles())->toHaveCount(1);
});

it('removes a failed or queued row of a closed election, which holds no stored copy [FR-CRED-05] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'closed']);
    Accounts::plantGeneratedFile(['election' => $election, 'status' => 'failed']);
    Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued']);

    expect(Artisan::call('files:purge'))->toBe(0);

    expect(Accounts::generatedFileRows($election))->toBe([]);
});

it('marks a file queued for more than an hour as failed, so a new run can start [FR-CRED-04] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Attend']);
    $stale = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'created_at' => now('UTC')->subMinutes(61)->toDateTimeString()]);
    $fresh = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'created_at' => now('UTC')->subMinutes(5)->toDateTimeString()]);

    expect(Artisan::call('files:purge'))->toBe(0);

    $rows = collect(Accounts::generatedFileRows($election))->keyBy('uuid');
    expect($rows[$stale]['status'])->toBe('failed')
        ->and($rows[$stale]['failure'])->toBe('generation_failed')
        ->and($rows[$fresh]['status'])->toBe('queued');
});

it('lets a new run start once the stale file is failed [FR-CRED-04] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Attend']);
    Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'created_at' => now('UTC')->subMinutes(90)->toDateTimeString()]);
    Team::signIn($this, $t['a']['owner']);
    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'run_in_progress');

    Artisan::call('files:purge');

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);
    expect(Accounts::credentialRows($election))->toHaveCount(1);
});

it('is idempotent and prints no code, name or file name [FR-CRED-02] (scenario 6)', function () {
    [$t, $election, $file, $stored] = purgeFile('closed');
    Artisan::call('files:purge');

    expect(Artisan::call('files:purge'))->toBe(0);

    $output = Artisan::output();
    expect($output)->not->toContain($stored)->and($output)->not->toContain($file);
});

it('is run every day by the scheduler [FR-CRED-05] (scenario 7)', function () {
    expect(Artisan::call('schedule:list'))->toBe(0);

    expect(Artisan::output())->toContain('files:purge');
});
