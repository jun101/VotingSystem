<?php

/*
 * School mode (docs/slices/19c-installers.md step A, docs/api/README.md section 7a): no email is ever sent, the sign-up
 * and the link flows are closed, an owner creates the team with one-time passwords, a user who must change a
 * password can do nothing else, and the voter cookie's `Secure` flag follows the request.
 *
 * Online mode keeps the behaviour of slices 02 to 19; its tests are the existing ones.
 *
 * Tenant routes covered here (tests/Support/Tenancy.php): POST api/v1/users, POST api/v1/users/{user}/password-reset.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Team;
use Tests\Support\Voting;

beforeEach(function () {
    config(['deployment.mode' => 'school']);
});

const SCHOOL_PASSWORD_SHAPE = '/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}(-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}){2}$/';

/** Marks a planted user as having to change the password (the column of the migration of this slice). */
function schoolMustChange(string $email, bool $value = true): void
{
    DB::connection(useMigratorConnection())->table('users')->where('email', strtolower($email))->update(['must_change_password' => $value]);
}

function schoolOwner($test): array
{
    $t = Team::two();
    Team::signIn($test, $t['a']['owner']);

    return $t;
}

// ---- the closed routes ---------------------------------------------------------------------------------------

it('answers 404 on every link flow and sends nothing [NFR-SEC-01] (docs: School mode)', function (string $method, string $uri, array $body) {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->{$method}($uri, $body);

    $response->assertNotFound()->assertJsonPath('error.code', 'not_found');
    expect(Accounts::mail())->toBe([]);
})->with([
    'register' => ['post', '/api/v1/auth/register', ['institution_name' => 'Collège X', 'name' => 'Marie', 'email' => 'm@x.test', 'password' => 'un mot de passe long', 'language' => 'fr']],
    'verify email' => ['post', '/api/v1/auth/verify-email', ['token' => 'a']],
    'resend verification' => ['post', '/api/v1/auth/verify-email/resend', []],
    'forgot password' => ['post', '/api/v1/auth/forgot-password', ['email' => 'alice.a@example.test']],
    'reset password' => ['post', '/api/v1/auth/reset-password', ['token' => 'a', 'password' => 'un mot de passe long']],
    'accept invitation' => ['post', '/api/v1/auth/accept-invitation', ['token' => 'a', 'name' => 'X', 'password' => 'un mot de passe long']],
    'list invitations' => ['get', '/api/v1/invitations', []],
    'invite' => ['post', '/api/v1/invitations', ['email' => 'j@x.test', 'role' => 'manager']],
]);

it('keeps the link flows open in online mode [FR-INST-01]', function () {
    config(['deployment.mode' => 'online']);

    $this->browser->post('/api/v1/auth/register', [
        'institution_name' => 'Collège X', 'name' => 'Marie', 'email' => 'marie@x.test', 'password' => 'un mot de passe long', 'language' => 'fr',
    ])->assertCreated();
    expect(Accounts::mailTo('marie@x.test'))->toHaveCount(1);
});

// ---- POST /users ---------------------------------------------------------------------------------------------

it('creates a manager with a one-time password, in the caller\'s institution, with no email [FR-INST-03] (POST-users 1)', function () {
    $t = schoolOwner($this);

    $response = $this->browser->post('/api/v1/users', [
        'name' => '  Jean Pierre ', 'email' => 'J.Pierre@Ecole.Local', 'role' => 'manager', 'password' => $t['a']['owner']['password'],
    ])->assertCreated();

    expect(array_keys($response->json()))->toBe(['data', 'one_time_password'])
        ->and($response->json('data.name'))->toBe('Jean Pierre')
        ->and($response->json('data.email'))->toBe('j.pierre@ecole.local')
        ->and($response->json('data.role'))->toBe('manager')
        ->and($response->json('data.id'))->toMatch(UUID_V4)
        ->and($response->json('one_time_password'))->toMatch(SCHOOL_PASSWORD_SHAPE);

    $row = Accounts::userRow('j.pierre@ecole.local');
    expect($row['email_verified_at'])->not->toBeNull()
        ->and((bool) $row['must_change_password'])->toBeTrue()
        ->and(Hash::check($response->json('one_time_password'), $row['password']))->toBeTrue()
        ->and($row['uuid'])->toBe($response->json('data.id'))
        ->and(Accounts::mail())->toBe([]);
});

it('draws a different password each time [NFR-SEC-01] (POST-users 1)', function () {
    $t = schoolOwner($this);
    $a = $this->browser->post('/api/v1/users', ['name' => 'A', 'email' => 'a@ecole.local', 'role' => 'manager', 'password' => $t['a']['owner']['password']])->json('one_time_password');
    $b = $this->browser->post('/api/v1/users', ['name' => 'B', 'email' => 'b@ecole.local', 'role' => 'manager', 'password' => $t['a']['owner']['password']])->json('one_time_password');

    expect($a)->not->toBe($b);
});

it('creates an owner [FR-INST-03] (POST-users 2)', function () {
    $t = schoolOwner($this);

    $this->browser->post('/api/v1/users', ['name' => 'Co Owner', 'email' => 'co@ecole.local', 'role' => 'owner', 'password' => $t['a']['owner']['password']])
        ->assertCreated()->assertJsonPath('data.role', 'owner');
});

it('lets the new user sign in once, and only to change the password [NFR-SEC-01] (POST-users, side effects)', function () {
    $t = schoolOwner($this);
    $one = $this->browser->post('/api/v1/users', ['name' => 'Jean', 'email' => 'jean@ecole.local', 'role' => 'manager', 'password' => $t['a']['owner']['password']])->json('one_time_password');

    $person = new AuthClient($this);
    $person->login('jean@ecole.local', $one)->assertOk();

    $person->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    $person->get('/api/v1/elections')->assertForbidden()->assertJsonPath('error.code', 'password_change_required');
    $person->get('/api/v1/users')->assertForbidden()->assertJsonPath('error.code', 'password_change_required');

    $person->post('/api/v1/auth/change-password', ['current_password' => $one, 'password' => 'un nouveau mot de passe'])->assertNoContent();

    $person->get('/api/v1/elections')->assertOk();
    expect($person->get('/api/v1/auth/me')->json('data'))->not->toHaveKey('must_change_password');
});

it('validates the body [FR-INST-03] (POST-users 3 to 5)', function (array $body, string $field, string $code) {
    $t = schoolOwner($this);
    $body += ['password' => $t['a']['owner']['password']];

    $this->browser->post('/api/v1/users', $body)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath("error.fields.{$field}.0", $code);
})->with([
    'no name' => [['email' => 'a@e.local', 'role' => 'manager'], 'name', 'required'],
    'no email' => [['name' => 'A', 'role' => 'manager'], 'email', 'required'],
    'bad email' => [['name' => 'A', 'email' => 'not an address', 'role' => 'manager'], 'email', 'email'],
    'no role' => [['name' => 'A', 'email' => 'a@e.local'], 'role', 'required'],
    'bad role' => [['name' => 'A', 'email' => 'a@e.local', 'role' => 'platform_admin'], 'role', 'in'],
]);

it('refuses an address that already has an account, in any letter case [FR-INST-03] (POST-users 6)', function () {
    $t = schoolOwner($this);

    $this->browser->post('/api/v1/users', ['name' => 'A', 'email' => 'ALICE.A@example.test', 'role' => 'manager', 'password' => $t['a']['owner']['password']])
        ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'taken');
});

it('asks for the owner\'s own password [NFR-SEC-01] (POST-users 7)', function () {
    schoolOwner($this);

    $this->browser->post('/api/v1/users', ['name' => 'A', 'email' => 'a@e.local', 'role' => 'manager', 'password' => 'not the password'])
        ->assertStatus(422)->assertJsonPath('error.fields.password.0', 'incorrect');
    expect(Accounts::userRow('a@e.local'))->toBeNull();
});

it('refuses a manager [FR-INST-04] (POST-users 9)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post('/api/v1/users', ['name' => 'A', 'email' => 'a@e.local', 'role' => 'manager', 'password' => $t['a']['manager']['password']])
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('is not allowed in online mode, as before [FR-INST-03] (POST-users 10)', function () {
    config(['deployment.mode' => 'online']);
    $t = schoolOwner($this);

    $this->browser->post('/api/v1/users', ['name' => 'A', 'email' => 'a@e.local', 'role' => 'manager', 'password' => $t['a']['owner']['password']])
        ->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    expect(Accounts::userRow('a@e.local'))->toBeNull();
});

it('needs a session and a CSRF token [NFR-SEC-04] (POST-users 11, 13)', function () {
    $this->browser->post('/api/v1/users', ['name' => 'A'])->assertUnauthorized();

    schoolOwner($this);
    $this->browser->post('/api/v1/users', ['name' => 'A', 'email' => 'a@e.local', 'role' => 'manager', 'password' => 'x'], [], false)->assertStatus(419);
});

// ---- POST /users/{user}/password-reset -----------------------------------------------------------------------

it('gives a manager a new one-time password and ends their sessions [FR-INST-03] (password-reset 1)', function () {
    $t = schoolOwner($this);
    $manager = new AuthClient($this);
    $manager->login($t['a']['manager']['email'], $t['a']['manager']['password'])->assertOk();

    $response = $this->browser->post("/api/v1/users/{$t['a']['manager']['user']}/password-reset", ['password' => $t['a']['owner']['password']])->assertOk();

    expect(array_keys($response->json()))->toBe(['one_time_password'])
        ->and($response->json('one_time_password'))->toMatch(SCHOOL_PASSWORD_SHAPE);

    $manager->get('/api/v1/auth/me')->assertUnauthorized();
    (new AuthClient($this))->login($t['a']['manager']['email'], $t['a']['manager']['password'])->assertStatus(401);

    $again = new AuthClient($this);
    $again->login($t['a']['manager']['email'], $response->json('one_time_password'))->assertOk();
    $again->get('/api/v1/auth/me')->assertJsonPath('data.must_change_password', true);
    expect(Accounts::mail())->toBe([]);
});

it('does not reset oneself [FR-INST-03] (password-reset 3)', function () {
    $t = schoolOwner($this);

    $this->browser->post("/api/v1/users/{$t['a']['owner']['user']}/password-reset", ['password' => $t['a']['owner']['password']])
        ->assertStatus(409)->assertJsonPath('error.code', 'cannot_reset_self');
});

it('asks for the owner\'s password and answers 404 for another institution\'s user [FR-INST-03] (password-reset 4, 5)', function () {
    $t = schoolOwner($this);

    $this->browser->post("/api/v1/users/{$t['a']['manager']['user']}/password-reset", ['password' => 'wrong'])
        ->assertStatus(422)->assertJsonPath('error.fields.password.0', 'incorrect');
    $this->browser->post("/api/v1/users/{$t['b']['manager']['user']}/password-reset", ['password' => $t['a']['owner']['password']])
        ->assertNotFound();
});

it('refuses a manager and answers 404 in online mode [FR-INST-04] (password-reset 6, 7)', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['manager']);
    $this->browser->post("/api/v1/users/{$t['a']['owner']['user']}/password-reset", ['password' => $t['a']['manager']['password']])->assertForbidden();

    config(['deployment.mode' => 'online']);
    $owner = new AuthClient($this);
    $owner->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();
    $owner->post("/api/v1/users/{$t['a']['manager']['user']}/password-reset", ['password' => $t['a']['owner']['password']])->assertNotFound();
});

// ---- POST /auth/change-password ------------------------------------------------------------------------------

it('changes the password, ends the other sessions and keeps this one [NFR-SEC-01] (change-password 1)', function () {
    $t = schoolOwner($this);
    $other = new AuthClient($this);
    $other->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();

    $this->browser->post('/api/v1/auth/change-password', ['current_password' => $t['a']['owner']['password'], 'password' => 'un mot de passe tout neuf'])->assertNoContent();

    $this->browser->get('/api/v1/auth/me')->assertOk();
    $other->get('/api/v1/auth/me')->assertUnauthorized();
    (new AuthClient($this))->login($t['a']['owner']['email'], 'un mot de passe tout neuf')->assertOk();
    (new AuthClient($this))->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertStatus(401);
});

it('refuses a wrong current password, a short one, the email and the same password [NFR-SEC-01] (change-password 4 to 6)', function () {
    $t = schoolOwner($this);
    $current = $t['a']['owner']['password'];

    $this->browser->post('/api/v1/auth/change-password', ['current_password' => 'wrong', 'password' => 'un mot de passe tout neuf'])
        ->assertStatus(422)->assertJsonPath('error.fields.current_password.0', 'incorrect');
    $this->browser->post('/api/v1/auth/change-password', ['current_password' => $current, 'password' => 'short'])
        ->assertStatus(422)->assertJsonPath('error.fields.password.0', 'min');
    $this->browser->post('/api/v1/auth/change-password', ['current_password' => $current, 'password' => $t['a']['owner']['email']])
        ->assertStatus(422);
    $this->browser->post('/api/v1/auth/change-password', ['current_password' => $current, 'password' => $current])
        ->assertStatus(422)->assertJsonPath('error.fields.password.0', 'unchanged');
});

it('needs a session [NFR-SEC-01] (change-password 8)', function () {
    $this->browser->post('/api/v1/auth/change-password', ['current_password' => 'a', 'password' => 'b'])->assertUnauthorized();
});

it('is available in online mode too [NFR-SEC-01] (change-password)', function () {
    config(['deployment.mode' => 'online']);
    $t = schoolOwner($this);

    $this->browser->post('/api/v1/auth/change-password', ['current_password' => $t['a']['owner']['password'], 'password' => 'un mot de passe tout neuf'])->assertNoContent();
});

// ---- the email channel of the access codes -------------------------------------------------------------------

it('refuses the email channel of a credential run [FR-CRED-04] (credential-runs, School mode)', function () {
    $t = schoolOwner($this);
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => 'draft']);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 0])
        ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'unavailable');
});

// ---- the Secure flag -----------------------------------------------------------------------------------------

it('sets the voter cookie without Secure on a plain HTTP request in school mode [NFR-SEC-04] (README 7a)', function () {
    $e = Voting::election();
    $voter = Voting::browser($this);

    $response = Voting::enter($voter, $e['election'], $e['codes']['Wideline'])->assertStatus(201);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'vote_session');
    expect($cookie->isSecure())->toBeFalse()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict');
});

it('keeps the voter cookie Secure in online mode [NFR-SEC-04]', function () {
    config(['deployment.mode' => 'online']);
    $e = Voting::election();

    $response = Voting::enter(Voting::browser($this), $e['election'], $e['codes']['Wideline'])->assertStatus(201);

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'vote_session');
    expect($cookie->isSecure())->toBeTrue();
});
