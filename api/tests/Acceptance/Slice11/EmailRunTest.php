<?php

/*
 * POST /api/v1/elections/{election}/credential-runs, email part (slice 11) —
 * docs/api/credentials/POST-elections-{election}-credential-runs.md "Slice 11". The test service runs the queue
 * synchronously and mails into the `array` mailer: when the answer is back, the messages are sent.
 */

use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Team;

function emailRunUrl(string $election): string
{
    return "/api/v1/elections/{$election}/credential-runs";
}

/**
 * An owner of A signed in and an election (French) with "4e année" (Rose, Jean with an address; Nadège with none) and
 * Paul with an address and no group. Returns [team, election, g4, voters].
 */
function emailRunSignedIn($test, string $language = 'fr', string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status, 'language' => $language, 'title' => 'Conseil des élèves']);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $v = [
        'Rose' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'group' => $g4, 'email' => 'rose@example.ht']),
        'Jean' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'group' => $g4, 'email' => 'jean@example.ht']),
        'Nadège' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Nadège Louis', 'group' => $g4]),
        'Paul' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Paul Joseph', 'email' => 'paul@example.ht']),
    ];
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $g4, $v];
}

/** The code in an email: the only XXXXX-XXXXX of its text. */
function emailRunCodeIn(array $mail): ?string
{
    return preg_match(Codes::FIND, $mail['text'], $m) === 1 ? $m[0] : null;
}

it('sends one email to each voter served who has an address, and none to the others [FR-CRED-04] (scenario 20)', function () {
    [$t, $election, $g4, $v] = emailRunSignedIn($this);

    $response = $this->browser->post(emailRunUrl($election), ['scope' => 'all', 'email' => true, 'confirm_emails' => 3])->assertStatus(202);

    expect($response->json('data.emails'))->toBe(3);
    foreach (['rose@example.ht', 'jean@example.ht', 'paul@example.ht'] as $address) {
        expect(Accounts::mailTo($address))->toHaveCount(1);
    }
    expect(Accounts::mail())->toHaveCount(3)
        ->and(Accounts::credentialRows($election))->toHaveCount(4);
    foreach (['Rose', 'Jean', 'Paul'] as $name) {
        $row = Accounts::credentialRow($v[$name]);
        expect($row['email_status'])->toBe('sent')
            ->and($row['email_sent_at'])->not->toBeNull()
            ->and($row['email_error'])->toBeNull();
    }
    expect(Accounts::credentialRow($v['Nadège'])['email_status'])->toBeNull();
});

it('puts the voter, the election, the link and the code in the email, in the election\'s language [FR-CRED-04, FR-CRED-06] (scenario 20)', function (string $language, string $needle) {
    [$t, $election, $g4, $v] = emailRunSignedIn($this, $language);

    $this->browser->post(emailRunUrl($election), ['scope' => 'group', 'group' => $g4, 'email' => true, 'confirm_emails' => 2])->assertStatus(202);

    $mail = Accounts::mailTo('rose@example.ht')[0];
    $code = emailRunCodeIn($mail);
    expect($code)->toMatch(Codes::PATTERN)
        ->and($mail['text'])->toContain('Rose Désir')
        ->and($mail['text'])->toContain('Conseil des élèves')
        ->and($mail['text'])->toContain(rtrim((string) config('app.url'), '/')."/vote/{$election}#{$code}")
        ->and($mail['html'])->toContain("/vote/{$election}#{$code}")
        ->and($mail['html'])->toContain($needle)
        ->and(Accounts::credentialRow($v['Rose'])['code_hash'])->toBe(Codes::hash($code));
})->with([
    'french' => ['fr', 'lang="fr"'],
    'english' => ['en', 'lang="en"'],
]);

it('sends to the voter\'s address only and never shows another voter or the institution\'s users [FR-CRED-04] (scenario 20)', function () {
    [$t, $election] = emailRunSignedIn($this);

    $this->browser->post(emailRunUrl($election), ['scope' => 'all', 'email' => true, 'confirm_emails' => 3])->assertStatus(202);

    foreach (Accounts::mail() as $mail) {
        expect($mail['to'])->toHaveCount(1)
            ->and($mail['text'])->not->toContain('Jean Pierre')->and($mail['text'])->not->toContain('Paul Joseph')->and($mail['text'])->not->toContain($t['a']['owner']['email']);
    }
});

it('writes the same code in the email as on the slip file [FR-CRED-04] (scenario 20)', function () {
    [$t, $election, $g4, $v] = emailRunSignedIn($this);

    $this->browser->post(emailRunUrl($election), ['scope' => 'group', 'group' => $g4, 'email' => true, 'confirm_emails' => 2, 'file' => 'csv'])->assertStatus(202);

    $file = collect(Accounts::generatedFileRows($election))->firstWhere('kind', 'codes_csv');
    $csv = Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($this->credentialsRoot.'/'.$file['stored_file']));
    expect($csv)->toContain(emailRunCodeIn(Accounts::mailTo('rose@example.ht')[0]))
        ->and($csv)->toContain(emailRunCodeIn(Accounts::mailTo('jean@example.ht')[0]));
});

it('answers 409 email_count_changed with the right number and does nothing when the confirmation is not the count [FR-CRED-07] (scenario 21)', function () {
    [$t, $election] = emailRunSignedIn($this);

    $response = $this->browser->post(emailRunUrl($election), ['scope' => 'all', 'email' => true, 'confirm_emails' => 2])->assertStatus(409);

    expect($response->json('error.code'))->toBe('email_count_changed')
        ->and($response->json('error.count'))->toBe(3)
        ->and(Accounts::credentialRows($election))->toBe([])
        ->and(Accounts::generatedFileRows($election))->toBe([])
        ->and(Accounts::mail())->toBe([]);
});

it('answers 409 nothing_to_email when nobody served has an address [FR-CRED-07] (scenario 22)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans adresse']);
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(emailRunUrl($election), ['scope' => 'all', 'email' => true, 'confirm_emails' => 0])->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_email');
    expect(Accounts::credentialRows($election))->toBe([]);
});

it('does not email a voter who already had a code, and counts the others only [FR-CRED-04] (scenario 20)', function () {
    [$t, $election, $g4, $v] = emailRunSignedIn($this);
    Accounts::plantCredential(['voter' => $v['Rose']]);

    $this->browser->post(emailRunUrl($election), ['scope' => 'all', 'email' => true, 'confirm_emails' => 2])->assertStatus(202);

    expect(Accounts::mailTo('rose@example.ht'))->toBe([])
        ->and(Accounts::mail())->toHaveCount(2)
        ->and(Accounts::credentialRow($v['Rose'])['email_status'])->toBeNull();
});

it('marks a message failed with a short fixed reason when the mail cannot be sent, and the code is still issued [FR-CRED-04] (scenario 20)', function () {
    [$t, $election, $g4, $v] = emailRunSignedIn($this);
    config([
        'credentials.email_tries' => 1,
        'mail.default' => 'unreachable',
        'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1],
    ]);

    $this->browser->post(emailRunUrl($election), ['scope' => 'group', 'group' => $g4, 'email' => true, 'confirm_emails' => 2])->assertStatus(202);

    foreach (['Rose', 'Jean'] as $name) {
        $row = Accounts::credentialRow($v[$name]);
        expect($row)->not->toBeNull()
            ->and($row['email_status'])->toBe('failed')
            ->and($row['email_error'])->toMatch('/^[a-z_]{3,30}$/');
    }
});

it('gives a new code to the voters whose email failed, and emails it, for failed_email [FR-CRED-04] (scenarios 24, 25)', function () {
    [$t, $election, $g4, $v] = emailRunSignedIn($this);
    $old = Accounts::plantCredential(['voter' => $v['Rose'], 'email_status' => 'failed']);
    Accounts::plantCredential(['voter' => $v['Jean'], 'email_status' => 'sent']);
    $oldHash = Accounts::credentialRow($v['Rose'])['code_hash'];

    $response = $this->browser->post(emailRunUrl($election), ['scope' => 'failed_email', 'email' => true, 'confirm_emails' => 1])->assertStatus(202);

    $row = Accounts::credentialRow($v['Rose']);
    $mails = Accounts::mailTo('rose@example.ht');
    expect($response->json('data.emails'))->toBe(1)
        ->and($row['code_hash'])->not->toBe($oldHash)
        ->and($row['reissue_count'])->toBe(1)
        ->and($row['email_status'])->toBe('sent')
        ->and($mails)->toHaveCount(1)
        ->and(emailRunCodeIn($mails[0]))->not->toBe($old)
        ->and(Accounts::mail())->toHaveCount(1)
        ->and(Accounts::credentialRow($v['Jean'])['reissue_count'])->toBe(0);
    $this->browser->post(emailRunUrl($election), ['scope' => 'failed_email', 'email' => true, 'confirm_emails' => 0])->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_generate');
});

it('answers 422 for a body that is not valid [FR-CRED-07] (scenario 26)', function (string $name, array $body, string $field, string $rule) {
    [$t, $election] = emailRunSignedIn($this);

    $response = $this->browser->post(emailRunUrl($election), $body + ['scope' => 'all'])->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::credentialRows($election))->toBe([]);
})->with([
    'email not a boolean' => ['email', ['email' => 'oui'], 'email', 'invalid'],
    'no confirmation' => ['confirm', ['email' => true], 'confirm_emails', 'required'],
    'negative confirmation' => ['negative', ['email' => true, 'confirm_emails' => -1], 'confirm_emails', 'invalid'],
    'text confirmation' => ['text', ['email' => true, 'confirm_emails' => 'trois'], 'confirm_emails', 'invalid'],
    'file unknown' => ['file', ['file' => 'pdf'], 'file', 'invalid'],
    'failed_email without email' => ['failed', ['scope' => 'failed_email'], 'email', 'required'],
]);

it('keeps a run without email exactly as slice 10 did [FR-CRED-04] (scenario 20)', function () {
    [$t, $election] = emailRunSignedIn($this);

    $this->browser->post(emailRunUrl($election), ['scope' => 'all'])->assertStatus(202);

    expect(Accounts::mail())->toBe([])
        ->and(Accounts::credentialRows($election))->toHaveCount(4)
        ->and(array_unique(array_column(Accounts::credentialRows($election), 'email_status')))->toBe([null]);
});

it('writes no address, no code and no link to a log [NFR-SEC-02] (scenario 20)', function () {
    $before = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $before[$file] = filesize($file);
    }
    [$t, $election] = emailRunSignedIn($this);

    $this->browser->post(emailRunUrl($election), ['scope' => 'all', 'email' => true, 'confirm_emails' => 3])->assertStatus(202);

    $codes = array_filter(array_map('emailRunCodeIn', Accounts::mail()));
    $logged = '';
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $logged .= (string) substr((string) file_get_contents($file), $before[$file] ?? 0);
    }
    expect($codes)->toHaveCount(3);
    foreach ($codes as $code) {
        expect($logged)->not->toContain($code);
    }
    foreach (['rose@example.ht', 'jean@example.ht', 'paul@example.ht', 'Rose Désir', '/vote/'] as $secret) {
        expect($logged)->not->toContain($secret);
    }
});
