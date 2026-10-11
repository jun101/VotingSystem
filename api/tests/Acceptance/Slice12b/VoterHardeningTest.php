<?php

/*
 * POST /api/v1/vote/{election}/session, slice 12b scenarios 9, 11 and 12 — docs/api/vote/POST-vote-{election}-session.md
 * and docs/slices/12b-voter-hardening.md. The slice 12 scenarios stay in Slice12/VoteSessionTest.php.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Codes;
use Tests\Support\Voting;

function hardeningUrl(string $election, string $path = 'session'): string
{
    return "/api/v1/vote/{$election}/{$path}";
}

/** Counts 300 wrong codes for the election, from 300 different addresses (so no address is locked out). */
function hardeningFill($test, string $election): void
{
    foreach (range(1, 300) as $i) {
        $browser = Voting::browser($test)->fromAddress('10.'.intdiv($i, 250).'.'.intdiv($i, 25).'.'.($i % 25 + 1));
        Voting::enter($browser, $election, Codes::fresh())->assertStatus(401);
    }
}

it('lets a valid code open a session once 300 wrong codes are counted for the election, and still refuses wrong ones [FR-VOTE-08, NFR-SEC-05] (scenarios 9, 11)', function () {
    $e = Voting::election();
    hardeningFill($this, $e['election']);

    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.1'), $e['election'], Codes::fresh())->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.2'), $e['election'], $e['codes']['Wideline'])->assertCreated();
    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.3'), $e['election'], $e['codes']['Paul'])->assertCreated();
});

it('keeps the order of answers for a valid code once the election limit is reached: already_voted and election_not_open still win [FR-VOTE-05] (scenario 11)', function () {
    $e = Voting::election();
    hardeningFill($this, $e['election']);
    $db = DB::connection(useMigratorConnection());
    $voter = $db->table('voters')->where('uuid', $e['voters']['Marc'])->first();
    $db->table('participations')->insert(['election_id' => $voter->election_id, 'voter_id' => $voter->id, 'voted_at' => now('UTC')->toDateTimeString()]);

    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.4'), $e['election'], $e['codes']['Marc'])->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
});

it('does not give the guessing back: a wrong code is not counted twice and a valid code does not reset the count [NFR-SEC-05] (scenario 11)', function () {
    $e = Voting::election();
    hardeningFill($this, $e['election']);
    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.5'), $e['election'], $e['codes']['Wideline'])->assertCreated();

    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.6'), $e['election'], Codes::fresh())->assertStatus(429);
});

it('still locks out an address after 30 wrong codes, valid code included, when the election limit is not reached [NFR-SEC-05] (scenario 9)', function () {
    $e = Voting::election();
    $browser = Voting::browser($this)->fromAddress('10.20.30.60');
    foreach (range(1, 30) as $i) {
        Voting::enter($browser, $e['election'], Codes::fresh())->assertStatus(401);
    }

    Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertStatus(429);
});

it('ends the first session of a voter when the same voter opens a second one in another browser [FR-VOTE-06] (scenario 12)', function () {
    $e = Voting::election();
    $first = Voting::browser($this);
    $second = Voting::browser($this);
    Voting::enter($first, $e['election'], $e['codes']['Paul'])->assertCreated();
    $first->get(hardeningUrl($e['election'], 'ballots'))->assertOk();

    Voting::enter($second, $e['election'], $e['codes']['Paul'])->assertCreated();

    $first->get(hardeningUrl($e['election'], 'ballots'))->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $first->post(hardeningUrl($e['election'], 'submit'), Voting::body($e), [], false)->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $second->get(hardeningUrl($e['election'], 'ballots'))->assertOk()->assertJsonPath('data.voter.full_name', 'Paul Étienne');
    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
});

it('lets only the newest session of a voter vote, once [FR-VOTE-05, FR-VOTE-06] (scenario 12)', function () {
    $e = Voting::election();
    $first = Voting::browser($this);
    $second = Voting::browser($this);
    Voting::enter($first, $e['election'], $e['codes']['Paul'])->assertCreated();
    Voting::enter($second, $e['election'], $e['codes']['Paul'])->assertCreated();

    $second->post(hardeningUrl($e['election'], 'submit'), Voting::body($e), [], false)->assertCreated();

    expect(Voting::counts($e['election'])[0])->toBe(1);
    $first->post(hardeningUrl($e['election'], 'submit'), Voting::body($e), [], false)->assertStatus(401);
});

it('does not touch the session of another voter [FR-VOTE-06] (scenario 12)', function () {
    $e = Voting::election();
    $paul = Voting::browser($this);
    $marc = Voting::browser($this);
    Voting::enter($paul, $e['election'], $e['codes']['Paul'])->assertCreated();
    Voting::enter($marc, $e['election'], $e['codes']['Marc'])->assertCreated();

    Voting::enter(Voting::browser($this), $e['election'], $e['codes']['Paul'])->assertCreated();

    $marc->get(hardeningUrl($e['election'], 'ballots'))->assertOk()->assertJsonPath('data.voter.full_name', 'Marc Sans-groupe');
});

it('keeps a browser that enters the same code again in a working session [FR-VOTE-06] (scenarios 8, 12)', function () {
    $e = Voting::election();
    Voting::enter($this->browser, $e['election'], $e['codes']['Paul'])->assertCreated();
    Voting::enter($this->browser, $e['election'], $e['codes']['Paul'])->assertCreated();

    $this->browser->get(hardeningUrl($e['election'], 'ballots'))->assertOk();
});

it('does not bring an ended session back when the newer one ends [FR-VOTE-06] (scenario 12)', function () {
    $e = Voting::election();
    $first = Voting::browser($this);
    $second = Voting::browser($this);
    Voting::enter($first, $e['election'], $e['codes']['Paul'])->assertCreated();
    Voting::enter($second, $e['election'], $e['codes']['Paul'])->assertCreated();

    $second->delete(hardeningUrl($e['election']), [], false)->assertNoContent();

    $first->get(hardeningUrl($e['election'], 'ballots'))->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $second->get(hardeningUrl($e['election'], 'ballots'))->assertStatus(401);
});
