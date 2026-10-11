<?php

/*
 * GET /api/v1/vote/{election} — docs/api/vote/GET-vote-{election}.md. Public: no session.
 */

use Tests\Support\Accounts;
use Tests\Support\Voting;

const VOTE_INFO_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function voteInfoUrl(string $uuid): string
{
    return "/api/v1/vote/{$uuid}";
}

it('says the election is before its start while it is scheduled [FR-VOTE-02] (scenario 1)', function () {
    $e = Voting::election(['status' => 'scheduled', 'starts_at' => now('UTC')->addDay()->toDateTimeString(), 'ends_at' => now('UTC')->addDays(3)->toDateTimeString()]);

    $response = $this->browser->get(voteInfoUrl($e['election']))->assertOk();

    expect($response->json('data'))->toMatchArray(['id' => $e['election'], 'title' => 'Conseil des élèves 2026', 'language' => 'fr', 'phase' => 'before', 'results' => null])
        ->and(array_keys($response->json('data')))->toBe(['id', 'title', 'institution', 'starts_at', 'ends_at', 'timezone', 'language', 'phase', 'results']);
});

it('says before for an open election whose start has not come [FR-VOTE-02] (scenario 1)', function () {
    $e = Voting::election(['status' => 'open', 'starts_at' => now('UTC')->addHour()->toDateTimeString()]);

    $this->browser->get(voteInfoUrl($e['election']))->assertOk()->assertJsonPath('data.phase', 'before');
});

it('says open between the start and the end of an open election [FR-VOTE-02] (scenario 2)', function () {
    $e = Voting::election();

    $this->browser->get(voteInfoUrl($e['election']))->assertOk()->assertJsonPath('data.phase', 'open');
});

it('says closed after the end even if the status still says open, and for closed, published and archived elections [FR-ELEC-05] (scenario 3)', function (string $status, string $ends) {
    $e = Voting::election(['status' => $status, 'ends_at' => $ends === 'past' ? now('UTC')->subMinute()->toDateTimeString() : now('UTC')->addDay()->toDateTimeString()]);

    $this->browser->get(voteInfoUrl($e['election']))->assertOk()->assertJsonPath('data.phase', 'closed');
})->with([
    'open but past its end' => ['open', 'past'],
    'closed' => ['closed', 'future'],
    'published' => ['published', 'future'],
    'archived' => ['archived', 'future'],
]);

it('gives the address of the public results once published [FR-VOTE-02] (scenario 3)', function () {
    $e = Voting::election(['status' => 'published']);

    $this->browser->get(voteInfoUrl($e['election']))->assertOk()->assertJsonPath('data.results', "/results/{$e['election']}");
});

it('answers 404, the same for every case, for a draft, an unknown election or a value that is not a UUID [FR-VOTE-01] (scenario 4)', function () {
    $e = Voting::election(['status' => 'draft']);

    $unknown = Tests\Support\Team::shape($this->browser->get(voteInfoUrl(VOTE_INFO_UNKNOWN)));
    expect($unknown['status'])->toBe(404);

    foreach ([$e['election'], 'not-a-uuid', '12'] as $target) {
        expect(Tests\Support\Team::shape($this->browser->get(voteInfoUrl($target))))->toBe($unknown);
    }
});

it('stays readable and closed for a suspended institution [FR-VOTE-02] (scenario 5)', function () {
    $e = Voting::election();
    Accounts::suspend($e['institution']);

    $this->browser->get(voteInfoUrl($e['election']))->assertOk()->assertJsonPath('data.phase', 'closed');
});

it('answers 405 for another method [NFR-SEC-04] (scenario 6)', function () {
    $e = Voting::election();

    $this->browser->other('PUT', voteInfoUrl($e['election']))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});

it('outputs no numeric id, no count and no ballot [NFR-SEC-08] (scenario 2)', function () {
    $e = Voting::election();

    $body = $this->browser->get(voteInfoUrl($e['election']))->assertOk()->getContent();

    expect($body)->not->toContain('ballots')->and($body)->not->toContain('voters')->and($body)->not->toContain('votes');
});
