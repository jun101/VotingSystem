<?php

/*
 * NFR-OPS-04: the application logs never hold a code, a password, a token or a vote, over a whole voting run
 * (sign-up, sign-in, election, code entry, vote, close, audit).
 */

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Voting;

it('logs nothing secret over a voting run [NFR-OPS-04, FR-SEC-09]', function () {
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$lines): void {
        $lines[] = $log->level.' '.$log->message.' '.json_encode($log->context, JSON_UNESCAPED_UNICODE);
    });

    $e = Voting::election();
    $voter = Voting::browser($this);
    Voting::enter($voter, $e['election'], $e['codes']['Paul'])->assertStatus(201);
    $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e), [], false)->assertStatus(201);
    Voting::enter(Voting::browser($this), $e['election'], 'WRONG-CODE-0000');
    $user = Accounts::user(['email' => 'secret.person@example.test']);
    (new AuthClient($this))->login($user['email'], 'not the right password at all')->assertStatus(401);
    (new AuthClient($this))->login($user['email'], $user['password'])->assertOk();
    Artisan::call('elections:advance');

    $log = implode("\n", $lines);
    $forbidden = [
        $e['codes']['Paul'], $e['codes']['Wideline'], $e['codes']['Marc'], 'WRONG-CODE-0000',
        $user['password'], 'not the right password at all',
        $e['candidates']['A'], $e['candidates']['D'], $e['candidates']['E'],
        'Paul Étienne', 'Candidat A', 'secret.person@example.test',
    ];
    foreach ($forbidden as $secret) {
        expect($log)->not->toContain($secret);
    }
    expect($log)->not->toMatch('/\bpassword"?\s*[:=]\s*"[^"*]+"/i');
});
