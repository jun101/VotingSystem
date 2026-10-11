<?php

/*
 * POST /api/v1/elections/{election}/credential-previews — docs/api/credentials/POST-elections-{election}-credential-previews.md
 * Tenant suite, route api/v1/elections/{election}/credential-previews: another institution's election answers 404, the
 * same as an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const PREVIEW_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function previewUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/credential-previews";
}

/**
 * An owner of A signed in and an election with "4e année" (Rose with an email and a code, Jean with an email, Nadège with
 * none), "5e année" (Paul with an email) and Marc with no group and no email. Returns [team, election, g4, g5, voters].
 */
function previewSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g5 = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    $v = [
        'Rose' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose', 'group' => $g4, 'email' => 'rose@example.ht']),
        'Jean' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean', 'group' => $g4, 'email' => 'jean@example.ht']),
        'Nadège' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Nadège', 'group' => $g4]),
        'Paul' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Paul', 'group' => $g5, 'email' => 'paul@example.ht']),
        'Marc' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Marc']),
    ];
    Accounts::plantCredential(['voter' => $v['Rose']]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $g4, $g5, $v];
}

it('counts what a run would do, who has no address and who is skipped [FR-CRED-07] (scenario 1)', function () {
    [$t, $election, $g4, $g5, $v] = previewSignedIn($this);

    $response = $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertOk();

    expect($response->json('data'))->toMatchArray(['to_generate' => 4, 'with_email' => 2, 'without_email' => 2, 'skipped' => 1])
        ->and($response->json('data.voters_without_email'))->toBe([
            ['id' => $v['Marc'], 'full_name' => 'Marc', 'group' => null],
            ['id' => $v['Nadège'], 'full_name' => 'Nadège', 'group' => ['id' => $g4, 'name' => '4e année']],
        ]);
});

it('reads only: no code, no file, no mail [FR-CRED-07] (scenario 1)', function () {
    [$t, $election] = previewSignedIn($this);

    $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertOk();

    expect(Accounts::credentialRows($election))->toHaveCount(1)
        ->and(Accounts::generatedFileRows($election))->toBe([])
        ->and(Accounts::mail())->toBe([]);
});

it('counts a group or the ungrouped voters only [FR-CRED-07] (scenario 2)', function () {
    [$t, $election, $g4, $g5] = previewSignedIn($this);

    $group = $this->browser->post(previewUrl($election), ['scope' => 'group', 'group' => $g4])->assertOk()->json('data');
    $none = $this->browser->post(previewUrl($election), ['scope' => 'ungrouped'])->assertOk()->json('data');

    expect($group)->toMatchArray(['to_generate' => 2, 'with_email' => 1, 'without_email' => 1, 'skipped' => 1])
        ->and($none)->toMatchArray(['to_generate' => 1, 'with_email' => 0, 'without_email' => 1, 'skipped' => 0]);
});

it('counts the voters whose last email failed for failed_email [FR-CRED-04] (scenario 3)', function () {
    [$t, $election, $g4, $g5, $v] = previewSignedIn($this);
    Accounts::plantCredential(['voter' => $v['Jean'], 'email_status' => 'failed']);
    Accounts::plantCredential(['voter' => $v['Paul'], 'email_status' => 'sent']);

    $data = $this->browser->post(previewUrl($election), ['scope' => 'failed_email'])->assertOk()->json('data');

    expect($data)->toMatchArray(['to_generate' => 1, 'with_email' => 1, 'without_email' => 0, 'skipped' => 0, 'voters_without_email' => []]);
});

it('lists at most 100 voters without an address and counts all of them [FR-CRED-07] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    foreach (range(1, 130) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => sprintf('Sans courriel %03d', $i)]);
    }
    Team::signIn($this, $t['a']['owner']);

    $data = $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertOk()->json('data');

    expect($data['without_email'])->toBe(130)
        ->and($data['voters_without_email'])->toHaveCount(100)
        ->and($data['voters_without_email'][0]['full_name'])->toBe('Sans courriel 001');
});

it('answers zeros for an election with nothing to do [FR-CRED-07] (scenario 5)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    $data = $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertOk()->json('data');

    expect($data)->toBe(['to_generate' => 0, 'with_email' => 0, 'without_email' => 0, 'skipped' => 0, 'voters_without_email' => []]);
});

it('answers 409 election_codes_locked for a closed, published or archived election [FR-CRED-07] (scenario 6)', function (string $status) {
    [$t, $election] = previewSignedIn($this, $status);

    $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'election_codes_locked');
})->with(['closed', 'published', 'archived']);

it('answers 422 for a body that is not valid [FR-CRED-07] (scenario 7)', function (string $name, array $body, string $field, string $rule) {
    [$t, $election] = previewSignedIn($this);

    $response = $this->browser->post(previewUrl($election), $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.fields.{$field}"))->toContain($rule);
})->with([
    'no scope' => ['none', [], 'scope', 'required'],
    'unknown scope' => ['class', ['scope' => 'class'], 'scope', 'invalid'],
    'group without group' => ['nogroup', ['scope' => 'group'], 'group', 'required'],
    'group not a uuid' => ['uuid', ['scope' => 'group', 'group' => '4e'], 'group', 'invalid'],
]);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 8)', function () {
    [$t, $election] = previewSignedIn($this);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);

    $unknown = Team::shape($this->browser->post(previewUrl(PREVIEW_UNKNOWN), ['scope' => 'all']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(previewUrl($target), ['scope' => 'all'])))->toBe($unknown);
    }
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenario 9)', function () {
    [$t, $election] = previewSignedIn($this);

    $this->browser->post(previewUrl($election), ['scope' => 'all'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', previewUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 401 when nobody is signed in [FR-CRED-07] (scenario 9)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 429 above 120 requests an hour from one user [NFR-SEC-05] (scenario 9)', function () {
    [$t, $election] = previewSignedIn($this);

    foreach (range(1, 120) as $i) {
        $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertOk();
    }

    $this->browser->post(previewUrl($election), ['scope' => 'all'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
