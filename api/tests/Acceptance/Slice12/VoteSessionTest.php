<?php

/*
 * POST and DELETE /api/v1/vote/{election}/session — docs/api/vote/POST-vote-{election}-session.md and
 * DELETE-vote-{election}-session.md. Public: the code is the credential.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Voting;

const VOTE_SESSION_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function voteSessionUrl(string $election): string
{
    return "/api/v1/vote/{$election}/session";
}

it('opens a session with a valid code and sets a cookie that script and other sites cannot use [FR-VOTE-06] (scenario 1)', function () {
    $e = Voting::election();

    $response = Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertCreated();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'vote_session');
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('strict')
        ->and($cookie->getPath())->toBe('/api/v1/vote')
        ->and(strlen($cookie->getValue()))->toBeGreaterThanOrEqual(43)
        ->and($response->json('data'))->toHaveKey('expires_at')
        ->and(array_keys($response->json('data')))->toBe(['expires_at']);
    expect(Carbon\Carbon::parse($response->json('data.expires_at'))->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();
});

it('accepts the code in lower case, without the hyphen and with spaces [FR-VOTE-01] (scenario 2)', function (string $shape) {
    $e = Voting::election();
    $code = $e['codes']['Wideline'];
    $typed = match ($shape) {
        'lower' => strtolower($code),
        'no hyphen' => str_replace('-', '', $code),
        'spaces' => ' '.substr($code, 0, 3).' '.substr($code, 3).' ',
    };

    Voting::enter($this->browser, $e['election'], $typed)->assertCreated();
})->with(['lower', 'no hyphen', 'spaces']);

it('answers the same 401 invalid_code for an unknown code, another election\'s code, a malformed one and a replaced one [FR-VOTE-01] (scenario 3)', function () {
    $e = Voting::election();
    $other = Voting::election();
    $old = $e['codes']['Paul'];
    // The voter was reissued: the old code no longer matches.
    DB::connection(useMigratorConnection())->table('credentials')->where('voter_id', DB::connection(useMigratorConnection())->table('voters')->where('uuid', $e['voters']['Paul'])->value('id'))->update(['code_hash' => Codes::hash(Codes::fresh())]);
    $reference = null;

    foreach ([Codes::fresh(), $other['codes']['Wideline'], 'not a code', '', str_repeat('A', 64), $old] as $typed) {
        $response = Voting::enter($this->browser, $e['election'], $typed);
        $shape = Tests\Support\Team::shape($response);
        if ($typed === '') {
            expect($response->getStatusCode())->toBe(422);
            continue;
        }
        $reference ??= $shape;
        expect($shape['status'])->toBe(401)
            ->and(json_decode($shape['body'], true)['error']['code'])->toBe('invalid_code')
            ->and($shape)->toBe($reference);
    }
});

it('answers 409 already_voted for a voter who voted [FR-VOTE-05] (scenario 4)', function () {
    $e = Voting::election();
    $voter = DB::connection(useMigratorConnection())->table('voters')->where('uuid', $e['voters']['Wideline'])->first();
    DB::connection(useMigratorConnection())->table('participations')->insert(['election_id' => $voter->election_id, 'voter_id' => $voter->id, 'voted_at' => now('UTC')->toDateTimeString()]);

    Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
});

it('answers 409 election_not_open with the phase before the start, after the end and for a status that is not open [FR-VOTE-02, FR-ELEC-05] (scenario 5)', function (string $status, string $starts, string $ends, string $phase) {
    $e = Voting::election(['status' => $status, 'starts_at' => now('UTC')->modify($starts)->toDateTimeString(), 'ends_at' => now('UTC')->modify($ends)->toDateTimeString()]);

    $response = Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertStatus(409);

    expect($response->json('error.code'))->toBe('election_not_open')
        ->and($response->json('error.phase'))->toBe($phase);
})->with([
    'scheduled' => ['scheduled', '+1 day', '+3 days', 'before'],
    'open before its start' => ['open', '+1 hour', '+3 days', 'before'],
    'open past its end' => ['open', '-3 days', '-1 minute', 'closed'],
    'closed' => ['closed', '-3 days', '+1 day', 'closed'],
    'published' => ['published', '-3 days', '-1 day', 'closed'],
]);

it('answers 422 for a body without a code [FR-VOTE-01] (scenario 6)', function () {
    $e = Voting::election();

    $this->browser->post(voteSessionUrl($e['election']), [], [], false)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->browser->post(voteSessionUrl($e['election']), ['code' => ['x']], [], false)->assertStatus(422);
});

it('answers 404 for a draft, an unknown election or a value that is not a UUID [FR-VOTE-01] (scenario 7)', function () {
    $e = Voting::election(['status' => 'draft']);

    foreach ([$e['election'], VOTE_SESSION_UNKNOWN, 'not-a-uuid'] as $target) {
        Voting::enter(Voting::browser($this), $target, $e['codes']['Wideline'])->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }
});

it('replaces the session of a browser that opens a second one [FR-VOTE-06] (scenario 8)', function () {
    $e = Voting::election();
    Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    Voting::enter($this->browser, $e['election'], $e['codes']['Paul'])->assertCreated();

    $name = $this->browser->get("/api/v1/vote/{$e['election']}/ballots")->assertOk()->json('data.voter.full_name');

    expect($name)->toBe('Paul Étienne');
});

it('locks out an address after 30 wrong codes in 10 minutes for one election, not another election, and still refuses a valid code [NFR-SEC-05, FR-VOTE-08] (scenario 9)', function () {
    $e = Voting::election();
    $other = Voting::election();
    $browser = Voting::browser($this)->fromAddress('10.20.30.40');

    foreach (range(1, 30) as $i) {
        Voting::enter($browser, $e['election'], Codes::fresh())->assertStatus(401);
    }

    Voting::enter($browser, $e['election'], Codes::fresh())->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertStatus(429);
    Voting::enter($browser, $other['election'], $other['codes']['Wideline'])->assertCreated();
    Voting::enter(Voting::browser($this)->fromAddress('10.20.30.41'), $e['election'], $e['codes']['Wideline'])->assertCreated();
});

it('does not count valid codes against the limit, a whole class behind one address can enter [FR-VOTE-08] (scenario 9)', function () {
    $e = Voting::election();
    $browser = Voting::browser($this)->fromAddress('10.20.30.50');

    foreach (range(1, 40) as $i) {
        Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    }
});

it('limits an election to 300 wrong codes an hour across addresses [NFR-SEC-05] (scenario 9)', function () {
    $e = Voting::election();

    foreach (range(1, 300) as $i) {
        $browser = Voting::browser($this)->fromAddress('10.'.intdiv($i, 250).'.'.intdiv($i, 25).'.'.($i % 25 + 1));
        Voting::enter($browser, $e['election'], Codes::fresh())->assertStatus(401);
    }

    Voting::enter(Voting::browser($this)->fromAddress('10.99.99.99'), $e['election'], Codes::fresh())->assertStatus(429);
});

it('answers 405 for another method [NFR-SEC-04] (scenario 10)', function () {
    $e = Voting::election();

    $this->browser->other('GET', voteSessionUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});

it('ends a session with DELETE and the session can no longer be used [FR-VOTE-06] (scenarios 1, 2)', function () {
    $e = Voting::election();
    Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $this->browser->get("/api/v1/vote/{$e['election']}/ballots")->assertOk();

    $this->browser->delete(voteSessionUrl($e['election']), [], false)->assertNoContent();

    $this->browser->get("/api/v1/vote/{$e['election']}/ballots")->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
    $this->browser->delete(voteSessionUrl($e['election']), [], false)->assertNoContent();
});

it('forgets the session after 15 minutes [FR-VOTE-06] (scenario 1)', function () {
    $e = Voting::election();
    Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertCreated();

    $this->travel(16)->minutes();

    $this->browser->get("/api/v1/vote/{$e['election']}/ballots")->assertStatus(401)->assertJsonPath('error.code', 'voter_session_expired');
});

it('writes nothing in the database on a session [FR-SEC-01] (scenario 1)', function () {
    $e = Voting::election();

    Voting::enter($this->browser, $e['election'], $e['codes']['Wideline'])->assertCreated();

    expect(Voting::counts($e['election']))->toBe([0, 0, 0, 0]);
});

it('never puts the code or the token in the answer or in the logs [NFR-SEC-02] (scenario 1)', function () {
    $before = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $before[$file] = filesize($file);
    }
    $e = Voting::election();
    $code = $e['codes']['Wideline'];

    $response = Voting::enter($this->browser, $e['election'], $code)->assertCreated();
    Voting::enter(Voting::browser($this), $e['election'], Codes::fresh());

    $logged = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $logged .= (string) substr((string) file_get_contents($file), $before[$file] ?? 0);
    }
    $token = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'vote_session')->getValue();
    expect($response->getContent())->not->toContain($code)->and($response->getContent())->not->toContain($token)
        ->and($logged)->not->toContain($code)->and($logged)->not->toContain(str_replace('-', '', $code))->and($logged)->not->toContain($token);
    expect(Accounts::electionRow($e['election']))->not->toBeNull();
});
