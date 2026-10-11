<?php

/*
 * GET /api/v1/public/elections/{election}/results — docs/api/publication/GET-public-elections-{election}-results.md
 * Public route api/v1/public/elections/{election}/results: no sign-in; anything that is not published answers 404, the
 * same as an unknown election.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Publication;
use Tests\Support\Team;

const PUBLIC_RESULTS_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function publicResultsUrl(string $uuid): string
{
    return "/api/v1/public/elections/{$uuid}/results";
}

function publishedElection(array $options = [], int $a = 5, int $b = 3, int $blank = 2): array
{
    $t = Team::two();
    $e = Publication::election($t['a']['owner']['institution'], 'published', $a, $b, $blank, $options);
    Accounts::updateInstitution($t['a']['owner']['institution'], ['name' => 'Collège Étoile du Matin']);

    return [$t, $e];
}

it('gives the full counts to anyone without signing in [FR-RES-04] (scenario 1)', function () {
    [$t, $e] = publishedElection();

    $response = (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe(['id', 'title', 'language', 'timezone', 'starts_at', 'ends_at', 'published_at', 'display', 'institution', 'turnout', 'ballots'])
        ->and($data)->toMatchArray(['id' => $e['election'], 'title' => 'Conseil des élèves 2026', 'language' => 'fr', 'display' => 'full', 'published_at' => '2026-10-17T14:00:00Z'])
        ->and($data['institution'])->toMatchArray(['name' => 'Collège Étoile du Matin', 'logo' => null])
        ->and(array_keys($data['institution']))->toBe(['id', 'name', 'logo'])
        ->and($data['turnout'])->toBe(['eligible' => 10, 'voted' => 10, 'percentage' => 100.0])
        ->and($data['ballots'])->toHaveCount(2);
    $p = $data['ballots'][0];
    expect(array_keys($p))->toBe(['id', 'title', 'seats', 'groups', 'cast', 'blank', 'candidates', 'winners', 'tie', 'note'])
        ->and($p)->toMatchArray(['id' => $e['ballots']['P'], 'title' => 'Président(e)', 'seats' => 1, 'groups' => [], 'cast' => 10, 'blank' => 2, 'tie' => null, 'note' => null])
        ->and(array_keys($p['candidates'][0]))->toBe(['id', 'first_name', 'last_name', 'party', 'photo', 'votes', 'percentage'])
        ->and(array_map(fn ($c) => [$c['id'], $c['votes'], $c['percentage']], $p['candidates']))->toBe([[$e['candidates']['A'], 5, 50.0], [$e['candidates']['B'], 3, 30.0]])
        ->and($p['candidates'][0]['party'])->toBe('Avenir Étudiant')
        ->and($p['winners'])->toBe([$e['candidates']['A']]);
    expect($data['ballots'][1]['groups'])->toBe(['4e année']);
});

it('shows only the turnout, the winners and the tied candidates when the display is winners [FR-RES-08] (scenario 2)', function () {
    [$t, $e] = publishedElection(['results_display' => 'winners'], 4, 4, 1);

    $data = (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertOk()->json('data');
    $p = $data['ballots'][0];

    expect($data['display'])->toBe('winners')
        ->and($data['turnout']['voted'])->toBe(9)
        ->and(array_keys($p))->toBe(['id', 'title', 'seats', 'groups', 'candidates', 'winners', 'tie', 'note'])
        ->and($p['tie']['candidates'])->toHaveCount(2)
        ->and($p['winners'])->toBe([])
        ->and(array_keys($p['candidates'][0]))->toBe(['id', 'first_name', 'last_name', 'party', 'photo']);
    $json = json_encode($data);
    expect($json)->not->toContain('"votes"')->not->toContain('"percentage":50')->not->toContain('"blank"')->not->toContain('"cast"');
});

it('lists only the winner of a clear ballot in the winners display [FR-RES-08] (scenario 2)', function () {
    [$t, $e] = publishedElection(['results_display' => 'winners']);

    $p = (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertOk()->json('data.ballots.0');

    expect(array_column($p['candidates'], 'id'))->toBe([$e['candidates']['A']])
        ->and($p['winners'])->toBe([$e['candidates']['A']]);
});

it('shows a tie with its candidates in the full display [FR-RES-03] (scenario 3)', function () {
    [$t, $e] = publishedElection([], 4, 4, 0);

    $p = (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertOk()->json('data.ballots.0');

    expect($p['winners'])->toBe([])
        ->and($p['tie'])->toBe(['candidates' => [$e['candidates']['A'], $e['candidates']['B']], 'seats_left' => 1]);
});

it('shows the note of a ballot [FR-RES-03] (scenario 1)', function () {
    [$t, $e] = publishedElection();
    DB::connection(useMigratorConnection())->table('ballots')->where('uuid', $e['ballots']['P'])->update(['result_note' => "Un grand merci.\nÀ l'année prochaine."]);

    $note = (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertOk()->json('data.ballots.0.note');

    expect($note)->toBe("Un grand merci.\nÀ l'année prochaine.");
});

it('answers the same 404 for every status but published, an unknown election and a value that is not a UUID [FR-SEC-03] (scenario 4)', function () {
    $t = Team::two();
    $anonymous = new AuthClient($this);
    $unknown = Team::shape($anonymous->get(publicResultsUrl(PUBLIC_RESULTS_UNKNOWN)));
    expect($unknown['status'])->toBe(404)->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach (['draft', 'scheduled', 'open', 'closed', 'archived'] as $status) {
        $e = Publication::election($t['a']['owner']['institution'], $status);
        expect(Team::shape($anonymous->get(publicResultsUrl($e['election']))), $status)->toBe($unknown);
    }
    foreach (['not-a-uuid', '12'] as $target) {
        expect(Team::shape($anonymous->get(publicResultsUrl($target))))->toBe($unknown);
    }
});

it('answers 404 for a suspended institution [FR-INST-06] (scenario 5)', function () {
    [$t, $e] = publishedElection();
    Accounts::suspend($t['a']['owner']['institution']);

    (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertStatus(404);
});

it('gives no numeric id, no voter and no check, never reads vote_audit, and sets no cookie [FR-SEC-09, NFR-SEC-08] (scenario 1)', function () {
    [$t, $e] = publishedElection();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $response = (new AuthClient($this))->get(publicResultsUrl($e['election']))->assertOk();

    expect(implode("\n", $queries))->not->toContain('vote_audit')
        ->and($response->getContent())->not->toContain('election_id')->not->toContain('ballot_id')->not->toContain('institution_id')->not->toContain('"check"')->not->toContain('voter')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->getCookies())->toBe([]);
});

it('answers 429 above 120 requests a minute from one address for an election, and 405 for another method [NFR-SEC-05] (scenarios 6, 7)', function () {
    [$t, $e] = publishedElection();
    $anonymous = (new AuthClient($this))->fromAddress('10.30.30.30');
    $anonymous->other('POST', publicResultsUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');

    foreach (range(1, 120) as $i) {
        $anonymous->get(publicResultsUrl($e['election']))->assertOk();
    }
    $anonymous->get(publicResultsUrl($e['election']))->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
    (new AuthClient($this))->fromAddress('10.30.30.31')->get(publicResultsUrl($e['election']))->assertOk();
});

it('lists the elected of a winners display in the ballot\'s order, not by votes, so the ranking is not given away [FR-RES-08] (scenario 2)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'published', 'results_display' => 'winners', 'published_at' => '2026-10-17 14:00:00']);
    $ballot = Accounts::plantBallot(['election' => $election, 'title' => 'Trois postes', 'seats' => 3]);
    $c = [];
    foreach (['W', 'X', 'Y', 'Z'] as $letter) {
        $c[$letter] = Accounts::plantCandidate(['ballot' => $ballot, 'last_name' => "Nom {$letter}"]);
    }
    Tests\Support\Results::voters($election, 14);
    Tests\Support\Results::paper($ballot, [$c['W'], $c['Z']], 3);
    Tests\Support\Results::paper($ballot, [$c['Y'], $c['Z']], 5);
    Tests\Support\Results::paper($ballot, [$c['Y'], $c['X']], 4);
    Tests\Support\Results::paper($ballot, [$c['Y']], 2);

    $b = (new AuthClient($this))->get(publicResultsUrl($election))->assertOk()->json('data.ballots.0');

    // Votes: Y 11, Z 8, X 4, W 3. The three elected are Y, Z, X; they are listed by position: X, Y, Z.
    expect($b['winners'])->toBe([$c['X'], $c['Y'], $c['Z']])
        ->and(array_column($b['candidates'], 'id'))->toBe([$c['X'], $c['Y'], $c['Z']]);
});

it('does not give a results link to the voters of a suspended institution, since the public page answers 404 [FR-INST-06] (scenario 5)', function () {
    [$t, $e] = publishedElection();
    Accounts::suspend($t['a']['owner']['institution']);

    (new AuthClient($this))->get("/api/v1/vote/{$e['election']}")->assertOk()->assertJsonPath('data.results', null);
});
