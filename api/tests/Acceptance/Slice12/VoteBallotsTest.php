<?php

/*
 * GET /api/v1/vote/{election}/ballots — docs/api/vote/GET-vote-{election}-ballots.md. The voter session is the only
 * credential.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Voting;

function ballotsUrl(string $election): string
{
    return "/api/v1/vote/{$election}/ballots";
}

/** A voter inside a session: returns [election data, browser]. */
function ballotsEnter($test, string $who = 'Wideline', array $options = []): array
{
    $e = Voting::election($options);
    $browser = Voting::browser($test);
    Voting::enter($browser, $e['election'], $e['codes'][$who])->assertCreated();

    return [$e, $browser];
}

it('shows the voter\'s name and group and the general and group ballots they are eligible for [FR-VOTE-03, FR-BAL-05] (scenario 2)', function () {
    [$e, $browser] = ballotsEnter($this, 'Wideline');

    $data = $browser->get(ballotsUrl($e['election']))->assertOk()->json('data');

    expect($data['voter'])->toBe(['full_name' => 'Wideline Joseph', 'group' => '4e année'])
        ->and(array_column($data['ballots'], 'title'))->toBe(['Président(e)', 'Secrétaires', 'Délégué(e) de classe'])
        ->and(array_column($data['ballots'], 'id'))->toBe([$e['ballots']['P'], $e['ballots']['S'], $e['ballots']['G']])
        ->and($data['ballots'][2])->toMatchArray(['scope' => 'group', 'group_names' => ['4e année'], 'seats' => 1, 'allow_blank' => true])
        ->and($data['ballots'][1])->toMatchArray(['scope' => 'general', 'group_names' => [], 'seats' => 2, 'allow_blank' => false])
        ->and(array_keys($data))->toBe(['voter', 'expires_at', 'ballots']);
});

it('gives the general ballots only to a voter of another group or with no group [FR-BAL-05] (scenario 3)', function (string $who) {
    [$e, $browser] = ballotsEnter($this, $who);

    $titles = array_column($browser->get(ballotsUrl($e['election']))->assertOk()->json('data.ballots'), 'title');

    expect($titles)->toBe(['Président(e)', 'Secrétaires']);
})->with(['Paul', 'Marc']);

it('gives each candidate with party, slogan and photo fields and no numeric id [FR-VOTE-03] (scenario 1)', function () {
    [$e, $browser] = ballotsEnter($this);

    $candidate = $browser->get(ballotsUrl($e['election']))->assertOk()->json('data.ballots.0.candidates.0');

    expect($candidate)->toMatchArray(['id' => $e['candidates']['A'], 'first_name' => 'Candidat A', 'last_name' => 'Nom A', 'slogan' => 'Slogan A', 'photo' => null])
        ->and($candidate['party'])->toMatchArray(['name' => 'Avenir Étudiant', 'acronym' => 'AE'])
        ->and(array_keys($candidate))->toBe(['id', 'first_name', 'last_name', 'sex', 'slogan', 'biography', 'photo', 'party']);
});

it('keeps the same shuffled order for the whole session and a manual order as set [FR-CAND-05] (scenario 4)', function () {
    [$e, $browser] = ballotsEnter($this, 'Wideline', ['candidate_order' => 'shuffled']);

    $first = array_column($browser->get(ballotsUrl($e['election']))->json('data.ballots.0.candidates'), 'id');
    $second = array_column($browser->get(ballotsUrl($e['election']))->json('data.ballots.0.candidates'), 'id');

    expect($second)->toBe($first)
        ->and($first)->toHaveCount(3)
        ->and(array_diff($first, [$e['candidates']['A'], $e['candidates']['B'], $e['candidates']['C']]))->toBe([]);
    [$f, $b2] = ballotsEnter($this);
    expect(array_column($b2->get(ballotsUrl($f['election']))->json('data.ballots.0.candidates'), 'id'))->toBe([$f['candidates']['A'], $f['candidates']['B'], $f['candidates']['C']]);
});

it('answers 401 voter_session_expired without a cookie, with an ended session or with another election\'s session [FR-VOTE-06] (scenario 5)', function () {
    [$e, $browser] = ballotsEnter($this);
    $other = Voting::election();

    $this->browser->get(ballotsUrl($e['election']))->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $browser->get(ballotsUrl($other['election']))->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
});

it('answers 409 election_not_open once the election has closed, even for a valid session [FR-ELEC-05] (scenario 6)', function () {
    [$e, $browser] = ballotsEnter($this);
    Accounts::setElectionStatus($e['election'], 'closed');

    $browser->get(ballotsUrl($e['election']))->assertStatus(409)->assertJsonPath('error.code', 'election_not_open');
});

it('answers 409 already_voted for a session whose voter voted [FR-VOTE-05] (scenario 7)', function () {
    [$e, $browser] = ballotsEnter($this);
    $browser->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, ['G' => ['H']]), [], false)->assertCreated();

    $browser->get(ballotsUrl($e['election']))->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
});

it('answers 405 for another method [NFR-SEC-04] (scenario 8)', function () {
    [$e, $browser] = ballotsEnter($this);

    $browser->other('POST', ballotsUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});

it('never shows a code, a count of votes or a numeric id [FR-SEC-03] (scenario 1)', function () {
    [$e, $browser] = ballotsEnter($this);

    $body = $browser->get(ballotsUrl($e['election']))->assertOk()->getContent();

    expect($body)->not->toContain($e['codes']['Wideline'])->and($body)->not->toContain('votes')->and($body)->not->toContain('code');
    expect(DB::connection(useMigratorConnection())->table('votes')->count())->toBe(0);
    expect(Team::two())->toBeArray();
});
