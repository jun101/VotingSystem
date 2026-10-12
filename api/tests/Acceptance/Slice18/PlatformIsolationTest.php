<?php

/*
 * FR-ADM-02, FR-SEC-03: the platform admin reaches no vote, credential or voter data of any institution, and no route
 * lets anyone create a platform admin.
 */

use Illuminate\Support\Facades\Route;
use Tests\Support\Accounts;
use Tests\Support\Team;
use Tests\Support\Voting;

require_once __DIR__.'/ListInstitutionsTest.php';

it('answers the platform admin 403, 404 or an empty list, never data, on the tenant routes of an institution [FR-ADM-02]', function (string $path) {
    $e = Voting::election(['status' => 'closed']);
    platformSignedIn($this);

    $response = $this->browser->get(str_replace('{election}', $e['election'], $path));

    // A list answers 200 with nothing in it (the scope matches no institution); a record answers 403 or 404.
    expect($response->getStatusCode())->toBeIn([200, 403, 404])
        ->and($response->getStatusCode() === 200 ? $response->json('data') : [])->toBe([])
        ->and($response->getContent())->not->toContain('Wideline')->and($response->getContent())->not->toContain($e['codes']['Wideline']);
})->with([
    'election' => ['/api/v1/elections/{election}'],
    'voters' => ['/api/v1/elections/{election}/voters'],
    'results' => ['/api/v1/elections/{election}/results'],
    'turnout' => ['/api/v1/elections/{election}/turnout'],
    'credentials' => ['/api/v1/elections/{election}/credentials'],
    'voters export' => ['/api/v1/elections/{election}/voters/export?format=csv'],
    'audit' => ['/api/v1/audit'],
    'users' => ['/api/v1/users'],
]);

it('has no route under /platform that returns votes, credentials or voter data [FR-ADM-02]', function () {
    $paths = [];
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/platform')) {
            $paths[] = $route->uri();
        }
    }
    sort($paths);

    expect($paths)->toBe([
        'api/v1/platform/institutions',
        'api/v1/platform/institutions/{institution}/reactivate',
        'api/v1/platform/institutions/{institution}/suspend',
    ]);
});

it('gives a platform admin the role platform_admin and no institution at /auth/me [FR-ADM-01]', function () {
    platformSignedIn($this);

    $this->browser->get('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'platform_admin')->assertJsonPath('data.institution', null);
});

it('does not let anyone become a platform admin through the API [NFR-SEC-04]', function () {
    $t = Team::two();
    Team::signIn($this, $t['a']['owner']);

    $this->browser->patch('/api/v1/auth/me', ['role' => 'platform_admin']);
    $this->browser->post('/api/v1/invitations', ['email' => 'new@example.test', 'role' => 'platform_admin']);

    expect(Accounts::userRow($t['a']['owner']['email'])['role'])->toBe('owner')
        ->and(Accounts::userRow('new@example.test'))->toBeNull();
});
