<?php

/*
 * GET /api/v1/elections/{election}/voters, slice 14 — docs/api/voters/GET-elections-{election}-voters.md
 * "Slice 14: has voted". The older scenarios stay in Slice07/ListVotersTest.php.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Turnout;

function votedList($test): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'open']);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $rose = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'group' => $group]);
    $jean = Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'group' => $group]);
    $paul = Accounts::plantVoter(['election' => $election, 'full_name' => 'Paul Étienne']);
    Turnout::voted($rose, '2026-10-12 13:15:00');
    Team::signIn($test, $t['a']['owner']);

    return [$election, $group, ['rose' => $rose, 'jean' => $jean, 'paul' => $paul]];
}

it('gives has_voted and voted_at on every voter and nothing about what was voted [FR-VOT-07] (slice 14)', function () {
    [$election, , $v] = votedList($this);

    $data = collect($this->browser->get("/api/v1/elections/{$election}/voters")->assertOk()->json('data'))->keyBy('id');

    expect($data[$v['rose']])->toMatchArray(['has_voted' => true, 'voted_at' => '2026-10-12T13:15:00Z'])
        ->and($data[$v['jean']])->toMatchArray(['has_voted' => false, 'voted_at' => null])
        ->and($data[$v['paul']]['has_voted'])->toBeFalse();
});

it('filters by voted yes and no, combined with the other filters, and counts the filtered total [FR-VOT-06] (slice 14)', function () {
    [$election, $group, $v] = votedList($this);
    $url = "/api/v1/elections/{$election}/voters";

    $yes = $this->browser->get("{$url}?voted=yes")->assertOk();
    $no = $this->browser->get("{$url}?voted=no")->assertOk();
    $noInGroup = $this->browser->get("{$url}?voted=no&group={$group}")->assertOk();
    $noSearch = $this->browser->get("{$url}?voted=no&q=paul")->assertOk();

    expect(array_column($yes->json('data'), 'id'))->toBe([$v['rose']])
        ->and($yes->json('meta.total'))->toBe(1)
        ->and(array_column($no->json('data'), 'id'))->toBe([$v['jean'], $v['paul']])
        ->and($no->json('meta.total'))->toBe(2)
        ->and(array_column($noInGroup->json('data'), 'id'))->toBe([$v['jean']])
        ->and(array_column($noSearch->json('data'), 'id'))->toBe([$v['paul']]);
});

it('answers 422 for another value of voted [FR-VOT-06] (slice 14)', function () {
    [$election] = votedList($this);

    $this->browser->get("/api/v1/elections/{$election}/voters?voted=maybe")->assertStatus(422)->assertJsonPath('error.fields.voted.0', 'invalid');
});
