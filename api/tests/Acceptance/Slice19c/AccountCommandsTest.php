<?php

/*
 * owner:create, user:reset-password, user:list — docs/api/system/COMMAND-school-accounts.md (slice 19c, step B).
 * The operator's commands of an installation with no email: nobody signs in, the output names things by uuid, and no
 * password is ever logged.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;

const COMMAND_PASSWORD_SHAPE = '/One-time password: ([ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}(?:-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}){2})/';

/** The file's lines are written in French typography: a space before the colon. */
const COMMAND_FILE_PASSWORD_SHAPE = '/One-time password : ([ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}(?:-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}){2})/';

function commandFile(): string
{
    return sys_get_temp_dir().'/votesystem-credentials-'.bin2hex(random_bytes(4)).'.txt';
}

it('creates an institution and its owner with a one-time password, printed once [FR-INST-01] (owner:create 1)', function () {
    $exit = Artisan::call('owner:create', ['email' => 'Directeur@Ecole.Local', '--institution' => 'Collège Les Pins', '--name' => 'Directeur Pierre']);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toMatch(COMMAND_PASSWORD_SHAPE);
    preg_match(COMMAND_PASSWORD_SHAPE, $output, $found);
    preg_match('/Owner: ([0-9a-f-]{36})/', $output, $uuid);

    $row = Accounts::userRow('directeur@ecole.local');
    expect($row['role'])->toBe('owner')
        ->and($row['uuid'])->toBe($uuid[1])
        ->and($row['name'])->toBe('Directeur Pierre')
        ->and($row['email_verified_at'])->not->toBeNull()
        ->and((bool) $row['must_change_password'])->toBeTrue()
        ->and(Hash::check($found[1], $row['password']))->toBeTrue();

    $institution = Accounts::institutionRow(Accounts::userRowByUuid($uuid[1])['institution_uuid'] ?? '');
    expect($institution === null || $institution['name'] === 'Collège Les Pins')->toBeTrue();
});

it('signs the new owner in, and only to change the password [NFR-SEC-01] (owner:create 1)', function () {
    Artisan::call('owner:create', ['email' => 'dir@ecole.local', '--institution' => 'Collège Les Pins']);
    preg_match(COMMAND_PASSWORD_SHAPE, Artisan::output(), $found);

    $browser = new AuthClient($this);
    $browser->login('dir@ecole.local', $found[1])->assertOk();

    $browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true)->assertJsonPath('data.role', 'owner');
    $browser->get('/api/v1/elections')->assertForbidden()->assertJsonPath('error.code', 'password_change_required');
});

it('appends the credentials to a private file instead of printing the password [NFR-SEC-01] (owner:create 2)', function () {
    $file = commandFile();

    $exit = Artisan::call('owner:create', ['email' => 'dir@ecole.local', '--institution' => 'Collège Les Pins', '--credentials-file' => $file]);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->not->toMatch(COMMAND_PASSWORD_SHAPE);
    expect(file_exists($file))->toBeTrue()
        ->and(decoct(fileperms($file) & 0777))->toBe('600')
        ->and(file_get_contents($file))->toMatch(COMMAND_FILE_PASSWORD_SHAPE)
        ->toContain('dir@ecole.local');
    preg_match(COMMAND_FILE_PASSWORD_SHAPE, file_get_contents($file), $found);
    expect(Hash::check($found[1], Accounts::userRow('dir@ecole.local')['password']))->toBeTrue();

    // A second owner is appended, not written over the first.
    Artisan::call('owner:create', ['email' => 'dir2@ecole.local', '--institution' => 'Autre', '--credentials-file' => $file]);
    expect(file_get_contents($file))->toContain('dir@ecole.local')->toContain('dir2@ecole.local');
    unlink($file);
});

it('adds an owner to an existing institution [FR-INST-03] (owner:create 3)', function () {
    $existing = Accounts::user(['email' => 'first@ecole.local']);

    $exit = Artisan::call('owner:create', ['email' => 'second@ecole.local', '--institution-uuid' => $existing['institution']]);

    expect($exit)->toBe(0);
    $second = Accounts::userRow('second@ecole.local');
    $first = Accounts::userRow('first@ecole.local');
    expect($second['institution_id'])->toBe($first['institution_id'])->and($second['role'])->toBe('owner');
});

it('refuses and creates nothing [FR-INST-01] (owner:create 4 to 7)', function (array $arguments) {
    Accounts::user(['email' => 'taken@ecole.local']);
    $before = count(Accounts::mail());

    $exit = Artisan::call('owner:create', $arguments);

    expect($exit)->toBe(1)->and(Accounts::userRow('new@ecole.local'))->toBeNull();
    expect(count(Accounts::mail()))->toBe($before);
})->with([
    'unknown institution' => [['email' => 'new@ecole.local', '--institution-uuid' => '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']],
    'not an address' => [['email' => 'not an address', '--institution' => 'X']],
    'no institution' => [['email' => 'new@ecole.local']],
    'bad language' => [['email' => 'new@ecole.local', '--institution' => 'X', '--language' => 'es']],
    'taken address' => [['email' => 'TAKEN@ecole.local', '--institution' => 'X']],
]);

it('gives a user a new one-time password and ends their sessions [NFR-SEC-01] (user:reset-password 1)', function () {
    $user = Accounts::user(['email' => 'prof@ecole.local', 'role' => 'manager']);
    $browser = new AuthClient($this);
    $browser->login($user['email'], $user['password'])->assertOk();

    $exit = Artisan::call('user:reset-password', ['email' => 'PROF@ecole.local']);
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toMatch(COMMAND_PASSWORD_SHAPE);
    preg_match(COMMAND_PASSWORD_SHAPE, $output, $found);
    expect(Hash::check($found[1], Accounts::userRow('prof@ecole.local')['password']))->toBeTrue()
        ->and((bool) Accounts::userRow('prof@ecole.local')['must_change_password'])->toBeTrue();

    $browser->get('/api/v1/auth/me')->assertUnauthorized();
    (new AuthClient($this))->login($user['email'], $user['password'])->assertStatus(401);
    (new AuthClient($this))->login($user['email'], $found[1])->assertOk();
});

it('resets a platform admin too [FR-ADM-01] (user:reset-password 1)', function () {
    $admin = Accounts::user(['email' => 'admin@platform.test', 'role' => 'platform_admin']);

    expect(Artisan::call('user:reset-password', ['email' => $admin['email']]))->toBe(0);
});

it('says the same for an unknown or removed address [NFR-SEC-01] (user:reset-password 2)', function () {
    Accounts::user(['email' => 'gone@ecole.local', 'removed' => true]);

    expect(Artisan::call('user:reset-password', ['email' => 'nobody@ecole.local']))->toBe(1);
    $unknown = Artisan::output();
    expect(Artisan::call('user:reset-password', ['email' => 'gone@ecole.local']))->toBe(1);

    expect(Artisan::output())->toBe($unknown)->and($unknown)->toContain('No user has this address.');
});

it('never writes a password to the log [NFR-OPS-04] (owner:create, user:reset-password)', function () {
    $lines = [];
    Log::listen(function ($event) use (&$lines): void {
        $lines[] = $event->message.json_encode($event->context);
    });

    Artisan::call('owner:create', ['email' => 'dir@ecole.local', '--institution' => 'Collège Les Pins']);
    preg_match(COMMAND_PASSWORD_SHAPE, Artisan::output(), $first);
    Artisan::call('user:reset-password', ['email' => 'dir@ecole.local']);
    preg_match(COMMAND_PASSWORD_SHAPE, Artisan::output(), $second);

    $log = implode("\n", $lines);
    expect($log)->not->toContain($first[1])->not->toContain($second[1])->not->toContain('dir@ecole.local');
});

it('lists the users with no id [FR-INST-03] (user:list)', function () {
    $a = Accounts::user(['email' => 'alice@ecole.local', 'name' => 'Alice Owner']);
    Accounts::user(['email' => 'armand@ecole.local', 'name' => 'Armand Manager', 'role' => 'manager', 'institution' => $a['institution']]);
    Accounts::user(['email' => 'bruno@autre.local', 'name' => 'Bruno Other']);

    expect(Artisan::call('user:list'))->toBe(0);
    $all = Artisan::output();
    expect($all)->toContain('alice@ecole.local')->toContain('armand@ecole.local')->toContain('bruno@autre.local')
        ->not->toContain($a['user'])->not->toContain($a['institution']);

    expect(Artisan::call('user:list', ['--institution' => $a['institution']]))->toBe(0);
    expect(Artisan::output())->toContain('alice@ecole.local')->not->toContain('bruno@autre.local');

    expect(Artisan::call('user:list', ['--institution' => '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa']))->toBe(1);
});

it('sends a test message to the given address with the installation\'s mail settings [OPS-MAIL] (mail:test 1)', function () {
    Illuminate\Support\Facades\Mail::fake();

    $exit = Artisan::call('mail:test', ['to' => 'Admin@Example.HT']);

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('admin@example.ht');
    Illuminate\Support\Facades\Mail::assertSentCount(0);
});

it('refuses an address that is not valid and sends nothing [OPS-MAIL] (mail:test 2)', function () {
    Illuminate\Support\Facades\Mail::fake();

    expect(Artisan::call('mail:test', ['to' => 'not-an-address']))->toBe(1);
});

it('says what failed when the mail server cannot be reached, without a password [OPS-MAIL] (mail:test 3)', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.password' => 'top-secret-password',
    ]);
    app('mail.manager')->purge('smtp');

    $exit = Artisan::call('mail:test', ['to' => 'admin@example.ht']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('could not be sent')->not->toContain('top-secret-password');
});
