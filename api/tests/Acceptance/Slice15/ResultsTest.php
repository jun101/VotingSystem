<?php

/*
 * GET /api/v1/elections/{election}/results — docs/api/results/GET-elections-{election}-results.md
 * Tenant suite, route api/v1/elections/{election}/results: another institution's election answers 404, the same as an
 * unknown one.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Results;
use Tests\Support\Team;
use Tests\Support\Turnout;

const RESULTS_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function resultsUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/results";
}

/** An owner of A signed in and a closed election "Conseil" of A. Returns [team, election]. */
function resultsSignedIn($test, string $status = 'closed'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status, 'title' => 'Conseil', 'closed_at' => '2026-10-16 15:00:00']);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

/** A ballot with candidates named after their letter, in that order. Returns [ballot, [letter => uuid]]. */
function resultsBallot(string $election, array $letters, array $options = []): array
{
    $ballot = Accounts::plantBallot($options + ['election' => $election, 'title' => 'Président(e)', 'seats' => 1]);
    $candidates = [];
    foreach ($letters as $letter) {
        $candidates[$letter] = Accounts::plantCandidate(['ballot' => $ballot, 'first_name' => "Candidat {$letter}", 'last_name' => "Nom {$letter}"]);
    }

    return [$ballot, $candidates];
}

function resultsOf($test, string $election): array
{
    return $test->browser->get(resultsUrl($election))->assertOk()->json('data');
}

it('counts the papers per candidate with percentages of the papers cast, blank included, and names the winner [FR-RES-02] (scenarios 1, 2, 5)', function () {
    [$t, $election] = resultsSignedIn($this);
    $party = Accounts::plantParty(['election' => $election, 'name' => 'Avenir Étudiant', 'acronym' => 'AE']);
    [$ballot, $c] = resultsBallot($election, ['A', 'B', 'C']);
    DB::connection(useMigratorConnection())->table('candidates')->where('uuid', $c['A'])->update(['party_id' => DB::connection(useMigratorConnection())->table('parties')->where('uuid', $party)->value('id')]);
    Results::voters($election, 11);
    Results::paper($ballot, [$c['B']], 3);
    Results::paper($ballot, [$c['A']], 5);
    Results::paper($ballot, [$c['C']], 1);
    Results::paper($ballot, [], 2);

    $data = resultsOf($this, $election);
    $b = $data['ballots'][0];

    expect($data['election'])->toBe(['id' => $election, 'title' => 'Conseil', 'status' => 'closed', 'closed_at' => '2026-10-16T15:00:00Z'])
        ->and(array_keys($b))->toBe(['id', 'title', 'seats', 'scope', 'groups', 'eligible', 'voted', 'cast', 'blank', 'candidates', 'winners', 'tie', 'check', 'note', 'second_round'])
        ->and($b)->toMatchArray(['id' => $ballot, 'title' => 'Président(e)', 'seats' => 1, 'scope' => 'general', 'groups' => [], 'eligible' => 11, 'voted' => 11, 'cast' => 11, 'blank' => 2, 'tie' => null, 'note' => null, 'second_round' => null])
        ->and(array_keys($b['candidates'][0]))->toBe(['id', 'first_name', 'last_name', 'party', 'votes', 'percentage'])
        ->and(array_map(fn ($x) => [$x['id'], $x['votes'], $x['percentage']], $b['candidates']))->toBe([[$c['A'], 5, 45.5], [$c['B'], 3, 27.3], [$c['C'], 1, 9.1]])
        ->and($b['candidates'][0]['party'])->toBe('Avenir Étudiant')
        ->and($b['candidates'][1]['party'])->toBeNull()
        ->and($b['winners'])->toBe([$c['A']])
        ->and($b['check'])->toBe(['recorded' => 11, 'expected' => 11, 'ok' => true]);
});

it('gives the seats to the best candidates, with percentages of papers that can add up above 100 [FR-RES-02] (scenario 3)', function () {
    [$t, $election] = resultsSignedIn($this);
    [$ballot, $c] = resultsBallot($election, ['A', 'B', 'C', 'D'], ['seats' => 2]);
    Results::voters($election, 12);
    Results::paper($ballot, [$c['A'], $c['B']], 3);
    Results::paper($ballot, [$c['A']], 7);
    Results::paper($ballot, [$c['B'], $c['C']], 2);

    $b = resultsOf($this, $election)['ballots'][0];

    expect($b['cast'])->toBe(12)
        ->and(array_map(fn ($x) => [$x['id'], $x['votes'], $x['percentage']], $b['candidates']))->toBe([[$c['A'], 10, 83.3], [$c['B'], 5, 41.7], [$c['C'], 2, 16.7], [$c['D'], 0, 0.0]])
        ->and($b['winners'])->toBe([$c['A'], $c['B']])
        ->and($b['tie'])->toBeNull();
});

it('reports a tie for the last seat and never breaks it [FR-RES-03] (scenario 4)', function (int $seats, array $votes, array $winners, ?array $tie) {
    [$t, $election] = resultsSignedIn($this);
    [$ballot, $c] = resultsBallot($election, ['A', 'B', 'C', 'D'], ['seats' => $seats]);
    $total = array_sum($votes);
    Results::voters($election, $total);
    foreach (array_values($votes) as $i => $count) {
        if ($count > 0) {
            Results::paper($ballot, [$c[['A', 'B', 'C', 'D'][$i]]], $count);
        }
    }

    $b = resultsOf($this, $election)['ballots'][0];
    $expectWinners = array_map(fn ($l) => $c[$l], $winners);
    $expectTie = $tie === null ? null : ['candidates' => array_map(fn ($l) => $c[$l], $tie['candidates']), 'seats_left' => $tie['seats_left']];

    expect($b['winners'])->toBe($expectWinners)->and($b['tie'])->toBe($expectTie);
})->with([
    'one seat, two tied' => [1, [5, 5, 1, 0], [], ['candidates' => ['A', 'B'], 'seats_left' => 1]],
    'two seats, tie for the second' => [2, [6, 3, 3, 1], ['A'], ['candidates' => ['B', 'C'], 'seats_left' => 1]],
    'two seats, three tied for two' => [2, [4, 4, 4, 0], [], ['candidates' => ['A', 'B', 'C'], 'seats_left' => 2]],
    'tie that does not touch the last seat' => [1, [5, 3, 3, 0], ['A'], null],
    'two seats, tie for the first but both fit' => [2, [4, 4, 1, 0], ['A', 'B'], null],
]);

it('lists the candidates tied on votes in the election\'s order [FR-RES-02] (scenario 4)', function () {
    [$t, $election] = resultsSignedIn($this);
    [$ballot, $c] = resultsBallot($election, ['A', 'B', 'C']);
    Results::voters($election, 6);
    Results::paper($ballot, [$c['C']], 3);
    Results::paper($ballot, [$c['A']], 3);

    $b = resultsOf($this, $election)['ballots'][0];

    expect(array_column($b['candidates'], 'id'))->toBe([$c['A'], $c['C'], $c['B']])
        ->and($b['tie']['candidates'])->toBe([$c['A'], $c['C']]);
});

it('names the groups of a group ballot and counts only its voters [FR-BAL-05, FR-SEC-04] (scenario 6)', function () {
    [$t, $election] = resultsSignedIn($this);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g5 = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    [$general, $gc] = resultsBallot($election, ['A', 'B'], ['title' => 'Général']);
    [$group, $hc] = resultsBallot($election, ['H', 'I'], ['title' => 'Délégués', 'groups' => [$g4]]);
    Results::voters($election, 3, $g4);
    Results::voters($election, 2, $g5);
    Results::paper($general, [$gc['A']], 5);
    Results::paper($group, [$hc['H']], 2);
    Results::paper($group, [$hc['I']], 1);

    $data = resultsOf($this, $election);
    [$gen, $grp] = $data['ballots'];

    expect($gen)->toMatchArray(['scope' => 'general', 'groups' => [], 'eligible' => 5, 'voted' => 5, 'cast' => 5])
        ->and($grp)->toMatchArray(['scope' => 'group', 'groups' => [['id' => $g4, 'name' => '4e année']], 'eligible' => 3, 'voted' => 3, 'cast' => 3])
        ->and($grp['winners'])->toBe([$hc['H']])
        ->and(json_encode($gen))->not->toContain('4e année')->not->toContain('5e année');
});

it('flags a ballot whose recorded papers differ from the voters who voted on it [FR-SEC-05] (scenario 7)', function () {
    [$t, $election] = resultsSignedIn($this);
    [$ballot, $c] = resultsBallot($election, ['A', 'B']);
    Results::voters($election, 2);
    Results::paper($ballot, [$c['A']], 3);

    $b = resultsOf($this, $election)['ballots'][0];

    expect($b['check'])->toBe(['recorded' => 3, 'expected' => 2, 'ok' => false]);
});

it('answers zeros and no winner for an election with no vote [FR-RES-02] (scenario 8)', function () {
    [$t, $election] = resultsSignedIn($this);
    [$ballot, $c] = resultsBallot($election, ['A', 'B']);
    Accounts::plantVoter(['election' => $election]);

    $b = resultsOf($this, $election)['ballots'][0];

    expect($b)->toMatchArray(['eligible' => 1, 'voted' => 0, 'cast' => 0, 'blank' => 0, 'winners' => [], 'tie' => null])
        ->and(array_column($b['candidates'], 'percentage'))->toBe([0.0, 0.0])
        ->and($b['check'])->toBe(['recorded' => 0, 'expected' => 0, 'ok' => true]);
});

it('answers 409 results_not_available for a draft, scheduled or open election, even for the owner [FR-SEC-03] (scenario 9)', function (string $status) {
    [$t, $election] = resultsSignedIn($this, $status);
    [$ballot, $c] = resultsBallot($election, ['A', 'B']);
    Results::paper($ballot, [$c['A']], 2);

    $response = $this->browser->get(resultsUrl($election))->assertStatus(409);

    expect($response->json('error.code'))->toBe('results_not_available')
        ->and($response->getContent())->not->toContain('"votes"');
})->with(['draft', 'scheduled', 'open']);

it('gives the same numbers for a published and an archived election [FR-RES-02] (scenario 1)', function (string $status) {
    [$t, $election] = resultsSignedIn($this, $status);
    [$ballot, $c] = resultsBallot($election, ['A', 'B']);
    Results::voters($election, 2);
    Results::paper($ballot, [$c['A']], 2);

    $b = resultsOf($this, $election)['ballots'][0];

    expect($b['candidates'][0]['votes'])->toBe(2)->and($b['winners'])->toBe([$c['A']]);
})->with(['published', 'archived']);

it('never reads vote_audit and puts no numeric id or voter in the answer [FR-SEC-09, FR-SEC-01] (scenario 1)', function () {
    [$t, $election] = resultsSignedIn($this);
    [$ballot, $c] = resultsBallot($election, ['A', 'B']);
    $voters = Results::voters($election, 2);
    Results::paper($ballot, [$c['A']], 2);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $response = $this->browser->get(resultsUrl($election))->assertOk();

    expect(implode("\n", $queries))->not->toContain('vote_audit')
        ->and($response->getContent())->not->toContain('election_id')->not->toContain('ballot_id')->not->toContain('institution_id')
        ->and($response->getContent())->not->toContain($voters[0]);
});

it('lets a manager read the results [FR-RES-02] (scenario 1)', function () {
    [$t, $election] = resultsSignedIn($this);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get(resultsUrl($election))->assertOk();
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 10)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'status' => 'closed']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(resultsUrl(RESULTS_UNKNOWN)));
    expect($unknown['status'])->toBe(404)->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(resultsUrl($target))))->toBe($unknown);
    }
});

it('answers 401, 403 and 405 as every read [NFR-SEC-04] (scenario 11)', function () {
    [$t, $election] = resultsSignedIn($this);
    $this->browser->forgetCookies();

    $this->browser->get(resultsUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(resultsUrl($election), [])->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(resultsUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});
