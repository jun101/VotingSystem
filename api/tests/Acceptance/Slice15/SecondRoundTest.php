<?php

/*
 * POST /api/v1/elections/{election}/second-round — docs/api/results/POST-elections-{election}-second-round.md
 * Tenant suite, route api/v1/elections/{election}/second-round: another institution's election answers 404, the same as
 * an unknown one.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Results;
use Tests\Support\Team;
use Tests\Support\Turnout;

const ROUND_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function roundUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/second-round";
}

/**
 * A closed election "Conseil des élèves" of A with groups 4e and 5e, five voters (3 and 2) with participations, a
 * general ballot of three candidates (two seats: A 6, B 3, C 3 => tie B and C for one seat) and a ballot with a clear
 * winner. Returns [team, election, tiedBallot, clearBallot, candidates, groups].
 */
function roundSignedIn($test, array $options = []): array
{
    $t = Team::two();
    $election = Accounts::plantElection($options + ['institution' => $t['a']['owner']['institution'], 'status' => 'closed', 'title' => 'Conseil des élèves', 'language' => 'fr', 'candidate_order' => 'shuffled', 'results_display' => 'winners']);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g5 = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    $party = Accounts::plantParty(['election' => $election, 'name' => 'Avenir Étudiant', 'acronym' => 'AE']);
    $tied = Accounts::plantBallot(['election' => $election, 'title' => 'Secrétaires', 'seats' => 2, 'allow_blank' => false, 'description' => 'Deux postes']);
    $clear = Accounts::plantBallot(['election' => $election, 'title' => 'Président(e)', 'seats' => 1]);
    $c = [];
    foreach (['A', 'B', 'C', 'D'] as $letter) {
        $c[$letter] = Accounts::plantCandidate(['ballot' => $tied, 'first_name' => "Candidat {$letter}", 'last_name' => "Nom {$letter}", 'party' => $letter === 'B' ? $party : null, 'slogan' => "Slogan {$letter}", 'biography' => "Bio {$letter}", 'photo_file' => null]);
    }
    foreach (['X', 'Y'] as $letter) {
        $c[$letter] = Accounts::plantCandidate(['ballot' => $clear, 'first_name' => "Candidat {$letter}", 'last_name' => "Nom {$letter}"]);
    }
    Results::voters($election, 3, $g4);
    Results::voters($election, 2, $g5);
    foreach ([['A', 6], ['B', 3], ['C', 3]] as [$letter, $count]) {
        Results::paper($tied, [$c[$letter]], $count);
    }
    Results::paper($clear, [$c['X']], 4);
    Results::paper($clear, [$c['Y']], 1);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $tied, $clear, $c, ['4e' => $g4, '5e' => $g5]];
}

it('creates a draft election holding only the tied ballot with the tied candidates and the same voters and groups [FR-RES-09] (scenarios 1, 2)', function () {
    [$t, $election, $tied, $clear, $c, $g] = roundSignedIn($this);
    $source = Accounts::electionRow($election);

    $response = $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertCreated();
    $new = $response->json('data');
    $row = Accounts::electionRow($new['id']);

    expect($new)->toMatchArray(['status' => 'draft', 'title' => 'Second tour – Conseil des élèves', 'parent' => $election, 'ballots_count' => 1, 'voters_count' => 5, 'language' => 'fr', 'candidate_order' => 'shuffled', 'results_display' => 'winners', 'timezone' => $source['timezone']])
        ->and($new['id'])->not->toBe($election)
        ->and($row['parent_election_id'])->toBe($source['id'])
        ->and($row['institution_id'])->toBe($source['institution_id'])
        ->and($row['starts_at'])->toBe($source['starts_at'])
        ->and($row['ends_at'])->toBe($source['ends_at']);

    $ballots = Accounts::ballotRows($new['id']);
    expect($ballots)->toHaveCount(1)
        ->and($ballots[0])->toMatchArray(['title' => 'Secrétaires', 'seats' => 1, 'allow_blank' => 0])
        ->and(array_column(Accounts::candidateRows($ballots[0]['uuid']), 'last_name'))->toBe(['Nom B', 'Nom C']);
    $candidates = Accounts::candidateRows($ballots[0]['uuid']);
    expect($candidates[0])->toMatchArray(['first_name' => 'Candidat B', 'slogan' => 'Slogan B', 'biography' => 'Bio B', 'photo_file' => null])
        ->and(array_column(Accounts::groupRows($new['id']), 'name'))->toEqualCanonicalizing(['4e année', '5e année'])
        ->and(Accounts::voterRows($new['id']))->toHaveCount(5);
});

it('shows the second round on the ballot of the first election\'s results [FR-RES-09] (scenario 1)', function () {
    [$t, $election, $tied, $clear] = roundSignedIn($this);
    $new = $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertCreated()->json('data.id');

    $ballots = $this->browser->get("/api/v1/elections/{$election}/results")->assertOk()->json('data.ballots');
    $by = array_column($ballots, 'second_round', 'id');

    expect($by[$tied])->toBe($new)->and($by[$clear])->toBeNull();
});

it('copies no code, no vote and no participation and leaves the first election untouched [FR-SEC-01] (scenario 1)', function () {
    [$t, $election, $tied] = roundSignedIn($this);
    foreach (Accounts::voterRows($election) as $voter) {
        Accounts::plantCredential(['voter' => $voter['uuid']]);
    }
    $before = [Voting_counts($election), count(Accounts::credentialRows($election)), Accounts::electionRow($election)['status']];

    $new = $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertCreated()->json('data.id');

    expect(Voting_counts($new))->toBe([0, 0, 0])
        ->and(Accounts::credentialRows($new))->toBe([])
        ->and([Voting_counts($election), count(Accounts::credentialRows($election)), Accounts::electionRow($election)['status']])->toBe($before)
        ->and(count(Accounts::ballotRows($election)))->toBe(2);
});

function Voting_counts(string $election): array
{
    $db = DB::connection(useMigratorConnection());
    $id = $db->table('elections')->where('uuid', $election)->value('id');

    return [
        $db->table('participations')->where('election_id', $id)->count(),
        $db->table('votes')->where('election_id', $id)->count(),
        $db->table('vote_audit')->where('election_id', $id)->count(),
    ];
}

it('keeps the groups of a group ballot and cuts a long title to 200 characters [FR-RES-09] (scenarios 1, 2)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'published', 'title' => str_repeat('T', 200)]);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Délégués', 'groups' => [$g4]]);
    $a = Accounts::plantCandidate(['ballot' => $ballot]);
    $b = Accounts::plantCandidate(['ballot' => $ballot]);
    Results::voters($election, 2, $g4);
    Results::paper($ballot, [$a]);
    Results::paper($ballot, [$b]);
    Team::signIn($this, $t['a']['owner']);

    $new = $this->browser->post(roundUrl($election), ['ballot' => $ballot])->assertCreated()->json('data');

    expect(mb_strlen($new['title']))->toBe(200)->and(str_starts_with($new['title'], 'Second tour – '))->toBeTrue();
    $copied = Accounts::ballotRows($new['id'])[0];
    expect(Accounts::ballotGroups($copied['uuid']))->toHaveCount(1);
});

it('says Second round for an English election [FR-RES-09] (scenario 2)', function () {
    [$t, $election, $tied] = roundSignedIn($this, ['language' => 'en', 'title' => 'Student council']);

    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertCreated()->assertJsonPath('data.title', 'Second round – Student council');
});

it('answers 409 ballot_not_tied for a ballot with a clear result [FR-RES-09] (scenario 3)', function () {
    [$t, $election, $tied, $clear] = roundSignedIn($this);

    $this->browser->post(roundUrl($election), ['ballot' => $clear])->assertStatus(409)->assertJsonPath('error.code', 'ballot_not_tied');

    expect(Accounts::electionRows($t['a']['owner']['institution']))->toHaveCount(1);
});

it('allows one second round per ballot [FR-RES-09] (scenario 4)', function () {
    [$t, $election, $tied] = roundSignedIn($this);
    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertCreated();

    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertStatus(409)->assertJsonPath('error.code', 'second_round_exists');

    expect(count(Accounts::electionRows($t['a']['owner']['institution'])))->toBe(2);
});

it('answers 422 for a ballot of another election, an unknown one or no ballot [FR-RES-09] (scenario 5)', function () {
    [$t, $election, $tied] = roundSignedIn($this);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'closed']);
    $other = Accounts::plantBallot(['election' => $otherElection]);

    foreach ([['ballot' => $other], ['ballot' => ROUND_UNKNOWN], ['ballot' => 'not-a-uuid'], []] as $body) {
        $this->browser->post(roundUrl($election), $body)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }
});

it('answers 409 results_not_available for a draft, scheduled or open election [FR-SEC-03] (scenario 6)', function (string $status) {
    [$t, $election, $tied] = roundSignedIn($this, ['status' => $status]);

    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertStatus(409)->assertJsonPath('error.code', 'results_not_available');
})->with(['draft', 'scheduled', 'open']);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 7)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'closed']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->post(roundUrl(ROUND_UNKNOWN), ['ballot' => ROUND_UNKNOWN]));
    expect($unknown['status'])->toBe(404);

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(roundUrl($target), ['ballot' => ROUND_UNKNOWN])))->toBe($unknown);
    }
});

it('answers 401, 403, 419, 405 and 400 as every write, and lets a manager create it [NFR-SEC-04] (scenario 8)', function () {
    [$t, $election, $tied] = roundSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(roundUrl($election), ['ballot' => $tied], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', roundUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Team::signIn($this, $t['a']['manager']);
    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertCreated();
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 20 requests an hour from one user [NFR-SEC-05] (scenario 8)', function () {
    [$t, $election, $tied] = roundSignedIn($this);
    foreach (range(1, 20) as $i) {
        $this->browser->post(roundUrl($election), [])->assertStatus(422);
    }

    $this->browser->post(roundUrl($election), ['ballot' => $tied])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
