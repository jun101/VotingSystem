<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\Browser;
use Tests\Support\Accounts;

beforeEach(fn () => Accounts::reset());

it('queues the two notifications, so the queue service sends them [FR-INST-01]', function () {
    expect(new VerifyEmailNotification('a'))->toBeInstanceOf(ShouldQueue::class)
        ->and(new ResetPasswordNotification('a'))->toBeInstanceOf(ShouldQueue::class);
});

it('sends the verification and the reset email once each, in the language of the user, with a text and an HTML part [NFR-UX-01]', function () {
    foreach (['fr' => ['vérif', 'initialis'], 'en' => ['verify', 'reset']] as $language => [$verify, $reset]) {
        $made = Accounts::user(['language' => $language, 'verified' => false, 'email' => "{$language}@example.test"]);
        $user = User::query()->where('uuid', $made['user'])->firstOrFail();

        $user->notify(new VerifyEmailNotification(str_repeat('a', 64)));
        $user->notify(new ResetPasswordNotification(str_repeat('b', 64)));

        [$first, $second] = Accounts::mailTo($user->email);

        expect(mb_strtolower($first['subject']))->toContain($verify)
            ->and(mb_strtolower($second['subject']))->toContain($reset)
            ->and($first['text'])->toContain('/verify-email?token='.str_repeat('a', 64))
            ->and($first['html'])->toContain('/verify-email?token='.str_repeat('a', 64))->toContain("lang=\"{$language}\"")
            ->and($second['text'])->toContain('/reset-password?token='.str_repeat('b', 64))
            ->and($second['html'])->toContain('/reset-password?token='.str_repeat('b', 64));
    }
});

it('does not escape the text part, so a link or a name with a symbol stays readable [NFR-UX-01]', function () {
    $made = Accounts::user(['verified' => false]);
    $user = User::query()->where('uuid', $made['user'])->firstOrFail();
    $user->name = 'Marie & O\'Neil';
    $user->save();

    $user->notify(new VerifyEmailNotification(str_repeat('c', 64)));

    $mail = Accounts::mailTo($user->email)[0];

    expect($mail['text'])->toContain('Marie & O\'Neil')->not->toContain('&amp;')->not->toContain('&#039;')
        ->and($mail['html'])->toContain('Marie &amp; O&#039;Neil');
});

it('sends from the address set in the environment [FR-INST-01]', function () {
    expect(config('mail.from.address'))->toBe(env('MAIL_FROM_ADDRESS', 'no-reply@localhost'));
});

it('has a French and an English text for every key of the mail files [NFR-UX-01]', function () {
    $fr = require base_path('lang/fr/mail.php');
    $en = require base_path('lang/en/mail.php');

    expect(Arr::dot($fr))->toHaveKeys(array_keys(Arr::dot($en)))
        ->and(Arr::dot($en))->toHaveKeys(array_keys(Arr::dot($fr)));
});

it('has a French and an English message for every error code of the auth endpoints [NFR-UX-01]', function () {
    $fr = require base_path('lang/fr/errors.php');
    $en = require base_path('lang/en/errors.php');

    foreach (['invalid_credentials', 'institution_suspended', 'already_verified', 'unauthenticated', 'expired', 'csrf_mismatch', 'too_many_attempts', 'validation_failed', 'malformed_request', 'method_not_allowed'] as $code) {
        expect($fr)->toHaveKey($code)->and($en)->toHaveKey($code)
            ->and($fr[$code])->not->toBe($en[$code]);
    }
});

it('does not send anything when the notification is faked and the address is unknown [FR-INST-04]', function () {
    Notification::fake();

    $browser = new Browser($this);
    $browser->post('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertNoContent();

    Notification::assertNothingSent();
});
