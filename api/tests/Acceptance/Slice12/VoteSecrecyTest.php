<?php

/*
 * Ballot secrecy and integrity (FR-SEC-01, FR-SEC-02, FR-SEC-06, FR-SEC-08; docs/design/database.md sections 2.4, 4, 6):
 * what the tables hold, what the application's database account cannot do, and that nothing lines a vote up with a voter.
 */

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Voting;

/** Votes of the three voters of an election, all ballots. Returns the election data. */
function secrecyVoted($test): array
{
    $e = Voting::election();
    foreach (['Wideline' => ['P' => ['A'], 'S' => ['D', 'E'], 'G' => ['H']], 'Paul' => ['P' => ['B'], 'S' => ['E', 'F']], 'Marc' => ['P' => [], 'S' => ['D', 'F']]] as $who => $choices) {
        $browser = Voting::browser($test);
        Voting::enter($browser, $e['election'], $e['codes'][$who])->assertCreated();
        $browser->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, $choices), [], false)->assertCreated();
    }

    return $e;
}

/** The names of the columns of a table (the migrator account reads the schema). */
function secrecyColumns(string $table): array
{
    $rows = DB::connection(useMigratorConnection())->select('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);

    return array_map(fn ($r) => ((array) $r)['name'], $rows);
}

it('keeps only the documented columns in the four secrecy tables: no numeric id and no timestamp on a vote [FR-SEC-01] (scenario 1)', function () {
    Accounts::reset();

    expect(secrecyColumns('votes'))->toBe(['uuid', 'election_id', 'ballot_id'])
        ->and(secrecyColumns('vote_choices'))->toBe(['vote_uuid', 'candidate_id'])
        ->and(secrecyColumns('vote_audit'))->toBe(['uuid', 'election_id', 'payload'])
        ->and(secrecyColumns('participations'))->toBe(['election_id', 'voter_id', 'voted_at']);
});

it('gives the votes random uuids that do not follow the order of the participations [FR-SEC-01] (scenario 2)', function () {
    $e = secrecyVoted($this);

    $db = DB::connection(useMigratorConnection());
    $votes = $db->table('votes')->get();

    expect($votes)->toHaveCount(7);
    foreach ($votes as $vote) {
        expect($vote->uuid)->toMatch(UUID_V4);
    }
    expect(count(array_unique($votes->pluck('uuid')->all())))->toBe(7);
    $audit = $db->table('vote_audit')->pluck('uuid')->all();
    expect(array_intersect($audit, $votes->pluck('uuid')->all()))->toBe([]);
    expect($e['election'])->toBeString();
});

it('lets the audit key open every record and bind it to its election and voter [FR-SEC-08] (scenario 3)', function () {
    $e = secrecyVoted($this);

    $audit = Voting::audit($e['election'], $this->auditPair);

    expect($audit)->toHaveCount(7);
    $byVoter = collect($audit)->groupBy('voter');
    expect($byVoter[$e['voters']['Wideline']])->toHaveCount(3)
        ->and($byVoter[$e['voters']['Paul']])->toHaveCount(2)
        ->and($byVoter[$e['voters']['Marc']])->toHaveCount(2);
    $votes = DB::connection(useMigratorConnection())->table('votes')->pluck('ballot_id', 'uuid');
    foreach ($audit as $row) {
        expect($votes->has($row['vote']))->toBeTrue();
    }
});

it('cannot be read without the private key, the server holds only the public half [FR-SEC-08] (scenario 4)', function () {
    $e = secrecyVoted($this);
    $other = sodium_crypto_box_keypair();

    $row = DB::connection(useMigratorConnection())->table('vote_audit')->first();

    expect(sodium_crypto_box_seal_open($row->payload, $other))->toBeFalse()
        ->and(strlen(config('votes.audit_public_key')))->toBe(64);
    expect($e['election'])->toBeString();
});

it('refuses UPDATE and DELETE on the secrecy tables to the application account [FR-SEC-06] (scenario 5)', function (string $table) {
    secrecyVoted($this);
    $app = DB::connection();

    expect(fn () => $app->table($table)->update(['election_id' => 1]))->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => $app->table($table)->delete())->toThrow(Illuminate\Database\QueryException::class);
})->with(['participations', 'votes', 'vote_choices', 'vote_audit']);

it('refuses a second participation of the same voter at the database [FR-SEC-02] (scenario 6)', function () {
    $e = secrecyVoted($this);
    $db = DB::connection(useMigratorConnection());
    $row = $db->table('participations')->first();

    expect(fn () => $db->table('participations')->insert(['election_id' => $row->election_id, 'voter_id' => $row->voter_id, 'voted_at' => now('UTC')->toDateTimeString()]))
        ->toThrow(Illuminate\Database\QueryException::class);
    expect($e['election'])->toBeString();
});

it('refuses to delete an election that has votes, a voter who voted and a candidate that was chosen [FR-SEC-06] (scenario 7)', function () {
    $e = secrecyVoted($this);
    $db = DB::connection(useMigratorConnection());

    expect(fn () => $db->table('elections')->where('uuid', $e['election'])->delete())->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => $db->table('voters')->where('uuid', $e['voters']['Wideline'])->delete())->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => $db->table('candidates')->where('uuid', $e['candidates']['A'])->delete())->toThrow(Illuminate\Database\QueryException::class);
});

it('does not let an institution user delete a voter who voted or the election through the API [FR-SEC-06] (scenario 7)', function () {
    $owner = Accounts::user();
    $e = Voting::election(['institution' => $owner['institution']]);
    $browser = Voting::browser($this);
    Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $browser->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, ['G' => ['H']]), [], false)->assertCreated();
    $admin = Voting::browser($this);
    $admin->login($owner['email'], $owner['password'])->assertOk();

    $admin->delete("/api/v1/voters/{$e['voters']['Wideline']}")->assertStatus(409);
    $admin->delete("/api/v1/elections/{$e['election']}")->assertStatus(409);
    expect(Voting::counts($e['election'])[0])->toBe(1);
});

it('never shows a vote count to an institution user while the election is open [FR-SEC-03] (scenario 8)', function () {
    $owner = Accounts::user();
    $e = Voting::election(['institution' => $owner['institution']]);
    $browser = Voting::browser($this);
    Voting::enter($browser, $e['election'], $e['codes']['Wideline'])->assertCreated();
    $browser->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, ['G' => ['H']]), [], false)->assertCreated();
    $admin = Voting::browser($this);
    $admin->login($owner['email'], $owner['password'])->assertOk();

    $election = $admin->get("/api/v1/elections/{$e['election']}")->assertOk()->getContent();

    expect($election)->not->toContain('"votes"')->and($election)->not->toContain('vote_count');
});
