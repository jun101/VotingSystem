<?php

/*
 * GET /api/v1/elections/{election}/checklist — docs/api/elections/GET-elections-{election}-checklist.md
 * Tenant suite, route api/v1/elections/{election}/checklist: another institution's election answers 404, the same as an
 * unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const CHECKLIST_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function checklistUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/checklist";
}

function checklistSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

/** A ballot with the given number of candidates. */
function checklistBallot(string $election, string $title, int $candidates, array $groups = []): string
{
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => $title, 'groups' => $groups]);
    for ($i = 1; $i <= $candidates; $i++) {
        Accounts::plantCandidate(['ballot' => $ballot, 'last_name' => "{$title} {$i}"]);
    }

    return $ballot;
}

it('is ready when there is a ballot with two candidates and a voter [FR-ELEC-04] (scenario 1)', function () {
    [$t, $election] = checklistSignedIn($this);
    checklistBallot($election, 'Président', 2);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose']);

    $data = $this->browser->get(checklistUrl($election))->assertOk()->json('data');

    expect($data)->toBe([
        'status' => 'draft', 'can_schedule' => true,
        'counts' => ['ballots' => 1, 'candidates' => 2, 'voters' => 1, 'groups' => 0],
        'blockers' => [], 'warnings' => [],
    ]);
});

it('blocks with no ballot, a ballot without candidates and no voter, in a fixed order [FR-ELEC-04] (scenario 2)', function () {
    [$t, $election] = checklistSignedIn($this);

    $none = $this->browser->get(checklistUrl($election))->assertOk()->json('data');
    expect($none['can_schedule'])->toBeFalse()
        ->and(array_column($none['blockers'], 'code'))->toBe(['no_ballots', 'no_voters']);

    $empty = checklistBallot($election, 'Secrétaire', 0);
    $second = checklistBallot($election, 'Trésorier', 0);
    $data = $this->browser->get(checklistUrl($election))->assertOk()->json('data');

    expect(array_column($data['blockers'], 'code'))->toBe(['ballot_without_candidates', 'ballot_without_candidates', 'no_voters'])
        ->and($data['blockers'][0]['ballot'])->toBe(['id' => $empty, 'title' => 'Secrétaire'])
        ->and($data['blockers'][1]['ballot'])->toBe(['id' => $second, 'title' => 'Trésorier'])
        ->and($data['can_schedule'])->toBeFalse();
});

it('warns about a single candidate, a group ballot with no or few voters, and voters with no ballot [FR-ELEC-04, FR-BAL-06] (scenario 3)', function () {
    [$t, $election] = checklistSignedIn($this);
    $g6 = Accounts::plantGroup(['election' => $election, 'name' => '6e']);
    $g7 = Accounts::plantGroup(['election' => $election, 'name' => '7e']);
    $g8 = Accounts::plantGroup(['election' => $election, 'name' => '8e']);
    foreach (range(1, 3) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => "Sixième {$i}", 'group' => $g6]);
    }
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe 1']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe 2']);
    $single = checklistBallot($election, 'Un seul', 1, [$g6]);
    $few = checklistBallot($election, 'Six', 2, [$g6]);
    $none = checklistBallot($election, 'Huit', 2, [$g8]);

    $data = $this->browser->get(checklistUrl($election))->assertOk()->json('data');

    expect($data['can_schedule'])->toBeTrue()
        ->and($data['blockers'])->toBe([])
        ->and($data['warnings'])->toBe([
            ['code' => 'ballot_single_candidate', 'ballot' => ['id' => $single, 'title' => 'Un seul']],
            ['code' => 'group_ballot_no_voters', 'ballot' => ['id' => $none, 'title' => 'Huit']],
            ['code' => 'group_ballot_few_voters', 'ballot' => ['id' => $single, 'title' => 'Un seul'], 'count' => 3],
            ['code' => 'group_ballot_few_voters', 'ballot' => ['id' => $few, 'title' => 'Six'], 'count' => 3],
            ['code' => 'voters_without_ballot', 'count' => 2],
        ]);
});

it('does not count voters of a group no ballot covers as eligible when a general ballot exists [FR-BAL-05] (scenario 3)', function () {
    [$t, $election] = checklistSignedIn($this);
    $g = Accounts::plantGroup(['election' => $election, 'name' => '6e']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose', 'group' => $g]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe']);
    checklistBallot($election, 'Président', 2);

    $data = $this->browser->get(checklistUrl($election))->assertOk()->json('data');

    expect($data['warnings'])->toBe([]);
});

it('computes the list for an election that is not a draft, and says it cannot be scheduled [FR-ELEC-04] (scenario 4)', function (string $status) {
    [$t, $election] = checklistSignedIn($this, $status);
    checklistBallot($election, 'Président', 2);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose']);

    $data = $this->browser->get(checklistUrl($election))->assertOk()->json('data');

    expect($data)->toMatchArray(['status' => $status, 'can_schedule' => false, 'blockers' => []]);
})->with(['scheduled', 'open', 'closed']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 5)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(checklistUrl(CHECKLIST_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(checklistUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 [NFR-SEC-04] (scenarios 6, 7 and 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(checklistUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->other('POST', checklistUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(checklistUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
