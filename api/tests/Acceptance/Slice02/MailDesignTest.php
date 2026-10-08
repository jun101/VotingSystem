<?php

/*
 * docs/design/email.md — the look and the build of the emails the system sends.
 * Slice 02 sends two: the verification email and the password reset email, each in
 * French and English. Slice 04 adds the invitation. A later slice adds its emails to
 * `mailsToCheck()`.
 */

use Tests\Support\Accounts;
use Tests\Support\AuthClient;

/**
 * Every email of the system, in both languages.
 *
 * @return list<array{label: string, mail: array{to: list<string>, subject: string, text: string, html: string}, path: string}>
 */
function mailsToCheck($test): array
{
    $found = [];

    foreach (['fr', 'en'] as $language) {
        $user = Accounts::user(['verified' => false, 'language' => $language]);
        $browser = new AuthClient($test);
        $browser->login($user['email'], $user['password'])->assertOk();
        $browser->post('/api/v1/auth/verify-email/resend')->assertNoContent();
        $browser->post('/api/v1/auth/forgot-password', ['email' => $user['email']])->assertNoContent();

        foreach (Accounts::mailTo($user['email']) as $mail) {
            $path = Accounts::tokenIn($mail, '/verify-email') !== null ? '/verify-email' : '/reset-password';
            $found[] = ['label' => "{$language} {$path}", 'mail' => $mail, 'path' => $path];
        }
    }

    // Slice 04: the invitation of a user, in the language of the inviting institution.
    foreach (['fr', 'en'] as $language) {
        $owner = Accounts::user(['language' => $language]);
        $browser = new AuthClient($test);
        $browser->login($owner['email'], $owner['password'])->assertOk();
        $invited = "invited.{$language}@example.test";
        $browser->post('/api/v1/invitations', ['email' => $invited, 'role' => 'manager'])->assertCreated();

        foreach (Accounts::mailTo($invited) as $mail) {
            $found[] = ['label' => "{$language} /accept-invitation", 'mail' => $mail, 'path' => '/accept-invitation'];
        }
    }

    expect($found)->toHaveCount(6);

    return $found;
}

/** The hexadecimal colours of the tokens of the web application, lower case, 6 digits. */
function tokenColours(): array
{
    $css = file_get_contents(base_path('../web/src/styles/tokens.css'));
    preg_match_all('/--color-[a-z0-9-]+:\s*(#[0-9a-fA-F]{6})\b/', (string) $css, $found);

    return array_values(array_unique(array_map('strtolower', $found[1])));
}

function luminance(string $hex): float
{
    $channels = array_map(function ($part) {
        $c = hexdec($part) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrast(string $a, string $b): float
{
    [$l1, $l2] = [luminance($a), luminance($b)];

    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}

it('uses only the colours of the design tokens [docs/design/email.md section 1]', function () {
    $tokens = tokenColours();
    expect($tokens)->not->toBe([]);

    foreach (mailsToCheck($this) as $item) {
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $item['mail']['html'], $found);

        foreach (array_unique(array_map('strtolower', $found[0])) as $colour) {
            expect(in_array($colour, $tokens, true))->toBe(true, "{$item['label']}: {$colour} is not the value of a token");
        }
    }
});

it('paints the brand: navy header, hero, white body, accent button with deep navy text [docs/design/email.md section 1]', function () {
    foreach (mailsToCheck($this) as $item) {
        $html = strtolower($item['mail']['html']);

        foreach (['#061a3d', '#0d3a7a', '#ffffff', '#ff8603'] as $colour) {
            expect(str_contains($html, $colour))->toBe(true, "{$item['label']}: no {$colour}");
        }

        // The button: accent background; its text deep navy, never white.
        expect($html)->toMatch('/bgcolor="#ff8603"/');
        expect($html)->toMatch('/<a [^>]*style="[^"]*color:\s*#061a3d[^"]*"[^>]*>[^<]*(?:<[^>]+>)*[^<]*<\/a>/s');

        // The gradient is an addition on top of the solid hero colour.
        expect($html)->toContain('linear-gradient(135deg');
        expect($html)->toMatch('/bgcolor="#0d3a7a"/');
    }
});

it('is built with tables and inline colour, as the mail programs need [docs/design/email.md section 2]', function () {
    foreach (mailsToCheck($this) as $item) {
        $html = $item['mail']['html'];

        expect(strtolower($html))->toStartWith('<!doctype html');
        expect($html)->toMatch('/<html[^>]*\blang="(fr|en)"/');
        expect($html)->toContain('role="presentation"');
        expect($html)->toMatch('/<title>[^<]+<\/title>/');
        expect($html)->toContain('name="color-scheme"');
        expect($html)->toContain('prefers-color-scheme: dark');
        expect($html)->toContain('<!--[if mso');   // the Outlook button
        expect($html)->toContain('v:roundrect');

        // No property or element the main programs drop or Word cannot draw.
        foreach (['display:flex', 'display: flex', 'display:grid', 'display: grid', 'float:', 'position:', 'calc(', 'var(--', '@import', '<link', '<script', '<form', '<svg', '<video', '<iframe', 'javascript:'] as $banned) {
            expect(str_contains(strtolower($html), $banned))->toBe(false, "{$item['label']} uses {$banned}");
        }

        // Fonts that exist everywhere; no web font.
        expect($html)->toContain('-apple-system');
        expect($html)->toContain('Arial');
        expect(strtolower($html))->not->toContain('@font-face')->not->toContain('fonts.googleapis');

        // Under what Gmail cuts.
        expect(strlen($html))->toBeLessThan(102 * 1024);
    }
});

it('loads nothing from anywhere and has no image [docs/design/email.md section 2]', function () {
    foreach (mailsToCheck($this) as $item) {
        $html = $item['mail']['html'];

        expect($html)->not->toContain('<img');
        expect(strtolower($html))->not->toMatch('/url\((?!\s*[\'"]?data:)/');

        preg_match_all('#(?:href|src)="(https?://[^"]+)"#', $html, $links);
        $own = rtrim((string) config('app.url'), '/');

        foreach ($links[1] as $link) {
            expect(html_entity_decode($link))->toStartWith($own);
        }
    }
});

it('has a preheader, hidden from the body, and a title and a hero that say what the email is [docs/design/email.md sections 1, 2]', function () {
    foreach (mailsToCheck($this) as $item) {
        $html = $item['mail']['html'];

        expect($html)->toMatch('/display:\s*none[^>]*>[^<]{10,}/');   // the preheader
        expect($html)->toContain($item['mail']['subject']);           // the subject is the title of the page
    }
});

it('keeps the text contrast above 4.5 to 1 on its background [docs/design/email.md section 2, NFR-UX-03]', function () {
    $pairs = [
        ['#ffffff', '#061a3d'],   // product name on the header
        ['#ffffff', '#0d3a7a'],   // hero title
        ['#111b33', '#ffffff'],   // body text
        ['#4a556b', '#ffffff'],   // small print
        ['#061a3d', '#ff8603'],   // button
        ['#1e3a8a', '#ffffff'],   // link
    ];

    foreach ($pairs as [$text, $background]) {
        expect(contrast($text, $background))->toBeGreaterThanOrEqual(4.5, "{$text} on {$background}");
    }

    // Footer text: ink-muted on white is a large-text colour only; the footer is 12 px, so it must
    // not use it.
    foreach (mailsToCheck($this) as $item) {
        expect(strtolower($item['mail']['html']))->not->toMatch('/color:\s*#8e99ae/');
    }
});

it('puts the same content in the text part [docs/design/email.md section 2]', function () {
    foreach (mailsToCheck($this) as $item) {
        $text = $item['mail']['text'];
        $link = Accounts::tokenIn($item['mail'], $item['path']);

        expect($link)->not->toBeNull();
        expect($text)->toContain(rtrim((string) config('app.url'), '/').$item['path'].'?token='.$link);
        expect(preg_match('/^\s*https?:\/\/\S+\s*$/m', $text))->toBe(1);   // the link on a line of its own
        expect($text)->toContain($item['mail']['subject']);
    }
});

it('does not use the person\'s name anywhere in the email [security review S6]', function () {
    $user = Accounts::user(['verified' => false, 'email' => 'someone@example.test']);
    \Illuminate\Support\Facades\DB::connection(useMigratorConnection())->table('users')
        ->where('uuid', $user['user'])->update(['name' => 'Zebulon Quxwyvern']);

    $browser = new AuthClient($this);
    $browser->login($user['email'], $user['password'])->assertOk();
    $browser->post('/api/v1/auth/verify-email/resend')->assertNoContent();
    $browser->post('/api/v1/auth/forgot-password', ['email' => $user['email']])->assertNoContent();

    foreach (Accounts::mailTo($user['email']) as $mail) {
        expect($mail['html'].$mail['text'])->not->toContain('Zebulon')->not->toContain('Quxwyvern');
    }
});

it('names the institution in the invitation but never the inviter [security review S6, slice 04]', function () {
    $owner = Accounts::user(['email' => 'inviter@example.test']);
    \Illuminate\Support\Facades\DB::connection(useMigratorConnection())->table('users')
        ->where('uuid', $owner['user'])->update(['name' => 'Zebulon Quxwyvern']);
    \Illuminate\Support\Facades\DB::connection(useMigratorConnection())->table('institutions')
        ->where('uuid', $owner['institution'])->update(['name' => 'Collège Les Hirondelles']);

    $browser = new AuthClient($this);
    $browser->login($owner['email'], $owner['password'])->assertOk();
    $browser->post('/api/v1/invitations', ['email' => 'guest@example.test', 'role' => 'manager'])->assertCreated();

    $mail = Accounts::mailTo('guest@example.test')[0];
    expect($mail['html'].$mail['text'])->not->toContain('Zebulon')->not->toContain('Quxwyvern')
        ->and($mail['html'].$mail['text'])->toContain('Les Hirondelles');
});
