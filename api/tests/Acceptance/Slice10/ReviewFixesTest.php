<?php

/*
 * Slice 10 review round: a stuck run cannot block an election, a deleted election takes its stored files with it, the
 * file's expiry follows the election's end. docs/slices/10-codes-and-slips.md "Review".
 */

use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Team;

/** An owner signed in and a draft election with one voter. Returns [election, voter]. */
function reviewSetup($test, array $election = []): array
{
    $t = Team::two();
    $id = Accounts::plantElection($election + ['institution' => $t['a']['owner']['institution'], 'starts_at' => '2030-03-01 12:00:00', 'ends_at' => '2030-03-05 19:00:00']);
    $voter = Accounts::plantVoter(['election' => $id, 'full_name' => 'Rose Désir']);
    Team::signIn($test, $t['a']['owner']);

    return [$id, $voter];
}

it('fails a file queued for more than 15 minutes when a run starts, so it cannot block the election [FR-CRED-04] (review 1)', function () {
    [$election] = reviewSetup($this);
    $stale = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'created_at' => now('UTC')->subMinutes(16)->toDateTimeString()]);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);

    $rows = collect(Accounts::generatedFileRows($election))->keyBy('uuid');
    expect($rows[$stale]['status'])->toBe('failed')
        ->and($rows[$stale]['failure'])->toBe('generation_failed')
        ->and(Accounts::credentialRows($election))->toHaveCount(1);
});

it('still answers 409 run_in_progress for a file queued a few minutes ago [FR-CRED-04] (review 1)', function () {
    [$election] = reviewSetup($this);
    Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'created_at' => now('UTC')->subMinutes(3)->toDateTimeString()]);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'run_in_progress');
});

it('deletes the stored files of a draft election when the election is deleted [FR-CRED-05] (review 2)', function () {
    [$election] = reviewSetup($this);
    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'file' => null])->assertStatus(202);
    expect(Codes::storedFiles())->toHaveCount(1);

    $this->browser->delete("/api/v1/elections/{$election}")->assertNoContent();

    expect(Codes::storedFiles())->toBe([])
        ->and(Accounts::generatedFileRows($election))->toBe([])
        ->and(Accounts::electionRow($election))->toBeNull();
});

it('reports the election\'s current end as the expiry of its files [FR-CRED-05] (review 9)', function () {
    [$election] = reviewSetup($this);
    $response = $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);
    $file = $response->json('data.files.0.id');
    expect($response->json('data.files.0.expires_at'))->toBe('2030-03-05T19:00:00Z');

    $this->browser->patch("/api/v1/elections/{$election}", ['ends_at' => '2030-03-12T19:00:00Z'])->assertOk();

    $this->browser->get("/api/v1/generated-files/{$file}")->assertOk()->assertJsonPath('data.expires_at', '2030-03-12T19:00:00Z');
    expect($this->browser->get("/api/v1/elections/{$election}/generated-files")->json('data.0.expires_at'))->toBe('2030-03-12T19:00:00Z');
});
