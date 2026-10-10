<?php

/*
 * Candidates inside the ballots list, the cascades and the duplicate option (slice 06c):
 * docs/api/ballots/GET-elections-{election}-ballots.md, docs/api/parties/DELETE-parties-{party}.md,
 * docs/api/ballots/DELETE-ballots-{ballot}.md, docs/api/elections/POST-elections-{election}-duplicate.md 3d and 3e.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

/** A draft of A with the owner signed in. Returns [team, election]. */
function candListSignedIn($test): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('lists each ballot\'s candidates in order, with the documented fields and a real count [FR-CAND-02] (list scenario 1)', function () {
    [$t, $election] = candListSignedIn($this);
    $one = Accounts::plantBallot(['election' => $election, 'title' => 'Un', 'position' => 1]);
    $two = Accounts::plantBallot(['election' => $election, 'title' => 'Deux', 'position' => 2]);
    $party = Accounts::plantParty(['election' => $election]);
    $second = Accounts::plantCandidate(['ballot' => $one, 'first_name' => 'Second', 'position' => 2, 'party' => $party]);
    $first = Accounts::plantCandidate(['ballot' => $one, 'first_name' => 'Premier', 'position' => 1, 'sex' => 'male']);
    Accounts::plantCandidate(['ballot' => $two, 'first_name' => 'Ailleurs']);

    $response = $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk();

    $candidates = $response->json('data.0.candidates');
    expect($response->json('data.0.candidates_count'))->toBe(2)
        ->and($response->json('data.1.candidates_count'))->toBe(1)
        ->and(array_column($candidates, 'id'))->toBe([$first, $second])
        ->and($candidates[0])->toMatchArray(['ballot' => $one, 'party' => null, 'sex' => 'male', 'photo' => null, 'position' => 1])
        ->and($candidates[1]['party'])->toBe($party)
        ->and($candidates[0])->not->toHaveKeys(['ballot_id', 'party_id', 'election_id', 'institution_id', 'photo_file']);
});

it('never lists the candidates of another election or institution [FR-INST-05] (list scenario 1)', function () {
    [$t, $election] = candListSignedIn($this);
    $mine = Accounts::plantBallot(['election' => $election]);
    $other = Accounts::plantBallot(['election' => Accounts::plantElection(['institution' => $t['a']['owner']['institution']])]);
    $foreign = Accounts::plantBallot(['election' => Accounts::plantElection(['institution' => $t['b']['owner']['institution']])]);
    $kept = Accounts::plantCandidate(['ballot' => $mine]);
    Accounts::plantCandidate(['ballot' => $other]);
    Accounts::plantCandidate(['ballot' => $foreign]);

    $response = $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk();

    expect(array_column($response->json('data.0.candidates'), 'id'))->toBe([$kept]);
});

it('deletes a ballot\'s candidates with it [FR-BAL-01] (cascade)', function () {
    [$t, $election] = candListSignedIn($this);
    $ballot = Accounts::plantBallot(['election' => $election]);
    $kept = Accounts::plantBallot(['election' => $election, 'title' => 'Reste']);
    $gone = Accounts::plantCandidate(['ballot' => $ballot]);
    $stays = Accounts::plantCandidate(['ballot' => $kept]);

    $this->browser->delete("/api/v1/ballots/{$ballot}")->assertNoContent();

    expect(Accounts::candidateRow($gone))->toBeNull()
        ->and(Accounts::candidateRow($stays))->not->toBeNull();
});

it('clears the party of its candidates when a party is deleted, and deletes none [FR-CAND-01] (cascade)', function () {
    [$t, $election] = candListSignedIn($this);
    $ballot = Accounts::plantBallot(['election' => $election]);
    $party = Accounts::plantParty(['election' => $election]);
    $candidate = Accounts::plantCandidate(['ballot' => $ballot, 'party' => $party]);

    $this->browser->delete("/api/v1/parties/{$party}")->assertNoContent();

    $row = Accounts::candidateRow($candidate);
    expect($row)->not->toBeNull()->and($row['party_id'])->toBeNull();
    $this->browser->get("/api/v1/elections/{$election}/ballots")->assertOk()->assertJsonPath('data.0.candidates.0.party', null);
});

it('deletes the candidates with the election [FR-ELEC-03] (cascade)', function () {
    [$t, $election] = candListSignedIn($this);
    $id = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);

    $this->browser->delete("/api/v1/elections/{$election}")->assertNoContent();

    expect(Accounts::candidateRow($id))->toBeNull();
});

it('copies the candidates, in order, into the matching ballots with their parties mapped, when copy_candidates is true [FR-ELEC-06] (3d)', function () {
    [$t, $election] = candListSignedIn($this);
    $one = Accounts::plantBallot(['election' => $election, 'title' => 'Un', 'position' => 1]);
    $two = Accounts::plantBallot(['election' => $election, 'title' => 'Deux', 'position' => 2]);
    $party = Accounts::plantParty(['election' => $election, 'name' => 'Ensemble', 'colour' => '#C2410C']);
    Accounts::plantCandidate(['ballot' => $one, 'first_name' => 'A', 'last_name' => 'Un', 'sex' => 'female', 'party' => $party, 'slogan' => 'Mot', 'biography' => 'Bio', 'position' => 1]);
    Accounts::plantCandidate(['ballot' => $one, 'first_name' => 'B', 'last_name' => 'Deux', 'sex' => 'male', 'position' => 2]);
    Accounts::plantCandidate(['ballot' => $two, 'first_name' => 'C', 'last_name' => 'Trois', 'sex' => 'male', 'position' => 1]);

    $copy = $this->browser->post("/api/v1/elections/{$election}/duplicate", ['copy_candidates' => true])->assertCreated()->json('data.id');

    $ballots = Accounts::ballotRows($copy);
    $parties = Accounts::partyRows($copy);
    $inOne = Accounts::candidateRows($ballots[0]['uuid']);
    $inTwo = Accounts::candidateRows($ballots[1]['uuid']);
    expect($ballots)->toHaveCount(2)
        ->and(array_column($inOne, 'first_name'))->toBe(['A', 'B'])
        ->and(array_column($inTwo, 'first_name'))->toBe(['C'])
        ->and($inOne[0]['slogan'])->toBe('Mot')
        ->and($inOne[0]['biography'])->toBe('Bio')
        ->and($inOne[0]['sex'])->toBe('female')
        ->and($inOne[0]['party_id'])->toBe($parties[0]['id'])
        ->and($inOne[1]['party_id'])->toBeNull()
        ->and($inOne[0]['photo_file'])->toBeNull()
        ->and($inOne[0]['election_id'])->toBe(Accounts::electionRow($copy)['id']);
    // The source is untouched.
    expect(Accounts::candidateRows($one))->toHaveCount(2);
});

it('copies ballots and parties only when copy_candidates is absent or false [FR-ELEC-06] (3e)', function (array $body) {
    [$t, $election] = candListSignedIn($this);
    $ballot = Accounts::plantBallot(['election' => $election]);
    Accounts::plantParty(['election' => $election]);
    Accounts::plantCandidate(['ballot' => $ballot]);

    $copy = $this->browser->post("/api/v1/elections/{$election}/duplicate", $body)->assertCreated()->json('data.id');

    expect(Accounts::ballotRows($copy))->toHaveCount(1)
        ->and(Accounts::partyRows($copy))->toHaveCount(1)
        ->and(Accounts::candidateRows(Accounts::ballotRows($copy)[0]['uuid']))->toBe([]);
})->with([[[]], [['copy_candidates' => false]]]);

it('answers 422 when copy_candidates is not a boolean [FR-ELEC-06] (3d)', function () {
    [$t, $election] = candListSignedIn($this);

    $response = $this->browser->post("/api/v1/elections/{$election}/duplicate", ['copy_candidates' => 'maybe']);

    $response->assertStatus(422);
    expect($response->json('error.fields.copy_candidates'))->toContain('boolean');
});

it('deletes the copy\'s candidates without touching the source\'s [FR-ELEC-06] (3d)', function () {
    [$t, $election] = candListSignedIn($this);
    $kept = Accounts::plantCandidate(['ballot' => Accounts::plantBallot(['election' => $election])]);
    $copy = $this->browser->post("/api/v1/elections/{$election}/duplicate", ['copy_candidates' => true])->assertCreated()->json('data.id');

    $this->browser->delete("/api/v1/elections/{$copy}")->assertNoContent();

    expect(Accounts::candidateRow($kept))->not->toBeNull();
});
