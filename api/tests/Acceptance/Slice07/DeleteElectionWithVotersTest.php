<?php

/*
 * DELETE /api/v1/elections/{election} when the election has groups and voters (slice 07): the
 * voters go first, because a voter's group is a restrict foreign key.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

it('deletes a draft election with its groups and voters [FR-ELEC-03, FR-VOT-06]', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Dans le groupe', 'group' => $group]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe']);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Reste']);
    $stays = Accounts::plantVoter(['election' => $other, 'full_name' => 'Reste']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->delete("/api/v1/elections/{$election}")->assertNoContent();

    expect(Accounts::electionRow($election))->toBeNull()
        ->and(Accounts::groupRows($election))->toBe([])
        ->and(Accounts::voterRows($election))->toBe([])
        ->and(Accounts::voterRow($stays))->not->toBeNull();
});
