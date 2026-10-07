<?php

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Arr;

/** @return array<string, mixed> */
function layoutData(array $override = []): array
{
    return array_merge([
        'title' => 'A title',
        'line' => 'A line',
        'preheader' => 'The preview text of the message',
        'intro' => 'The sentence.',
        'button' => 'Press here',
        'url' => 'http://localhost:8080/verify-email?token='.str_repeat('a', 64),
        'validity' => 'Valid for a while.',
        'ignore' => 'Ignore it if it is not you.',
    ], $override);
}

it('puts the same parts, in the same order, in the text view as in the layout [docs/design/email.md section 2]', function () {
    $text = view('mail.action-text', layoutData())->render();

    $positions = array_map(fn ($part) => strpos($text, $part), ['A title', 'A line', 'The sentence.', 'Press here', 'http://localhost:8080/verify-email', 'Valid for a while.', 'Ignore it if it is not you.']);

    expect($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
    expect(preg_match('/^http:\/\/localhost:8080\/verify-email\?token=a{64}$/m', $text))->toBe(1);
});

it('escapes every text and puts the link in the button, the Outlook button and the fallback [docs/design/email.md section 1]', function () {
    $html = view('mail.action', layoutData(['title' => '<b>x</b>', 'button' => 'A & B']))->render();

    expect($html)->not->toContain('<b>x</b>')->toContain('&lt;b&gt;x&lt;/b&gt;')->toContain('A &amp; B')
        ->and(substr_count($html, 'href="http://localhost:8080/verify-email?token='.str_repeat('a', 64).'"'))->toBe(3);
    expect(strtolower(substr($html, 0, 15)))->toBe('<!doctype html>');
});

it('has the layout texts in both languages for both emails [NFR-UX-01]', function () {
    foreach (['fr', 'en'] as $language) {
        $mail = Arr::dot(require base_path("lang/{$language}/mail.php"));

        foreach (['verify', 'reset'] as $kind) {
            foreach (['subject', 'title', 'line', 'preheader'] as $key) {
                expect($mail["{$kind}.{$key}"] ?? '')->not->toBe('');
            }
        }
    }
});

it('gives both notifications the data of the layout', function () {
    $user = (object) ['email' => 'someone@example.test'];

    foreach ([new VerifyEmailNotification(str_repeat('a', 64)), new ResetPasswordNotification(str_repeat('b', 64))] as $notification) {
        $data = $notification->toMail($user)->viewData;

        expect($data)->toHaveKeys(['title', 'line', 'preheader', 'intro', 'button', 'url', 'validity', 'ignore']);
    }
});
