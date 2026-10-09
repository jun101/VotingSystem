<?php

/*
 * GET /api/v1/elections/{election} — docs/api/elections/GET-elections-{election}.md
 * Tenant suite, route api/v1/elections/{election}: another institution's election answers 404, the
 * same as an unknown one (scenario 2).
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const UNKNOWN_ELECTION = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function electionUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}";
}

it('shows an election of the institution, in any status, with the shape of the file [FR-ELEC-01] (scenario 1)', function (string $status) {
    $t = Team::two();
    $id = Accounts::plantElection([
        'institution' => $t['a']['owner']['institution'],
        'title' => 'Conseil des élèves 2026',
        'description' => 'Élection annuelle.',
        'status' => $status,
        'timezone' => 'America/Port-au-Prince',
        'language' => 'fr',
        'candidate_order' => 'shuffled',
        'results_display' => 'winners',
        'cover_file' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        'created_at' => '2026-10-08 15:20:00',
    ]);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->get(electionUrl($id))->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and($response->json('data'))->toBe([
            'id' => $id,
            'title' => 'Conseil des élèves 2026',
            'description' => 'Élection annuelle.',
            'status' => $status,
            'starts_at' => '2026-10-12T12:00:00Z',
            'ends_at' => '2026-10-16T19:00:00Z',
            'timezone' => 'America/Port-au-Prince',
            'language' => 'fr',
            'candidate_order' => 'shuffled',
            'results_display' => 'winners',
            'cover' => [
                'sm' => '/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-480.webp',
                'md' => '/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-960.webp',
            ],
            'ballots_count' => 0,
            'voters_count' => 0,
            'created_at' => '2026-10-08T15:20:00Z',
        ]);
    // Nothing internal.
    expect($response->getContent())->not->toContain('institution_id')->not->toContain('parent_election')->not->toContain('cover_file')->not->toContain('opened_at');
})->with(['draft', 'scheduled', 'open', 'closed', 'published', 'archived']);

it('shows a manager the same election [FR-ELEC-01] (scenario 1)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get(electionUrl($id))->assertOk()->assertJsonPath('data.id', $id);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution, or not a UUID [FR-INST-05] (scenario 2)', function () {
    $t = Team::two();
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution'], 'title' => 'Secrète de B']);
    Team::signIn($this, $t['a']['owner']);

    $unknown = Team::shape($this->browser->get(electionUrl(UNKNOWN_ELECTION)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(electionUrl($target))))->toBe($unknown);
    }
    expect($unknown['body'])->not->toContain('Secrète');
});

it('answers 401 when nobody is signed in or the session has gone [FR-ELEC-01] (scenario 3)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(electionUrl($id))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 when the institution was suspended since sign-in [FR-INST-06] (scenario 4)', function () {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);
    Accounts::suspend($t['a']['owner']['institution']);

    $this->browser->get(electionUrl($id))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 405 to another method than GET, HEAD, PATCH or DELETE [NFR-SEC-01] (scenario 5)', function (string $method) {
    $t = Team::two();
    $id = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->other($method, electionUrl($id));

    $response->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    $allow = array_map('trim', explode(',', (string) $response->headers->get('Allow')));
    expect($allow)->toContain('GET')->toContain('PATCH')->toContain('DELETE')->not->toContain($method);
})->with(['POST', 'PUT']);
