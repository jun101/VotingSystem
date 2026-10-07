<?php

/*
 * What every endpoint of the slice shares — docs/slices/02-sign-up-and-sign-in.md,
 * "Rules checked by tests".
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;

it('writes nothing about a person, a password or a token in the logs [NFR-OPS-04] (rule 2)', function () {
    $lines = [];
    Log::listen(function ($event) use (&$lines) {
        $lines[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });

    $password = 'a very private password, 12+';
    $email = 'private.person@example.test';

    // Register, sign out, sign in (right and wrong), forgot, reset, verify, resend.
    $this->browser->post('/api/v1/auth/register', [
        'institution_name' => 'Collège Secret', 'name' => 'Private Person', 'email' => $email, 'password' => $password, 'language' => 'fr',
    ])->assertCreated();
    $verifyToken = Accounts::tokenIn(Accounts::mailTo($email)[0], '/verify-email');
    $this->browser->post('/api/v1/auth/verify-email/resend')->assertNoContent();
    $this->browser->post('/api/v1/auth/logout')->assertNoContent();
    $this->browser->login($email, 'a wrong password, long enough')->assertStatus(401);
    $this->browser->login($email, $password)->assertOk();
    $this->browser->post('/api/v1/auth/logout')->assertNoContent();
    $this->browser->post('/api/v1/auth/forgot-password', ['email' => $email])->assertNoContent();
    $resetToken = Accounts::tokenIn(array_values(array_filter(Accounts::mailTo($email), fn ($m) => Accounts::tokenIn($m, '/reset-password') !== null))[0], '/reset-password');
    $this->browser->post('/api/v1/auth/reset-password', ['token' => $resetToken, 'password' => 'the new private password'])->assertNoContent();
    $this->browser->post('/api/v1/auth/verify-email', ['token' => $verifyToken])->assertStatus(422);

    $log = implode("\n", $lines);

    foreach ([$email, 'Private Person', $password, 'the new private password', 'wrong password', $verifyToken, $resetToken] as $secret) {
        expect($log)->not->toContain((string) $secret);
    }
});

it('stores only hashes of the tokens, never the token [NFR-SEC-02] (rule 2)', function () {
    $user = Accounts::user(['verified' => false]);
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->post('/api/v1/auth/verify-email/resend')->assertNoContent();
    $this->browser->post('/api/v1/auth/forgot-password', ['email' => $user['email']])->assertNoContent();

    $mails = Accounts::mailTo($user['email']);
    $tokens = [
        Accounts::tokenIn($mails[0], '/verify-email'),
        Accounts::tokenIn($mails[1], '/reset-password'),
    ];

    $db = DB::connection(useMigratorConnection());
    foreach (['email_verification_tokens', 'password_reset_tokens'] as $table) {
        foreach ($db->table($table)->get() as $row) {
            $stored = bin2hex((string) $row->token_hash).'|'.json_encode(array_map(fn ($v) => is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v, (array) $row));
            foreach ($tokens as $token) {
                expect($stored)->not->toContain($token);
            }
            expect(strlen((string) $row->token_hash))->toBe(32);
        }
    }
});

it('puts no database id in the emails, and only a token in their links [NFR-SEC-08] (rule 3)', function () {
    $user = Accounts::user(['verified' => false, 'email' => 'someone@example.test']);
    $this->browser->login($user['email'], $user['password'])->assertOk();
    $this->browser->post('/api/v1/auth/verify-email/resend')->assertNoContent();
    $this->browser->post('/api/v1/auth/forgot-password', ['email' => $user['email']])->assertNoContent();

    $row = Accounts::userRow($user['email']);

    foreach (Accounts::mailTo($user['email']) as $mail) {
        $body = $mail['text']."\n".$mail['html'];

        preg_match_all('#https?://[^\s"<>)]+#', $body, $links);
        expect($links[0])->not->toBe([]);

        foreach ($links[0] as $link) {
            expect($link)->toStartWith(rtrim((string) config('app.url'), '/'))
                ->and($link)->not->toMatch('#/\d+(/|\?|$)#')
                ->and($link)->not->toContain('id='.$row['id'])
                ->and($link)->not->toContain($user['email'])
                ->and($link)->not->toContain($user['user']);
        }
    }
});

it('hashes the password with Argon2id [NFR-SEC-02] (rule 2)', function () {
    $this->browser->post('/api/v1/auth/register', [
        'institution_name' => 'Collège', 'name' => 'Marie', 'email' => 'm@example.test', 'password' => 'a long enough password',
    ])->assertCreated();

    $hash = Accounts::userRow('m@example.test')['password'];

    expect($hash)->toStartWith('$argon2id$')->and(password_verify('a long enough password', $hash))->toBeTrue();
});

it('gives the same answer for sign-in failures on an existing and an unknown email [NFR-SEC-02] (rule 4)', function () {
    $user = Accounts::user();

    $wrong = (new AuthClient($this))->login($user['email'], 'a wrong password, long enough');
    $unknown = (new AuthClient($this))->login('nobody@example.test', 'a wrong password, long enough');

    expect($wrong->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($wrong->json())->toBe($unknown->json())
        ->and($wrong->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'));
});

it('answers every endpoint of the slice in the error shape, in French by default and English on request [NFR-UX-01]', function () {
    $fr = $this->browser->get('/api/v1/auth/me');
    $en = (new AuthClient($this))->get('/api/v1/auth/me', ['Accept-Language' => 'en']);

    expect($fr->json('error.code'))->toBe('unauthenticated')
        ->and($en->json('error.code'))->toBe('unauthenticated')
        ->and($fr->json('error.message'))->not->toBe($en->json('error.message'))
        ->and($fr->headers->get('X-Request-Id'))->toMatch(UUID_V4);
});

it('never sets a cookie on a refused CSRF request that would sign anybody in [NFR-SEC-04]', function () {
    $user = Accounts::user();

    $this->browser->post('/api/v1/auth/login', ['email' => $user['email'], 'password' => $user['password']], [], csrf: false)
        ->assertStatus(419);

    $this->browser->get('/api/v1/auth/me')->assertStatus(401);
});
