<?php

/*
 * GET /api/v1/elections/{election}/voters, credential and email status (slice 11) —
 * docs/api/voters/GET-elections-{election}-voters.md "Slice 11".
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

/** An owner signed in and four voters: Aline (code, sent), Bruno (code, failed), Carole (code, queued), Denis (none). */
function statusSetup($test): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $v = [];
    foreach (['Aline' => 'sent', 'Bruno' => 'failed', 'Carole' => 'queued', 'Denis' => null] as $name => $status) {
        $v[$name] = Accounts::plantVoter(['election' => $election, 'full_name' => $name, 'email' => strtolower($name).'@example.ht']);
        if ($name !== 'Denis') {
            Accounts::plantCredential(['voter' => $v[$name], 'email_status' => $status]);
        }
    }
    Team::signIn($test, $t['a']['owner']);

    return [$election, $v];
}

it('gives each voter has_code and email_status and never the code or its hash [FR-VOT-06] (scenario 1)', function () {
    [$election, $v] = statusSetup($this);

    $data = $this->browser->get("/api/v1/elections/{$election}/voters")->assertOk()->json('data');

    $by = collect($data)->keyBy('full_name');
    expect($by['Aline']['has_code'])->toBeTrue()->and($by['Aline']['email_status'])->toBe('sent')
        ->and($by['Bruno']['email_status'])->toBe('failed')
        ->and($by['Carole']['email_status'])->toBe('queued')
        ->and($by['Denis']['has_code'])->toBeFalse()->and($by['Denis']['email_status'])->toBeNull();
    foreach ($data as $voter) {
        expect(array_keys($voter))->not->toContain('code')->and(array_keys($voter))->not->toContain('code_hash');
    }
});

it('filters by credential status [FR-VOT-06] (scenario 2)', function (string $value, array $names) {
    [$election] = statusSetup($this);

    $data = $this->browser->get("/api/v1/elections/{$election}/voters?credential={$value}")->assertOk();

    expect(array_column($data->json('data'), 'full_name'))->toBe($names)
        ->and($data->json('meta.total'))->toBe(count($names));
})->with([
    'issued' => ['issued', ['Aline', 'Bruno', 'Carole']],
    'none' => ['none', ['Denis']],
]);

it('filters by email status, none included [FR-VOT-06] (scenario 3)', function (string $value, array $names) {
    [$election] = statusSetup($this);

    $data = $this->browser->get("/api/v1/elections/{$election}/voters?email={$value}")->assertOk();

    expect(array_column($data->json('data'), 'full_name'))->toBe($names);
})->with([
    'sent' => ['sent', ['Aline']],
    'failed' => ['failed', ['Bruno']],
    'queued' => ['queued', ['Carole']],
    'none' => ['none', ['Denis']],
]);

it('combines the credential filter with the others [FR-VOT-06] (scenario 4)', function () {
    [$election] = statusSetup($this);

    $data = $this->browser->get("/api/v1/elections/{$election}/voters?credential=issued&email=failed&q=bru")->assertOk()->json('data');

    expect(array_column($data, 'full_name'))->toBe(['Bruno']);
});

it('answers 422 for a status that is not in the list [FR-VOT-06] (scenario 5)', function (string $query, string $field) {
    [$election] = statusSetup($this);

    $response = $this->browser->get("/api/v1/elections/{$election}/voters?{$query}")->assertStatus(422);

    expect($response->json('error.fields.'.$field))->toContain('invalid');
})->with([
    'credential' => ['credential=maybe', 'credential'],
    'email' => ['email=bounced', 'email'],
]);
