<?php

/*
 * GET /api/v1/elections/{election}/credentials — docs/api/credentials/GET-elections-{election}-credentials.md
 * Tenant suite, route api/v1/elections/{election}/credentials: another institution's election answers 404, the same as
 * an unknown one.
 */

use Tests\Support\Accounts;
use Tests\Support\Team;

const STATE_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function stateUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/credentials";
}

/** An owner of A signed in and an election of the given status. Returns [team, election]. */
function stateSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election];
}

it('counts the voters, the codes and the emails, in total and per group [FR-CRED-01] (scenario 1)', function () {
    [$t, $election] = stateSignedIn($this);
    $fourth = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $third = Accounts::plantGroup(['election' => $election, 'name' => '3e année']);
    $a = Accounts::plantVoter(['election' => $election, 'full_name' => 'Aline', 'group' => $fourth, 'email' => 'aline@example.ht']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Bruno', 'group' => $fourth]);
    $c = Accounts::plantVoter(['election' => $election, 'full_name' => 'Carole', 'group' => $third, 'email' => 'carole@example.ht']);
    Accounts::plantCredential(['voter' => $a]);
    Accounts::plantCredential(['voter' => $c]);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'group' => $fourth]);
    Accounts::plantGeneratedFile(['election' => $election, 'group' => $third, 'status' => 'failed']);

    $response = $this->browser->get(stateUrl($election))->assertOk();

    expect($response->json('data'))->toMatchArray(['voters' => 3, 'with_code' => 2, 'without_code' => 1, 'with_email' => 2, 'run_in_progress' => false])
        ->and($response->json('data.groups'))->toBe([
            ['id' => $third, 'name' => '3e année', 'voters' => 1, 'with_code' => 1, 'with_email' => 1, 'files_ready' => 0],
            ['id' => $fourth, 'name' => '4e année', 'voters' => 2, 'with_code' => 1, 'with_email' => 1, 'files_ready' => 1],
        ]);
    expect($file)->toBeString();
});

it('answers zero everywhere for an election with no voter [FR-CRED-01] (scenario 2)', function () {
    [$t, $election] = stateSignedIn($this);

    $response = $this->browser->get(stateUrl($election))->assertOk();

    expect($response->json('data'))->toBe(['voters' => 0, 'with_code' => 0, 'without_code' => 0, 'with_email' => 0, 'run_in_progress' => false, 'groups' => []]);
});

it('lists the voters with no group on a last line with a null id and name [FR-CRED-01] (scenario 3)', function () {
    [$t, $election] = stateSignedIn($this);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Zèbres']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Dans le groupe', 'group' => $group]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe un']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Sans groupe deux', 'email' => 'deux@example.ht']);

    $groups = $this->browser->get(stateUrl($election))->assertOk()->json('data.groups');

    expect($groups)->toHaveCount(2)
        ->and($groups[1])->toBe(['id' => null, 'name' => null, 'voters' => 2, 'with_code' => 0, 'with_email' => 1, 'files_ready' => 0]);
});

it('sorts the groups by name with accents and case ignored [FR-CRED-01] (scenario 1)', function () {
    [$t, $election] = stateSignedIn($this);
    foreach (['Épsilon', 'alpha', 'Zêta', 'Beta'] as $name) {
        Accounts::plantVoter(['election' => $election, 'full_name' => "V {$name}", 'group' => Accounts::plantGroup(['election' => $election, 'name' => $name])]);
    }

    $names = array_column($this->browser->get(stateUrl($election))->assertOk()->json('data.groups'), 'name');

    expect($names)->toBe(['alpha', 'Beta', 'Épsilon', 'Zêta']);
});

it('says a run is in progress while a file is queued [FR-CRED-04] (scenario 4)', function () {
    [$t, $election] = stateSignedIn($this);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Attend']);
    Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued']);

    $this->browser->get(stateUrl($election))->assertOk()->assertJsonPath('data.run_in_progress', true);
});

it('answers for a closed, published or archived election, with no file ready [FR-CRED-05] (scenario 5)', function (string $status) {
    [$t, $election] = stateSignedIn($this, $status);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Fini']);
    Accounts::plantCredential(['voter' => $voter]);
    Accounts::plantGeneratedFile(['election' => $election]);

    $response = $this->browser->get(stateUrl($election))->assertOk();

    expect($response->json('data'))->toMatchArray(['voters' => 1, 'with_code' => 1])
        ->and($response->json('data.groups.0.files_ready'))->toBe(0);
})->with(['closed', 'published', 'archived']);

it('never counts the voters or codes of another election [FR-INST-05] (scenario 1)', function () {
    [$t, $election] = stateSignedIn($this);
    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre']);
    $voter = Accounts::plantVoter(['election' => $other, 'full_name' => 'Ailleurs']);
    Accounts::plantCredential(['voter' => $voter]);

    $this->browser->get(stateUrl($election))->assertOk()->assertJsonPath('data.voters', 0)->assertJsonPath('data.with_code', 0);
});

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 6)', function () {
    [$t, $election] = stateSignedIn($this);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);

    $unknown = Team::shape($this->browser->get(stateUrl(STATE_UNKNOWN)));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->get(stateUrl($target))))->toBe($unknown);
    }
});

it('answers 401 when nobody is signed in [FR-CRED-01] (scenario 7)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);

    $this->browser->get(stateUrl($election))->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('answers 403 for a suspended institution and 405 for another method [NFR-SEC-04] (scenarios 8, 9)', function () {
    [$t, $election] = stateSignedIn($this);

    $this->browser->other('POST', stateUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->get(stateUrl($election))->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('lets a manager read it [FR-CRED-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->get(stateUrl($election))->assertOk();
});
