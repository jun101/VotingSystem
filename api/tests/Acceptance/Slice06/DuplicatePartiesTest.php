<?php

/*
 * POST /api/v1/elections/{election}/duplicate, parties part (slice 06b) —
 * docs/api/elections/POST-elections-{election}-duplicate.md scenario 3c.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

it('copies every party with new UUIDs and the same name, acronym and colour [FR-ELEC-06, FR-CAND-01] (scenario 3c)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'closed']);
    $one = Accounts::plantParty(['election' => $source, 'name' => 'Avenir Étudiant', 'acronym' => 'AE', 'colour' => '#5468D4']);
    $two = Accounts::plantParty(['election' => $source, 'name' => 'Ensemble', 'colour' => '#C2410C']);
    Team::signIn($this, $t['a']['owner']);

    $copy = $this->browser->post("/api/v1/elections/{$source}/duplicate", [])->assertCreated()->json('data.id');

    $rows = Accounts::partyRows($copy);
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'name'))->toBe(['Avenir Étudiant', 'Ensemble'])
        ->and(array_column($rows, 'acronym'))->toBe(['AE', null])
        ->and(array_column($rows, 'colour'))->toBe(['#5468D4', '#C2410C'])
        ->and(array_intersect(array_column($rows, 'uuid'), [$one, $two]))->toBe([])
        ->and($rows[0]['logo_file'])->toBeNull()
        ->and($rows[0]['institution_id'])->toBe(Accounts::electionRow($source)['institution_id']);
    expect(array_column(Accounts::partyRows($source), 'uuid'))->toBe([$one, $two]);
});

it('deletes the copy\'s parties without touching the source\'s [FR-ELEC-06] (scenario 3c)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $kept = Accounts::plantParty(['election' => $source]);
    Team::signIn($this, $t['a']['owner']);
    $copy = $this->browser->post("/api/v1/elections/{$source}/duplicate", [])->assertCreated()->json('data.id');

    $this->browser->delete("/api/v1/elections/{$copy}")->assertNoContent();

    expect(Accounts::partyRow($kept))->not->toBeNull()
        ->and(Accounts::partyRows($copy))->toBe([]);
});
