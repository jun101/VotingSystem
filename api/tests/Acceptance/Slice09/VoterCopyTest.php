<?php

/*
 * POST /api/v1/elections/{election}/voter-copies — docs/api/voters/POST-elections-{election}-voter-copies.md
 * Tenant suite, route api/v1/elections/{election}/voter-copies: another institution's target answers 404, the same as an
 * unknown one.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Accounts;
use Tests\Support\Team;

const COPY_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function copyUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/voter-copies";
}

/**
 * An owner of A signed in; a source election (draft) with groups "4e année" (2 voters) and "5e année" (1 voter) and 1
 * ungrouped voter; an empty target election of the given status. Returns [team, source, target, g4, g5].
 */
function copySignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $institution = $t['a']['owner']['institution'];
    $source = Accounts::plantElection(['institution' => $institution, 'title' => 'Année dernière', 'status' => 'closed']);
    $g4 = Accounts::plantGroup(['election' => $source, 'name' => '4e année']);
    $g5 = Accounts::plantGroup(['election' => $source, 'name' => '5e année']);
    Accounts::plantVoter(['election' => $source, 'full_name' => 'Rose', 'group' => $g4, 'identifier' => 'E-1', 'email' => 'rose@example.ht', 'phone' => '+509 1111 1111']);
    Accounts::plantVoter(['election' => $source, 'full_name' => 'Jean', 'group' => $g4, 'identifier' => 'E-2']);
    Accounts::plantVoter(['election' => $source, 'full_name' => 'Nadège', 'group' => $g5, 'identifier' => 'E-3']);
    Accounts::plantVoter(['election' => $source, 'full_name' => 'Sans groupe', 'identifier' => 'E-4']);
    $target = Accounts::plantElection(['institution' => $institution, 'title' => 'Cette année', 'status' => $status]);
    Team::signIn($test, $t['a']['owner']);

    return [$t, $source, $target, $g4, $g5];
}

function copyNames(string $election): array
{
    return collect(Accounts::voterRows($election))->pluck('full_name')->sort()->values()->all();
}

it('copies every voter, ungrouped included, with their groups and fields [FR-VOT-05] (scenario 1)', function (string $status) {
    [$t, $source, $target] = copySignedIn($this, $status);

    $response = $this->browser->post(copyUrl($target), ['source' => $source])->assertCreated();

    expect($response->json('data'))->toBe(['copied' => 4, 'skipped' => 0, 'groups_created' => 2])
        ->and(copyNames($target))->toBe(['Jean', 'Nadège', 'Rose', 'Sans groupe'])
        ->and(collect(Accounts::groupRows($target))->pluck('name')->all())->toBe(['4e année', '5e année']);
    $rose = collect(Accounts::voterRows($target))->firstWhere('full_name', 'Rose');
    expect($rose)->toMatchArray(['identifier' => 'E-1', 'email' => 'rose@example.ht', 'phone' => '+509 1111 1111'])
        ->and($rose['voter_group_id'])->not->toBeNull()
        ->and(Accounts::voterRows($source))->toHaveCount(4)
        ->and(Accounts::groupRows($source))->toHaveCount(2);
})->with(['draft', 'scheduled']);

it('copies only the chosen groups, and the ungrouped voters only when asked [FR-VOT-05] (scenario 2)', function () {
    [$t, $source, $target, $g4] = copySignedIn($this);

    $only = $this->browser->post(copyUrl($target), ['source' => $source, 'groups' => [$g4]])->assertCreated();
    expect($only->json('data'))->toBe(['copied' => 2, 'skipped' => 0, 'groups_created' => 1])
        ->and(copyNames($target))->toBe(['Jean', 'Rose']);

    $other = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre cible']);
    $with = $this->browser->post(copyUrl($other), ['source' => $source, 'groups' => [$g4], 'include_ungrouped' => true])->assertCreated();
    expect($with->json('data.copied'))->toBe(3)
        ->and(copyNames($other))->toBe(['Jean', 'Rose', 'Sans groupe']);

    $ungroupedOnly = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Sans groupes']);
    $this->browser->post(copyUrl($ungroupedOnly), ['source' => $source, 'groups' => [], 'include_ungrouped' => true])->assertCreated()
        ->assertJsonPath('data.copied', 1);
});

it('renames a group during the copy [FR-VOT-05] (scenario 3)', function () {
    [$t, $source, $target, $g4, $g5] = copySignedIn($this);

    $response = $this->browser->post(copyUrl($target), ['source' => $source, 'rename' => [$g4 => '5e année', $g5 => '6e année']])->assertCreated();

    // "4e année" becomes "5e année" and "5e année" becomes "6e année": each voter lands in the group of the new name.
    $names = collect(Accounts::groupRows($target))->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['5e année', '6e année'])
        ->and($response->json('data.groups_created'))->toBe(2);
    $byName = collect(Accounts::groupRows($target))->keyBy('name');
    $voters = collect(Accounts::voterRows($target))->keyBy('full_name');
    expect($voters['Rose']['voter_group_id'])->toBe($byName['5e année']['id'])
        ->and($voters['Jean']['voter_group_id'])->toBe($byName['5e année']['id'])
        ->and($voters['Nadège']['voter_group_id'])->toBe($byName['6e année']['id']);
});

it('joins the group the target already has when the name is the same [FR-VOT-05] (scenario 4)', function () {
    [$t, $source, $target, $g4] = copySignedIn($this);
    $existing = Accounts::plantGroup(['election' => $target, 'name' => '5E ANNÉE']);

    $response = $this->browser->post(copyUrl($target), ['source' => $source, 'groups' => [$g4], 'rename' => [$g4 => '5e année']])->assertCreated();

    expect($response->json('data.groups_created'))->toBe(0)
        ->and(Accounts::groupRows($target))->toHaveCount(1)
        ->and(collect(Accounts::voterRows($target))->pluck('voter_group_id')->unique()->all())
        ->toBe([Accounts::groupRow($existing)['id']]);
});

it('skips the voters whose identifier or email the target already has, and counts them [FR-VOT-05] (scenario 5)', function () {
    [$t, $source, $target] = copySignedIn($this);
    Accounts::plantVoter(['election' => $target, 'full_name' => 'Déjà là', 'identifier' => 'e-1']);
    Accounts::plantVoter(['election' => $target, 'full_name' => 'Aussi', 'email' => 'ROSE2@example.ht', 'identifier' => 'E-77']);
    Accounts::plantVoter(['election' => $source, 'full_name' => 'Même courriel', 'identifier' => 'E-5', 'email' => 'rose2@example.ht']);

    $response = $this->browser->post(copyUrl($target), ['source' => $source])->assertCreated();

    expect($response->json('data'))->toBe(['copied' => 3, 'skipped' => 2, 'groups_created' => 2])
        ->and(copyNames($target))->toBe(['Aussi', 'Déjà là', 'Jean', 'Nadège', 'Sans groupe']);
});

it('copies nothing, without an error, when nothing matches [FR-VOT-05] (scenario 6)', function () {
    [$t, $source, $target] = copySignedIn($this);
    $empty = Accounts::plantGroup(['election' => $source, 'name' => 'Vide']);

    $this->browser->post(copyUrl($target), ['source' => $source, 'groups' => [$empty]])->assertCreated()
        ->assertJsonPath('data', ['copied' => 0, 'skipped' => 0, 'groups_created' => 0]);

    expect(Accounts::voterRows($target))->toBe([]);
});

it('answers 422 for a body that is not valid [FR-VOT-05] (scenarios 7 to 9)', function (string $name, array $body, string $field, string $rule) {
    [$t, $source, $target, $g4] = copySignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreignGroup = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'Chez B']);
    $replace = ['FOREIGNGROUP' => $foreignGroup, 'FOREIGN' => $foreignElection, 'SOURCE' => $source, 'TARGET' => $target, 'G4' => $g4, 'UNKNOWN' => COPY_UNKNOWN];
    $body = json_decode(str_replace(array_keys($replace), array_values($replace), json_encode($body)), true);

    $response = $this->browser->post(copyUrl($target), $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::voterRows($target))->toBe([]);
})->with([
    'no source' => ['no source', [], 'source', 'required'],
    'source not a uuid' => ['text', ['source' => 'hier'], 'source', 'uuid'],
    'source is the target' => ['same', ['source' => 'TARGET'], 'source', 'same'],
    'unknown source' => ['unknown', ['source' => 'UNKNOWN'], 'source', 'invalid'],
    'another institution\'s source' => ['foreign', ['source' => 'FOREIGN'], 'source', 'invalid'],
    'groups not a list' => ['list', ['source' => 'SOURCE', 'groups' => 'G4'], 'groups', 'invalid'],
    'a group that is not the source\'s' => ['other group', ['source' => 'SOURCE', 'groups' => ['FOREIGNGROUP']], 'groups', 'invalid'],
    'rename not an object' => ['rename list', ['source' => 'SOURCE', 'rename' => ['5e']], 'rename', 'invalid'],
    'rename of a group that is not copied' => ['rename other', ['source' => 'SOURCE', 'groups' => ['G4'], 'rename' => ['FOREIGNGROUP' => 'X']], 'rename', 'invalid'],
    'rename blank' => ['rename blank', ['source' => 'SOURCE', 'rename' => ['G4' => '   ']], 'rename', 'invalid'],
    'rename too long' => ['rename long', ['source' => 'SOURCE', 'rename' => ['G4' => str_repeat('g', 101)]], 'rename', 'invalid'],
]);

it('answers 409 voter_limit_reached and copies nothing when the target would pass 10 000 voters [FR-VOT-05] (scenario 10)', function () {
    [$t, $source, $target] = copySignedIn($this);
    $row = Accounts::electionRow($target);
    $now = date('Y-m-d H:i:s');
    foreach (array_chunk(range(1, 9998), 1000) as $chunk) {
        DB::connection(useMigratorConnection())->table('voters')->insert(array_map(fn ($n) => [
            'uuid' => (string) Str::uuid(), 'institution_id' => $row['institution_id'], 'election_id' => $row['id'],
            'full_name' => "Existant {$n}", 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $this->browser->post(copyUrl($target), ['source' => $source])->assertStatus(409)->assertJsonPath('error.code', 'voter_limit_reached');

    expect(Accounts::voterRows($target))->toHaveCount(9998)
        ->and(Accounts::groupRows($target))->toBe([]);
});

it('answers 409 group_limit_reached and copies nothing when the target would pass 100 groups [FR-VOT-05] (scenario 11)', function () {
    [$t, $source, $target] = copySignedIn($this);
    foreach (range(1, 99) as $i) {
        Accounts::plantGroup(['election' => $target, 'name' => "Groupe {$i}"]);
    }

    $this->browser->post(copyUrl($target), ['source' => $source])->assertStatus(409)->assertJsonPath('error.code', 'group_limit_reached');

    expect(Accounts::voterRows($target))->toBe([])
        ->and(Accounts::groupRows($target))->toHaveCount(99);
});

it('answers 409 election_voters_locked for an open, closed, published or archived target [FR-SEC-06] (scenario 12)', function (string $status) {
    [$t, $source, $target] = copySignedIn($this, $status);

    $this->browser->post(copyUrl($target), ['source' => $source])->assertStatus(409)->assertJsonPath('error.code', 'election_voters_locked');

    expect(Accounts::voterRows($target))->toBe([]);
})->with(['open', 'closed', 'published', 'archived']);

it('answers 404, the same for every case, for a target that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 13)', function () {
    [$t, $source] = copySignedIn($this);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);

    $unknown = Team::shape($this->browser->post(copyUrl(COPY_UNKNOWN), ['source' => $source]));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(copyUrl($target), ['source' => $source])))->toBe($unknown);
    }
    expect(Accounts::voterRows($foreign))->toBe([]);
});

it('answers 401, 403, 419 and 405 as every write [NFR-SEC-04] (scenarios 14 to 16 and 18)', function () {
    $t = Team::two();
    $source = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $target = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Cible']);

    $this->browser->post(copyUrl($target), ['source' => $source])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

    Team::signIn($this, $t['a']['owner']);
    $this->browser->post(copyUrl($target), ['source' => $source], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('GET', copyUrl($target))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(copyUrl($target), ['source' => $source])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
});

it('answers 429 above 20 requests an hour from one user [NFR-SEC-05] (scenario 17)', function () {
    [$t, $source, $target] = copySignedIn($this);
    foreach (range(1, 20) as $i) {
        $this->browser->post(copyUrl($target), ['source' => $source, 'groups' => []])->assertCreated();
    }

    $this->browser->post(copyUrl($target), ['source' => $source, 'groups' => []])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});
