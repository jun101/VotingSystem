<?php

/*
 * POST /api/v1/voters/{voter}/credential with `email` (slice 11) —
 * docs/api/credentials/POST-voters-{voter}-credential.md "Slice 11".
 */

use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Team;

/** An owner signed in, a voter with the given address in a draft election. Returns [election, voter]. */
function reissueEmailSetup($test, ?string $email = 'rose@example.ht'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Conseil des élèves']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => $email]);
    Team::signIn($test, $t['a']['owner']);

    return [$election, $voter];
}

it('emails the new code to the voter and says so [FR-CRED-04] (scenario 1)', function () {
    [$election, $voter] = reissueEmailSetup($this);
    Accounts::plantCredential(['voter' => $voter]);

    $response = $this->browser->post("/api/v1/voters/{$voter}/credential", ['email' => true])->assertCreated();

    $mails = Accounts::mailTo('rose@example.ht');
    expect($response->json('data.email'))->toBe('queued')
        ->and($mails)->toHaveCount(1)
        ->and($mails[0]['text'])->toContain($response->json('data.code'))
        ->and(Accounts::credentialRow($voter)['email_status'])->toBe('sent');
});

it('does not email without the flag [FR-CRED-04] (scenario 2)', function () {
    [$election, $voter] = reissueEmailSetup($this);

    $response = $this->browser->post("/api/v1/voters/{$voter}/credential")->assertCreated();

    expect($response->json('data.email'))->toBeNull()
        ->and(Accounts::mail())->toBe([])
        ->and(Accounts::credentialRow($voter)['email_status'])->toBeNull();
});

it('answers 422 email no_address and changes nothing for a voter with no address [FR-CRED-04] (scenario 3)', function () {
    [$election, $voter] = reissueEmailSetup($this, null);
    $code = Accounts::plantCredential(['voter' => $voter, 'code' => 'K7MP4-QX9RT']);

    $response = $this->browser->post("/api/v1/voters/{$voter}/credential", ['email' => true])->assertStatus(422);

    expect($response->json('error.fields.email'))->toContain('no_address')
        ->and(Accounts::credentialRow($voter)['code_hash'])->toBe(Codes::hash($code))
        ->and(Accounts::generatedFileRows($election))->toBe([]);
});

it('answers 422 email invalid for something that is not a boolean [FR-CRED-04] (scenario 4)', function () {
    [$election, $voter] = reissueEmailSetup($this);

    $this->browser->post("/api/v1/voters/{$voter}/credential", ['email' => 'oui'])->assertStatus(422);
    expect(Accounts::credentialRow($voter))->toBeNull();
});
