<?php

/*
 * What is written to the audit log — docs/slices/17-audit-log.md "Actions recorded" and FR-AUD-01, FR-AUD-02. The log is
 * read through GET /api/v1/audit (docs/api/audit/GET-audit.md); the entries are the owner's own.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts;
use Tests\Support\Publication;
use Tests\Support\Results;
use Tests\Support\Spreadsheets;
use Tests\Support\Team;
use Tests\Support\Voting;

/** The entries of the owner's log, newest first, optionally filtered by a query string. */
function auditEntries($test, string $query = ''): array
{
    return $test->browser->get('/api/v1/audit'.$query)->assertOk()->json('data');
}

/** The newest entry of an action, or null. */
function auditLast($test, string $action): ?array
{
    return auditEntries($test, '?action='.$action)[0] ?? null;
}

/** An owner of A signed in on `$this->browser`; returns [team, institution uuid]. */
function auditOwner($test): array
{
    $t = Team::two();
    Team::signIn($test, $t['a']['owner']);

    return [$t, $t['a']['owner']['institution']];
}

it('records a sign-in with the user, the time and the address [FR-AUD-01, FR-AUD-02]', function () {
    $t = Team::two();
    $browser = $this->browser->fromAddress('10.50.60.70');
    $browser->login($t['a']['owner']['email'], $t['a']['owner']['password'])->assertOk();

    $entry = auditLast($this, 'auth.sign_in');

    expect($entry)->not->toBeNull()
        ->and($entry['actor']['name'])->toBe('Alice Owner A')
        ->and($entry['ip_address'])->toBe('10.50.60.70')
        ->and($entry['details'])->toBe([])
        ->and(abs(strtotime($entry['created_at']) - now('UTC')->timestamp))->toBeLessThan(15)
        ->and(array_keys($entry))->toBe(['id', 'action', 'actor', 'election', 'subject', 'details', 'ip_address', 'created_at'])
        ->and(array_keys($entry['actor']))->toBe(['id', 'name']);
});

it('records the creation, an edit, and a change of times with the old and the new values [FR-AUD-01]', function () {
    [$t] = auditOwner($this);
    $created = $this->browser->post('/api/v1/elections', ['title' => 'Conseil 2026', 'starts_at' => '2030-03-01T12:00:00Z', 'ends_at' => '2030-03-05T19:00:00Z'])->assertCreated()->json('data.id');

    $this->browser->patch("/api/v1/elections/{$created}", ['title' => 'Conseil des élèves 2026'])->assertOk();
    $this->browser->patch("/api/v1/elections/{$created}", ['starts_at' => '2030-03-02T12:00:00Z'])->assertOk();

    $c = auditLast($this, 'election.created');
    $u = auditLast($this, 'election.updated');
    $times = auditLast($this, 'election.times_changed');
    expect($c['details'])->toBe(['title' => 'Conseil 2026'])
        ->and($c['election'])->toBe(['id' => $created, 'title' => 'Conseil des élèves 2026'])
        ->and($c['subject'])->toBe(['type' => 'election', 'id' => $created])
        ->and($u['details'])->toBe(['fields' => ['title']])
        ->and($times['details']['from']['starts_at'])->toBe('2030-03-01T12:00:00Z')
        ->and($times['details']['to']['starts_at'])->toBe('2030-03-02T12:00:00Z')
        ->and($times['details']['to']['ends_at'])->toBe('2030-03-05T19:00:00Z');
});

it('records scheduling, unscheduling, closing early and extending by the user [FR-AUD-01]', function () {
    [$t, $institution] = auditOwner($this);
    $draft = Accounts::plantElection(['institution' => $institution, 'status' => 'draft', 'starts_at' => '2030-03-01 12:00:00', 'ends_at' => '2030-03-05 19:00:00']);
    $ballot = Accounts::plantBallot(['election' => $draft, 'title' => 'Président']);
    Accounts::plantCandidate(['ballot' => $ballot]);
    Accounts::plantCandidate(['ballot' => $ballot]);
    Accounts::plantVoter(['election' => $draft]);
    $this->browser->post("/api/v1/elections/{$draft}/schedule")->assertOk();
    $this->browser->post("/api/v1/elections/{$draft}/unschedule")->assertOk();
    $open = Voting::election(['institution' => $institution])['election'];

    $this->browser->post("/api/v1/elections/{$open}/extend", ['ends_at' => now('UTC')->addDays(4)->format('Y-m-d\TH:i:s\Z')])->assertOk();
    $this->browser->post("/api/v1/elections/{$open}/close", ['confirmation' => 'Conseil des élèves 2026'])->assertOk();

    expect(auditLast($this, 'election.scheduled')['election']['id'])->toBe($draft)
        ->and(auditLast($this, 'election.unscheduled')['election']['id'])->toBe($draft)
        ->and(auditLast($this, 'election.extended')['details'])->toHaveKeys(['from', 'to'])
        ->and(auditLast($this, 'election.closed')['details'])->toBe(['by' => 'user']);
});

it('records what the scheduler does with no user, as system [FR-AUD-02]', function () {
    [$t, $institution] = auditOwner($this);
    $a = Voting::election(['institution' => $institution, 'status' => 'scheduled', 'starts_at' => now('UTC')->subMinute()->toDateTimeString()])['election'];
    $b = Voting::election(['institution' => $institution, 'status' => 'open', 'ends_at' => now('UTC')->subMinute()->toDateTimeString()])['election'];

    Artisan::call('elections:advance');

    $opened = auditLast($this, 'election.opened');
    $closed = auditLast($this, 'election.closed');
    expect($opened['election']['id'])->toBe($a)
        ->and($opened['actor'])->toBe(['id' => null, 'name' => 'system'])
        ->and($opened['details'])->toBe(['by' => 'scheduler'])
        ->and($closed['election']['id'])->toBe($b)
        ->and($closed['details'])->toBe(['by' => 'scheduler']);
    expect(count(auditEntries($this, '?user=system')))->toBe(2);
});

it('records publication, unpublication, archiving and unarchiving [FR-AUD-01]', function () {
    [$t, $institution] = auditOwner($this);
    $e = Publication::election($institution)['election'];

    $this->browser->post("/api/v1/elections/{$e}/publish", ['results_display' => 'winners'])->assertOk();
    $this->browser->post("/api/v1/elections/{$e}/unpublish")->assertOk();
    $this->browser->post("/api/v1/elections/{$e}/archive")->assertOk();
    $this->browser->post("/api/v1/elections/{$e}/unarchive")->assertOk();

    expect(auditLast($this, 'election.published')['details'])->toBe(['display' => 'winners'])
        ->and(array_column(array_slice(auditEntries($this, '?election='.$e), 0, 4), 'action'))
        ->toBe(['election.unarchived', 'election.archived', 'election.unpublished', 'election.published']);
});

it('records the deletion of a draft and keeps its uuid and title [FR-AUD-01]', function () {
    [$t, $institution] = auditOwner($this);
    $draft = Accounts::plantElection(['institution' => $institution, 'status' => 'draft', 'title' => 'À supprimer']);

    $this->browser->delete("/api/v1/elections/{$draft}")->assertNoContent();

    $entry = auditLast($this, 'election.deleted');
    expect($entry['election'])->toBeNull()
        ->and($entry['subject'])->toBe(['type' => 'election', 'id' => $draft])
        ->and($entry['details'])->toBe(['title' => 'À supprimer']);
});

it('records a second round and a duplicate [FR-AUD-01]', function () {
    [$t, $institution] = auditOwner($this);
    $e = Publication::election($institution, 'closed', 4, 4, 0);

    $copy = $this->browser->post("/api/v1/elections/{$e['election']}/duplicate", [])->assertCreated()->json('data.id');
    $round = $this->browser->post("/api/v1/elections/{$e['election']}/second-round", ['ballot' => $e['ballots']['P']])->assertCreated()->json('data.id');

    expect(auditLast($this, 'election.duplicated')['election']['id'])->toBe($copy)
        ->and(auditLast($this, 'second_round.created')['details'])->toBe(['from' => $e['election'], 'ballot' => $e['ballots']['P']])
        ->and(auditLast($this, 'second_round.created')['election']['id'])->toBe($round);
});

it('records the voters added, edited and deleted, with the name and not more [FR-AUD-01]', function () {
    [$t, $institution] = auditOwner($this);
    $election = Accounts::plantElection(['institution' => $institution, 'status' => 'draft']);

    $voter = $this->browser->post("/api/v1/elections/{$election}/voters", ['full_name' => 'Rose Désir', 'email' => 'rose@example.ht'])->assertCreated()->json('data.id');
    $this->browser->patch("/api/v1/voters/{$voter}", ['full_name' => 'Rose-Marie Désir'])->assertOk();
    $this->browser->delete("/api/v1/voters/{$voter}")->assertNoContent();

    expect(auditLast($this, 'voter.created')['details'])->toBe(['name' => 'Rose Désir'])
        ->and(auditLast($this, 'voter.updated')['details'])->toBe(['name' => 'Rose-Marie Désir', 'fields' => ['full_name']])
        ->and(auditLast($this, 'voter.deleted')['details'])->toBe(['name' => 'Rose-Marie Désir'])
        ->and(auditLast($this, 'voter.deleted')['subject'])->toBe(['type' => 'voter', 'id' => $voter])
        ->and(json_encode(auditEntries($this)))->not->toContain('rose@example.ht');
});

it('records a confirmed import with how many voters came in and were left out [FR-AUD-01]', function () {
    [$t, $institution] = auditOwner($this);
    $election = Accounts::plantElection(['institution' => $institution, 'status' => 'draft']);
    $import = Accounts::plantImport(['election' => $election, 'rows' => [
        ['full_name' => 'Rose-Marie Désir', 'group' => '4e année'],
        ['full_name' => 'Jean Pierre', 'group' => '4e année'],
        ['full_name' => null, 'status' => 'invalid', 'reasons' => ['full_name_required']],
    ]]);

    $this->browser->post("/api/v1/voter-imports/{$import}/confirm")->assertStatus(202);

    expect(auditLast($this, 'voters.imported')['details'])->toBe(['count' => 2, 'skipped' => 1]);
});

it('records code generation, a reissue, a download and an email run, and never a code [FR-AUD-01, FR-SEC-06]', function () {
    [$t, $institution] = auditOwner($this);
    $election = Accounts::plantElection(['institution' => $institution, 'status' => 'draft', 'title' => 'Conseil']);
    $rose = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => 'rose@example.ht']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre']);

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all'])->assertStatus(202);
    $reissued = $this->browser->post("/api/v1/voters/{$rose}/credential", [])->assertCreated();
    $code = $reissued->json('data.code');
    $file = $this->browser->get("/api/v1/elections/{$election}/generated-files")->assertOk()->json('data.0.id');
    $this->browser->get("/api/v1/generated-files/{$file}/download")->assertOk();
    $election2 = Accounts::plantElection(['institution' => $institution, 'status' => 'draft']);
    Accounts::plantVoter(['election' => $election2, 'full_name' => 'Paul Joseph', 'email' => 'paul@example.ht']);
    $this->browser->post("/api/v1/elections/{$election2}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 1])->assertStatus(202);

    expect(auditLast($this, 'credentials.generated')['details'])->toHaveKeys(['scope', 'count'])
        ->and(auditLast($this, 'credentials.reissued')['details'])->toBe(['name' => 'Rose Désir'])
        ->and(auditLast($this, 'credentials.reissued')['subject'])->toBe(['type' => 'voter', 'id' => $rose])
        ->and(auditLast($this, 'credentials.downloaded')['details'])->toHaveKey('kind')
        ->and(auditLast($this, 'credentials.emailed')['details'])->toBe(['count' => 1]);
    $everything = json_encode(auditEntries($this, '?per_page=100'));
    expect($everything)->not->toContain($code)->not->toContain(str_replace('-', '', $code))->not->toContain('password');
});

it('writes nothing for a request that was refused [FR-AUD-02]', function () {
    [$t, $institution] = auditOwner($this);
    $e = Voting::election(['institution' => $institution])['election'];
    $before = count(auditEntries($this, '?per_page=100'));

    $this->browser->post("/api/v1/elections/{$e}/close", ['confirmation' => 'non'])->assertStatus(422);
    $this->browser->post("/api/v1/elections/{$e}/publish")->assertStatus(409);
    $this->browser->post("/api/v1/elections/{$e}/schedule")->assertStatus(409);

    expect(count(auditEntries($this, '?per_page=100')))->toBe($before);
});

it('cannot be changed or removed by the application: the database refuses [FR-AUD-02]', function () {
    [$t, $institution] = auditOwner($this);
    $this->browser->post('/api/v1/elections', ['title' => 'Une élection', 'starts_at' => '2030-03-01T12:00:00Z', 'ends_at' => '2030-03-05T19:00:00Z'])->assertCreated();

    expect(fn () => DB::table('audit_entries')->update(['action' => 'x']))->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => DB::table('audit_entries')->delete())->toThrow(Illuminate\Database\QueryException::class)
        ->and(DB::connection(useMigratorConnection())->table('audit_entries')->count())->toBeGreaterThan(0);
});
