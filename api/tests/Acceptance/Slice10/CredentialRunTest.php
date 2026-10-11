<?php

/*
 * POST /api/v1/elections/{election}/credential-runs — docs/api/credentials/POST-elections-{election}-credential-runs.md
 * Tenant suite, route api/v1/elections/{election}/credential-runs: another institution's election answers 404, the same
 * as an unknown one. The test service runs the queue synchronously: when the answer is back, the job has run.
 */

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Accounts;
use Tests\Support\Codes;
use Tests\Support\Pdfs;
use Tests\Support\Team;

const RUN_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function runUrl(string $uuid): string
{
    return "/api/v1/elections/{$uuid}/credential-runs";
}

/**
 * An owner of A signed in and an election of the given status with groups "4e année" (3 voters), "5e année" (2) and 1
 * voter with no group. Returns [team, election, g4, g5, voters by name].
 */
function runSignedIn($test, string $status = 'draft'): array
{
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'status' => $status, 'title' => 'Conseil des élèves']);
    $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $g5 = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
    $voters = [];
    foreach ([['Rose', $g4], ['Jean', $g4], ['Nadège', $g4], ['Paul', $g5], ['Wideline', $g5], ['Sans groupe Marc', null]] as [$name, $group]) {
        $voters[$name] = Accounts::plantVoter(['election' => $election, 'full_name' => $name, 'group' => $group]);
    }
    Team::signIn($test, $t['a']['owner']);

    return [$t, $election, $g4, $g5, $voters];
}

/** The decrypted PDF of a file of the election, by group uuid (null: the voters with no group). */
function runPdf(string $election, ?string $group, int $part = 1): string
{
    foreach (Accounts::generatedFileRows($election) as $row) {
        $rowGroup = $row['voter_group_id'] === null ? null : DB::connection(useMigratorConnection())->table('voter_groups')->where('id', $row['voter_group_id'])->value('uuid');
        if ($rowGroup === $group && (int) $row['part'] === $part) {
            return Crypt::decryptString(Storage::disk('credentials')->get($row['stored_file']));
        }
    }
    throw new RuntimeException('no such file');
}

it('gives a code to every voter and writes one ready PDF per group, the ungrouped included [FR-CRED-01, FR-CRED-04] (scenario 1)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);

    $response = $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);

    expect($response->json('data.voters'))->toBe(6)
        ->and($response->json('data.skipped'))->toBe(0)
        ->and($response->json('data.files'))->toHaveCount(3);
    $rows = Accounts::generatedFileRows($election);
    expect($rows)->toHaveCount(3)
        ->and(array_unique(array_column($rows, 'status')))->toBe(['ready'])
        ->and(array_unique(array_column($rows, 'kind')))->toBe(['slips_pdf'])
        ->and(Accounts::credentialRows($election))->toHaveCount(6);
    $counts = collect($rows)->pluck('voter_count')->sort()->values()->all();
    expect($counts)->toBe([1, 2, 3]);
    foreach (array_keys($voters) as $name) {
        expect(Accounts::credentialRow($voters[$name]))->not->toBeNull();
    }
    foreach ($response->json('data.files') as $file) {
        expect($file['id'])->toMatch(UUID_V4)
            ->and(array_keys($file))->toBe(['id', 'kind', 'group', 'part', 'parts', 'voter_count', 'status', 'failure', 'size_bytes', 'expires_at', 'created_at']);
    }
});

it('serves one group only [FR-CRED-01] (scenario 2)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);

    $response = $this->browser->post(runUrl($election), ['scope' => 'group', 'group' => $g5])->assertStatus(202);

    expect($response->json('data.voters'))->toBe(2)
        ->and($response->json('data.files'))->toHaveCount(1)
        ->and($response->json('data.files.0.group'))->toBe(['id' => $g5, 'name' => '5e année'])
        ->and(Accounts::credentialRow($voters['Paul']))->not->toBeNull()
        ->and(Accounts::credentialRow($voters['Wideline']))->not->toBeNull()
        ->and(Accounts::credentialRow($voters['Rose']))->toBeNull()
        ->and(Accounts::credentialRow($voters['Sans groupe Marc']))->toBeNull();
});

it('serves the voters with no group in a file whose group is null [FR-CRED-01] (scenario 3)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);

    $response = $this->browser->post(runUrl($election), ['scope' => 'ungrouped'])->assertStatus(202);

    expect($response->json('data.files'))->toHaveCount(1)
        ->and($response->json('data.files.0.group'))->toBeNull()
        ->and($response->json('data.voters'))->toBe(1)
        ->and(Accounts::credentialRow($voters['Sans groupe Marc']))->not->toBeNull()
        ->and(Accounts::credentialRows($election))->toHaveCount(1);
});

it('splits a group of more than 1 000 voters in parts of 1 000, in name order [FR-CRED-04] (scenario 4)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    $group = Accounts::plantGroup(['election' => $election, 'name' => 'Toute l\'école']);
    foreach (range(1, 1001) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => sprintf('Électeur %04d', $i), 'group' => $group]);
    }
    Team::signIn($this, $t['a']['owner']);

    $response = $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);

    $files = $response->json('data.files');
    expect($files)->toHaveCount(2)
        ->and([$files[0]['part'], $files[0]['parts'], $files[0]['voter_count']])->toBe([1, 2, 1000])
        ->and([$files[1]['part'], $files[1]['parts'], $files[1]['voter_count']])->toBe([2, 2, 1])
        ->and(Accounts::credentialRows($election))->toHaveCount(1001);
    $second = Pdfs::codesByName(runPdf($election, $group, 2), ['Électeur 1001']);
    expect($second)->toHaveCount(1);
});

it('leaves the voters who already have a code as they are and counts them as skipped [FR-CRED-01, FR-CRED-03] (scenario 5)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);
    $kept = Accounts::plantCredential(['voter' => $voters['Rose'], 'code' => 'K7MP4-QX9RT']);
    $hash = Accounts::credentialRow($voters['Rose'])['code_hash'];

    $response = $this->browser->post(runUrl($election), ['scope' => 'group', 'group' => $g4])->assertStatus(202);

    expect($response->json('data.voters'))->toBe(2)
        ->and($response->json('data.skipped'))->toBe(1)
        ->and(Accounts::credentialRow($voters['Rose'])['code_hash'])->toBe($hash)
        ->and(Accounts::credentialRow($voters['Rose'])['reissue_count'])->toBe(0)
        ->and(Pdfs::codesByName(runPdf($election, $g4), ['Rose', 'Jean', 'Nadège']))->not->toHaveKey('Rose');
    expect($kept)->toBe('K7MP4-QX9RT');
});

it('makes no file for a group whose voters all have a code [FR-CRED-01] (scenario 5)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);
    foreach (['Rose', 'Jean', 'Nadège'] as $name) {
        Accounts::plantCredential(['voter' => $voters[$name]]);
    }

    $response = $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);

    $groups = array_map(fn ($file) => $file['group']['id'] ?? null, $response->json('data.files'));
    expect($response->json('data.files'))->toHaveCount(2)
        ->and($groups)->not->toContain($g4)
        ->and($response->json('data.skipped'))->toBe(3);
});

it('writes in each PDF the slips of its group on US Letter, 10 a page, with the code of each voter matching the stored hash [FR-CRED-04, FR-CRED-06] (scenario 6)', function () {
    $t = Team::two();
    $institution = $t['a']['owner']['institution'];
    $election = Accounts::plantElection(['institution' => $institution, 'title' => 'Conseil des élèves']);
    $group = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
    $names = [];
    foreach (range(1, 25) as $i) {
        $names[$i] = sprintf('Élève %02d', 26 - $i);
        Accounts::plantVoter(['election' => $election, 'full_name' => $names[$i], 'group' => $group]);
    }
    Team::signIn($this, $t['a']['owner']);

    $this->browser->post(runUrl($election), ['scope' => 'group', 'group' => $group])->assertStatus(202);

    $pdf = runPdf($election, $group);
    $text = Pdfs::text($pdf);
    expect(Pdfs::isPdf($pdf))->toBeTrue()
        ->and(Pdfs::isLetter($pdf))->toBeTrue()
        ->and(Pdfs::pageCount($pdf))->toBe(3)
        ->and($text)->toContain('Conseil des élèves')
        ->and($text)->toContain('4e année')
        ->and($text)->toContain("/vote/{$election}")
        ->and(Pdfs::codes($pdf))->toHaveCount(25);
    $codes = Pdfs::codesByName($pdf, array_values($names));
    expect($codes)->toHaveCount(25);
    foreach (Accounts::credentialRows($election) as $row) {
        expect($row['code_hash'])->toBe(Codes::hash($codes[$row['full_name']]));
    }
    // Sorted by name inside the group: the first name read is the first in alphabetical order.
    $read = array_keys($codes);
    $sorted = $read;
    sort($sorted, SORT_STRING);
    expect($read)->toBe($sorted);
});

it('marks a file failed and keeps no code when the file cannot be written [FR-CRED-01, FR-CRED-05] (scenario 7)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);
    Codes::breakDisk();

    $this->browser->post(runUrl($election), ['scope' => 'group', 'group' => $g4])->assertStatus(202);

    $rows = Accounts::generatedFileRows($election);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('failed')
        ->and($rows[0]['failure'])->toBe('generation_failed')
        ->and($rows[0]['stored_file'])->toBeNull()
        ->and(Accounts::credentialRows($election))->toBe([]);
    // A new run, with the disk back, serves the same voters.
    Codes::useTempDisk();
    $this->browser->post(runUrl($election), ['scope' => 'group', 'group' => $g4])->assertStatus(202);
    expect(Accounts::credentialRows($election))->toHaveCount(3);
});

it('answers 409 nothing_to_generate when every voter of the scope has a code, or there is no voter [FR-CRED-01] (scenario 8)', function () {
    [$t, $election, $g4, $g5, $voters] = runSignedIn($this);
    $empty = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Vide']);
    foreach ($voters as $voter) {
        Accounts::plantCredential(['voter' => $voter]);
    }

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_generate');
    $this->browser->post(runUrl($empty), ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_generate');
    expect(Accounts::generatedFileRows($election))->toBe([]);
});

it('answers 409 run_in_progress while a file of the election is queued [FR-CRED-04] (scenario 9)', function () {
    [$t, $election, $g4] = runSignedIn($this);
    Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued']);

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'run_in_progress');
    expect(Accounts::credentialRows($election))->toBe([]);
});

it('runs in a draft, scheduled or open election [FR-CRED-01] (scenario 1)', function (string $status) {
    [$t, $election] = runSignedIn($this, $status);

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);
    expect(Accounts::credentialRows($election))->toHaveCount(6);
})->with(['draft', 'scheduled', 'open']);

it('answers 409 election_codes_locked for a closed, published or archived election [FR-CRED-01] (scenario 10)', function (string $status) {
    [$t, $election] = runSignedIn($this, $status);

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(409)->assertJsonPath('error.code', 'election_codes_locked');
    expect(Accounts::credentialRows($election))->toBe([]);
})->with(['closed', 'published', 'archived']);

it('answers 422 for a body that is not valid [FR-CRED-01] (scenario 11)', function (string $name, array $body, string $field, string $rule) {
    [$t, $election, $g4] = runSignedIn($this);
    $foreignElection = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    $foreign = Accounts::plantGroup(['election' => $foreignElection, 'name' => 'Chez B']);
    $otherElection = Accounts::plantElection(['institution' => $t['a']['owner']['institution'], 'title' => 'Autre']);
    $elsewhere = Accounts::plantGroup(['election' => $otherElection, 'name' => 'Ailleurs']);
    $replace = ['FOREIGN' => $foreign, 'ELSEWHERE' => $elsewhere];
    $body = json_decode(str_replace(array_keys($replace), array_values($replace), json_encode($body)), true);

    $response = $this->browser->post(runUrl($election), $body)->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json("error.fields.{$field}"))->toContain($rule)
        ->and(Accounts::credentialRows($election))->toBe([]);
})->with([
    'no scope' => ['none', [], 'scope', 'required'],
    'unknown scope' => ['class', ['scope' => 'class'], 'scope', 'invalid'],
    'group without group' => ['nogroup', ['scope' => 'group'], 'group', 'required'],
    'group not a uuid' => ['uuid', ['scope' => 'group', 'group' => '4e'], 'group', 'invalid'],
    'another institution\'s group' => ['foreign', ['scope' => 'group', 'group' => 'FOREIGN'], 'group', 'invalid'],
    'another election\'s group' => ['elsewhere', ['scope' => 'group', 'group' => 'ELSEWHERE'], 'group', 'invalid'],
]);

it('answers 404, the same for every case, for an election that is unknown, of another institution or not a UUID [FR-INST-05] (scenario 12)', function () {
    [$t, $election] = runSignedIn($this);
    $foreign = Accounts::plantElection(['institution' => $t['b']['owner']['institution']]);
    Accounts::plantVoter(['election' => $foreign, 'full_name' => 'De B']);

    $unknown = Team::shape($this->browser->post(runUrl(RUN_UNKNOWN), ['scope' => 'all']));
    expect($unknown['status'])->toBe(404)
        ->and(json_decode($unknown['body'], true)['error']['code'])->toBe('not_found');

    foreach ([$foreign, 'not-a-uuid', '12'] as $target) {
        expect(Team::shape($this->browser->post(runUrl($target), ['scope' => 'all'])))->toBe($unknown);
    }
    expect(Accounts::credentialRows($foreign))->toBe([]);
});

it('answers 401, 403, 419, 405 and 400 as every write [NFR-SEC-04] (scenarios 13 to 17)', function () {
    [$t, $election] = runSignedIn($this);

    $this->browser->post(runUrl($election), ['scope' => 'all'], [], false)->assertStatus(419)->assertJsonPath('error.code', 'csrf_mismatch');
    $this->browser->other('PUT', runUrl($election))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    Accounts::suspend($t['a']['owner']['institution']);
    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(403)->assertJsonPath('error.code', 'institution_suspended');
    expect(Accounts::credentialRows($election))->toBe([]);
});

it('answers 401 when nobody is signed in [FR-CRED-01] (scenario 14)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Seul']);

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('lets a manager run it [FR-CRED-01] (scenario 1)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Géré']);
    Team::signIn($this, $t['a']['manager']);

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);
});

it('answers 429 above 20 requests an hour from one user [NFR-SEC-05] (scenario 16)', function () {
    $t = Team::two();
    $election = Accounts::plantElection(['institution' => $t['a']['owner']['institution']]);
    Team::signIn($this, $t['a']['owner']);

    foreach (range(1, 20) as $i) {
        Accounts::plantVoter(['election' => $election, 'full_name' => "Électeur {$i}"]);
        $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);
    }
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Le vingt et unième']);

    $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(429)->assertJsonPath('error.code', 'too_many_attempts');
});

it('outputs no numeric id and no stored name in its answer [NFR-SEC-01] (scenario 1)', function () {
    [$t, $election] = runSignedIn($this);

    $response = $this->browser->post(runUrl($election), ['scope' => 'all'])->assertStatus(202);

    $stored = array_column(Accounts::generatedFileRows($election), 'stored_file');
    $body = $response->getContent();
    foreach ($stored as $name) {
        expect($body)->not->toContain($name);
    }
    $keys = [];
    $json = $response->json();
    array_walk_recursive($json, function ($value, $key) use (&$keys) {
        $keys[] = $key;
    });
    expect(array_filter($keys, fn ($key) => is_string($key) && str_ends_with($key, '_id')))->toBe([]);
});
