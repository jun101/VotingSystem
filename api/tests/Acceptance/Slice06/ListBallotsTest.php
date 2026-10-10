<?php

/*
 * GET /api/v1/elections/{election}/ballots — docs/api/ballots/GET-elections-{election}-ballots.md
 * Tenant suite, route api/v1/elections/{election}/ballots: another institution's record answers 404, the same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const LIST_UNKNOWN_ELECTION = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function listUrl(string $uuid, string $query = ''): string
{
    return "/api/v1/elections/{$uuid}/ballots{$query}";
}

/** An owner of A signed in, and an election of A in the given status. Returns [team, election uuid]. */
function listSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('lists the ballots in display order with the documented fields [FR-BAL-01] (scenario 1)', function () {
    [$t, $election] = listSignedIn($this);
    $second = Accounts::plantBallot(['election' => $election, 'title' => 'Secrétaire', 'position' => 2, 'seats' => 2, 'allow_blank' => false, 'description' => 'Un poste']);
    $first = Accounts::plantBallot(['election' => $election, 'title' => 'Président(e)', 'position' => 1]);

    $response = $this->browser->get(listUrl($election))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$first, $second])
        ->and($response->json('data.1'))->toMatchArray(['id' => $second, 'title' => 'Secrétaire', 'description' => 'Un poste', 'position' => 2, 'seats' => 2, 'allow_blank' => false, 'candidates_count' => 0])
        ->and(array_keys($response->json('data.1')))->toEqualCanonicalizing(['id', 'title', 'description', 'position', 'seats', 'allow_blank', 'candidates_count', 'created_at', 'updated_at'])
        ->and($response->json('meta.total'))->toBe(2);
});

it('answers an empty list for an election with no ballot [FR-BAL-01] (scenario 2)', function () {
    [$t, $election] = listSignedIn($this);

    $this->browser->get(listUrl($election))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});

it('lists the ballots of an election that is not a draft too [FR-BAL-01] (scenario 3)', function (string $status) {
    [$t, $election] = listSignedIn($this, $status);
    Accounts::plantBallot(['election' => $election]);

    $this->browser->get(listUrl($election))->assertOk()->assertJsonPath('meta.total', 1);
})->with(['scheduled', 'open', 'closed', 'published', 'archived']);

it('never lists the ballots of another election or institution [FR-INST-05] (scenario 1)', function () {
    [$t, $election] = listSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $mine = Accounts::plantBallot(['election' => $election, 'title' => 'À moi']);
    Accounts::plantBallot(['election' => $other, 'title' => 'Autre élection']);
    Accounts::plantBallot(['election' => $foreign, 'title' => 'De B']);

    $response = $this->browser->get(listUrl($election))->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$mine]);
});

it('pages the list with per_page and page [FR-BAL-01] (scenario 1)', function () {
    [$t, $election] = listSignedIn($this);
    foreach (range(1, 3) as $i) {
        Accounts::plantBallot(['election' => $election, 'title' => "Poste {$i}"]);
    }

    $page = $this->browser->get(listUrl($election, '?per_page=2&page=2'))->assertOk();

    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('data.0.title'))->toBe('Poste 3')
        ->and($page->json('meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3]);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 4)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Accounts::plantBallot(['election' => $foreign]);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(listUrl(LIST_UNKNOWN_ELECTION)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(listUrl($target))))->toBe($unknown);
    }
});

it('answers 401 when nobody is signed in [FR-BAL-01] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(listUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 6)', function () {
    [$t, $election] = listSignedIn($this);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(listUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 for another method [FR-BAL-01] (scenario 7)', function (string $method) {
    [$t, $election] = listSignedIn($this);

    $this->browser->other($method, listUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
})->with(['PUT', 'PATCH', 'DELETE']);

it('outputs no numeric id or foreign key [NFR-SEC-08] (scenario 1)', function () {
    [$t, $election] = listSignedIn($this);
    Accounts::plantBallot(['election' => $election]);

    $item = $this->browser->get(listUrl($election))->assertOk()->json('data.0');

    expect($item)->not->toHaveKeys(['election_id', 'institution_id', 'election', 'institution'])
        ->and(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $item['id']))->toBe(1);
});
