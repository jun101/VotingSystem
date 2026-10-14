<?php

/*
 * Slice 11 review round: who may send emails and how many, statuses that must not lie, text that cannot forge lines,
 * the sweep of stuck statuses, the resend offer, the name of the file of the ungrouped. docs/slices/11-email-and-file.md
 * "Review round".
 */

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Accounts;
use Tests\Support\Team;

/** An election of the owner with Rose and Jean (an address each) and Nadège (none). Returns [team, election, voters]. */
function reviewMailSetup(array $owner = []): array
{
    $user = Accounts::user($owner + ['name' => 'Alice Owner A', 'email' => 'alice.review@example.test']);
    $election = Accounts::plantElection(['institution' => $user['institution'], 'title' => 'Conseil des élèves']);
    $v = [
        'Rose' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => 'rose@example.ht']),
        'Jean' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'email' => 'jean@example.ht']),
        'Nadège' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Nadège Louis']),
    ];

    return [$user, $election, $v];
}

function reviewMailSignIn($test, array $user): void
{
    $test->browser->login($user['email'], $user['password'])->assertOk();
}

it('refuses to send emails for a user whose email is not verified, and still lets a run without email go [FR-CRED-07] (review 1)', function () {
    [$user, $election, $v] = reviewMailSetup(['verified' => false]);
    reviewMailSignIn($this, $user);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 2])
        ->assertStatus(403)->assertJsonPath('error.code', 'email_not_verified');
    $this->browser->post("/api/v1/voters/{$v['Rose']}/credential", ['email' => true])
        ->assertStatus(403)->assertJsonPath('error.code', 'email_not_verified');
    expect(Accounts::credentialRows($election))->toBe([])
        ->and(Accounts::mail())->toBe([]);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);
    expect(Accounts::credentialRows($election))->toHaveCount(3);
});

it('stops at the daily quota of emails of an institution with the number left [FR-CRED-07] (review 1)', function () {
    config(['credentials.emails_per_institution_per_day' => 1]);
    [$user, $election] = reviewMailSetup();
    reviewMailSignIn($this, $user);

    $response = $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 2])->assertStatus(409);

    expect($response->json('error.code'))->toBe('email_quota_exceeded')
        ->and($response->json('error.count'))->toBe(1)
        ->and(Accounts::credentialRows($election))->toBe([])
        ->and(Accounts::mail())->toBe([]);
});

it('counts the emails already sent today in the quota [FR-CRED-07] (review 1)', function () {
    config(['credentials.emails_per_institution_per_day' => 3]);
    [$user, $election, $v] = reviewMailSetup();
    reviewMailSignIn($this, $user);
    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 2])->assertStatus(202);
    $extra = Accounts::plantVoter(['election' => $election, 'full_name' => 'Troisième', 'email' => 'trois@example.ht']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Quatrième', 'email' => 'quatre@example.ht']);

    $response = $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 2])->assertStatus(409);

    expect($response->json('error.code'))->toBe('email_quota_exceeded')
        ->and($response->json('error.count'))->toBe(1)
        ->and(Accounts::credentialRow($extra))->toBeNull();
});

it('clears the email status of a voter given a printed code, whatever it was [FR-CRED-03, FR-CRED-04] (review 4)', function (string $status) {
    [$user, $election, $v] = reviewMailSetup();
    Accounts::plantCredential(['voter' => $v['Rose'], 'email_status' => $status]);
    reviewMailSignIn($this, $user);

    $this->browser->post("/api/v1/voters/{$v['Rose']}/credential")->assertCreated();

    $row = Accounts::credentialRow($v['Rose']);
    expect($row['email_status'])->toBeNull()
        ->and($row['email_sent_at'])->toBeNull()
        ->and($row['email_error'])->toBeNull();
    $state = $this->browser->get("/api/v1/elections/{$election}/credentials")->assertOk()->json('data');
    expect($state['run_in_progress'])->toBeFalse();
})->with(['queued', 'sent', 'failed']);

it('puts a voter name with line breaks on one line of the text email [NFR-SEC-02] (review 5)', function () {
    [$user, $election, $v] = reviewMailSetup();
    $evil = Accounts::plantVoter(['election' => $election, 'full_name' => "Jean\n\nVotre lien a changé :\nhttps://evil.example/vote", 'email' => 'evil@example.ht']);
    reviewMailSignIn($this, $user);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 3])->assertStatus(202);

    $mail = Accounts::mailTo('evil@example.ht')[0];
    foreach (preg_split('/\R/', $mail['text']) as $line) {
        expect($line)->not->toStartWith('https://evil.example')->and($line)->not->toStartWith('Votre lien a changé');
    }
    expect($mail['text'])->toContain('Votre lien a changé');
    expect($evil)->toBeString();
});

it('fails the email status of a code that stayed queued for hours with no job behind it, and leaves a recent one [FR-CRED-04] (review 7)', function () {
    [$user, $election, $v] = reviewMailSetup();
    Accounts::plantCredential(['voter' => $v['Rose'], 'email_status' => 'queued', 'issued_at' => now('UTC')->subHours(8)->toDateTimeString()]);
    Accounts::plantCredential(['voter' => $v['Jean'], 'email_status' => 'queued', 'issued_at' => now('UTC')->subMinutes(30)->toDateTimeString()]);

    expect(Artisan::call('files:purge'))->toBe(0);

    $old = Accounts::credentialRow($v['Rose']);
    expect($old['email_status'])->toBe('failed')
        ->and($old['email_error'])->toBe('send_failed')
        ->and(Accounts::credentialRow($v['Jean'])['email_status'])->toBe('queued');
});

it('counts as failed emails only the voters who still have an address, the ones a resend can serve [FR-CRED-04] (review 9)', function () {
    [$user, $election, $v] = reviewMailSetup();
    Accounts::plantCredential(['voter' => $v['Rose'], 'email_status' => 'failed']);
    Accounts::plantCredential(['voter' => $v['Nadège'], 'email_status' => 'failed']);
    reviewMailSignIn($this, $user);

    $state = $this->browser->get("/api/v1/elections/{$election}/credentials")->assertOk()->json('data');

    expect($state['email_failed'])->toBe(1);
});

it('names the file of the voters with no group after them, not after all groups [FR-CRED-04] (review 12)', function () {
    [$user, $election] = reviewMailSetup();
    reviewMailSignIn($this, $user);

    $response = $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'ungrouped', 'file' => 'xlsx'])->assertStatus(202);

    $id = collect($response->json('data.files'))->firstWhere('kind', 'codes_xlsx')['id'];
    $this->browser->get("/api/v1/generated-files/{$id}/download")->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="codes-sans-groupe.xlsx"');
});
