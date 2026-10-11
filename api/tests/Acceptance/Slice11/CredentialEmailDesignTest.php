<?php

/*
 * The credentials email follows docs/design/email.md (slice 02 rules, checked for every email by MailDesignTest for the
 * ones that carry a token): tables and inline colour, no image, no external load, a preheader, a text part, no person's
 * name, and the colours of the tokens only.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

/** The credentials email of Rose in the given language. */
function credentialMail($test, string $language): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'language' => $language, 'title' => 'Conseil des élèves']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => 'rose@example.ht']);
    Team::signIn($test, $t['a']['owner']);
    $test->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 1])->assertStatus(202);

    return Accounts::mailTo('rose@example.ht')[0] + ['election' => $election];
}

it('is built with tables and inline colour, with no image and nothing loaded from elsewhere [docs/design/email.md section 2]', function (string $language) {
    $mail = credentialMail($this, $language);

    expect($mail['html'])->toContain('<table')
        ->and($mail['html'])->not->toContain('<img')
        ->and($mail['html'])->not->toMatch('#<link[^>]+href=#i')
        ->and($mail['html'])->not->toMatch('#<script#i')
        ->and($mail['html'])->not->toMatch('#url\(\s*[\'"]?https?://#i')
        ->and($mail['html'])->not->toContain('background-image');
})->with(['fr', 'en']);

it('uses only the colours of the design tokens [docs/design/email.md section 1]', function () {
    $mail = credentialMail($this, 'fr');
    $css = file_get_contents(base_path('../web/src/styles/tokens.css'));
    preg_match_all('/--color-[a-z0-9-]+:\s*(#[0-9a-fA-F]{6})\b/', (string) $css, $tokens);
    $allowed = array_map('strtolower', $tokens[1]);
    preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $mail['html'], $used);

    foreach (array_unique(array_map('strtolower', $used[0])) as $colour) {
        expect($allowed)->toContain($colour);
    }
});

it('has a preheader hidden from the body, a title and the same content in the text part [docs/design/email.md section 2]', function () {
    $mail = credentialMail($this, 'fr');

    expect($mail['html'])->toMatch('#display:\s*none#i')
        ->and($mail['html'])->toMatch('#<title>[^<]+</title>#')
        ->and($mail['subject'])->not->toBe('')
        ->and($mail['text'])->toContain('Conseil des élèves')
        ->and($mail['text'])->toContain('/vote/'.$mail['election'].'#');
});

it('never names a user of the institution, only the voter and the institution [security review S6]', function () {
    $mail = credentialMail($this, 'fr');
    expect($mail['text'])->toContain('Rose Désir')
        ->and($mail['html'])->not->toContain('Alice Owner A');
});

it('puts the code in the text and the link on a button in the html [FR-CRED-04, FR-CRED-06]', function () {
    $mail = credentialMail($this, 'fr');
    preg_match('/[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}/', $mail['text'], $code);

    expect($code)->not->toBe([])
        ->and($mail['html'])->toContain($code[0])
        ->and($mail['html'])->toMatch('#<a[^>]+href="[^"]+/vote/'.$mail['election'].'\#'.$code[0].'"#');
});
