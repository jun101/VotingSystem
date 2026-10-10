<?php

/*
 * POST /api/v1/elections/{election}/duplicate, ballots part (slice 06a) —
 * docs/api/elections/POST-elections-{election}-duplicate.md scenario 3b.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

it('copies every ballot, in the same order, with new UUIDs [FR-ELEC-06, FR-BAL-01] (scenario 3b)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'closed']);
    $one = Accounts::plantBallot(['election' => $source, 'title' => 'Président(e)', 'position' => 1, 'seats' => 1, 'allow_blank' => true, 'description' => 'Pour toute l\'école']);
    $two = Accounts::plantBallot(['election' => $source, 'title' => 'Délégués', 'position' => 2, 'seats' => 3, 'allow_blank' => false]);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post("/api/v1/elections/{$source}/duplicate", [])->assertCreated();

    $copy = $response->json('data.id');
    $rows = Accounts::ballotRows($copy);
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'title'))->toBe(['Président(e)', 'Délégués'])
        ->and(array_column($rows, 'position'))->toBe([1, 2])
        ->and(array_column($rows, 'seats'))->toBe([1, 3])
        ->and(array_map('boolval', array_column($rows, 'allow_blank')))->toBe([true, false])
        ->and($rows[0]['description'])->toBe('Pour toute l\'école')
        ->and(array_intersect(array_column($rows, 'uuid'), [$one, $two]))->toBe([])
        ->and($response->json('data.ballots_count'))->toBe(2)
        ->and($rows[0]['institution_id'])->toBe(Accounts::electionRow($source)['institution_id']);
    // The source keeps its own ballots.
    expect(array_column(Accounts::ballotRows($source), 'uuid'))->toBe([$one, $two]);
});

it('copies nothing for a source with no ballot [FR-ELEC-06] (scenario 3b)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $copy = $this->browser->post("/api/v1/elections/{$source}/duplicate", [])->assertCreated()->json('data.id');

    expect(Accounts::ballotRows($copy))->toBe([]);
});

it('deletes the copy\'s ballots without touching the source\'s [FR-ELEC-06] (scenario 3b)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $kept = Accounts::plantBallot(['election' => $source]);
    Team::signIn($this, $t['a']['owner']);
    $copy = $this->browser->post("/api/v1/elections/{$source}/duplicate", [])->assertCreated()->json('data.id');

    $this->browser->delete("/api/v1/elections/{$copy}")->assertNoContent();

    expect(Accounts::ballotRow($kept))->not->toBeNull()
        ->and(Accounts::ballotRows($copy))->toBe([]);
});
