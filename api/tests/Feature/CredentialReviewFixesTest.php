<?php

/*
 * Slice 11 review round, the details the acceptance tests leave: the daily quota and the verified gate
 * on a reissue, an address that changed, a message delivered twice, a suspended institution, the
 * throttle key of an institution, the files of two runs in the same second, the statuses a crashed
 * file leaves, the partial of the code file when its write is undone, and the text of the email.
 */

use App\Actions\Credentials\AppendCodeRows;
use App\Actions\Credentials\GenerateSlipFile;
use App\Actions\Credentials\StartCredentialRun;
use App\Jobs\Middleware\ThrottleCredentialEmails;
use App\Jobs\SendCredentialEmail;
use App\Mail\CredentialMail;
use App\Models\Credential;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\User;
use App\Support\Credentials\AddressSnapshot;
use App\Support\Credentials\SlipFileName;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Accounts;
use Tests\Support\AuthClient;
use Tests\Support\Codes;

beforeEach(function () {
    Accounts::reset();
    Codes::useTempDisk();
    Cache::flush();
    RateLimiter::clear('credential-emails');
    $this->browser = new AuthClient($this);
});

/** An election of a new institution with Rose (an address). Returns [user, election, voter, code of Rose or null]. */
function rvSetup(array $owner = [], ?string $status = null, bool $planted = true): array
{
    $user = Accounts::user($owner + ['email' => 'owner.'.bin2hex(random_bytes(4)).'@example.test']);
    $election = Accounts::plantElection(['institution' => $user['institution'], 'language' => 'en', 'title' => 'Student council']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => 'rose@example.ht']);
    $code = $planted ? Accounts::plantCredential(['voter' => $voter, 'email_status' => $status ?? 'queued']) : null;

    return [$user, $election, $voter, $code];
}

function rvSet(string $voter, array $values): void
{
    $db = DB::connection(useMigratorConnection());
    $db->table('credentials')->where('voter_id', $db->table('voters')->where('uuid', $voter)->value('id'))->update($values);
}

function rvSnapshot(string $address): string
{
    return AddressSnapshot::of($address);
}

it('refuses a reissue with email from an unverified user and takes nothing from a verified one over the quota [FR-CRED-07] (review fix 1)', function () {
    config(['credentials.emails_per_institution_per_day' => 1]);
    [$user, $election, $rose] = rvSetup(['verified' => true], 'sent', false);
    $other = Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'email' => 'jean@example.ht']);
    Accounts::plantCredential(['voter' => $other, 'email_status' => 'sent']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    // The quota of 1 is used by Jean: another voter's email is refused with nothing left.
    $response = $this->browser->post("/api/v1/voters/{$rose}/credential", ['email' => true])->assertStatus(409);

    expect($response->json('error.code'))->toBe('email_quota_exceeded')
        ->and($response->json('error.count'))->toBe(0)
        ->and(Accounts::credentialRow($rose))->toBeNull()
        ->and(Accounts::mail())->toBe([]);

    // A reissue without email is not counted.
    $this->browser->post("/api/v1/voters/{$rose}/credential")->assertCreated();
});

it('lets a reissue replace a code that already counted in the quota [FR-CRED-07] (review fix 1)', function () {
    config(['credentials.emails_per_institution_per_day' => 1]);
    [$user, $election, $rose] = rvSetup([], 'sent');
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post("/api/v1/voters/{$rose}/credential", ['email' => true])->assertCreated()->assertJsonPath('data.email', 'queued');

    expect(Accounts::credentialRow($rose)['email_status'])->toBe('sent');
});

it('does not count a failed email nor one of more than a day ago in the quota [FR-CRED-07] (review fix 1)', function () {
    config(['credentials.emails_per_institution_per_day' => 2]);
    [$user, $election, $rose] = rvSetup([], 'failed', false);
    $old = Accounts::plantVoter(['election' => $election, 'full_name' => 'Ancien', 'email' => 'old@example.ht']);
    Accounts::plantCredential(['voter' => $old, 'email_status' => 'sent', 'issued_at' => now('UTC')->subHours(30)->toDateTimeString()]);
    $failed = Accounts::plantVoter(['election' => $election, 'full_name' => 'Échec', 'email' => 'failed@example.ht']);
    Accounts::plantCredential(['voter' => $failed, 'email_status' => 'failed']);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'email' => true, 'confirm_emails' => 1])
        ->assertStatus(202);
});

it('refuses an address that changed since the run and sends nothing [FR-CRED-04] (review fix 4)', function () {
    [$user, $election, $voter, $code] = rvSetup();
    rvSet($voter, ['email_hash' => rvSnapshot('old@example.ht')]);

    (new SendCredentialEmail($voter, $election, $code))->handle();

    $row = Accounts::credentialRow($voter);
    expect(Accounts::mail())->toBe([])
        ->and($row['email_status'])->toBe('failed')
        ->and($row['email_error'])->toBe('address_changed');
});

it('sends to the address the run was made for [FR-CRED-04] (review fix 4)', function () {
    [$user, $election, $voter, $code] = rvSetup();
    rvSet($voter, ['email_hash' => rvSnapshot(' ROSE@example.ht ')]);

    (new SendCredentialEmail($voter, $election, $code))->handle();

    expect(Accounts::mailTo('rose@example.ht'))->toHaveCount(1)
        ->and(Accounts::credentialRow($voter)['email_status'])->toBe('sent')
        ->and(Accounts::credentialRow($voter)['email_hash'])->toBeNull();
});

it('sends once when the same message is delivered twice [FR-CRED-04] (review fix 5)', function () {
    [$user, $election, $voter, $code] = rvSetup();

    (new SendCredentialEmail($voter, $election, $code))->handle();
    (new SendCredentialEmail($voter, $election, $code))->handle();

    expect(Accounts::mail())->toHaveCount(1);
});

it('does not send a message whose status is no longer queued, nor one another worker holds [FR-CRED-04] (review fix 5)', function () {
    [$user, $election, $voter, $code] = rvSetup(status: 'failed');
    (new SendCredentialEmail($voter, $election, $code))->handle();
    expect(Accounts::mail())->toBe([])->and(Accounts::credentialRow($voter)['email_status'])->toBe('failed');

    [$user, $election, $held, $heldCode] = rvSetup();
    rvSet($held, ['email_sent_at' => now('UTC')->toDateTimeString()]);
    (new SendCredentialEmail($held, $election, $heldCode))->handle();
    expect(Accounts::mail())->toBe([])->and(Accounts::credentialRow($held)['email_status'])->toBe('queued');
});

it('marks the message of a suspended institution failed and sends nothing [FR-CRED-04] (review fix 5)', function () {
    [$user, $election, $voter, $code] = rvSetup();
    Accounts::suspend($user['institution']);

    (new SendCredentialEmail($voter, $election, $code))->handle();

    $row = Accounts::credentialRow($voter);
    expect(Accounts::mail())->toBe([])
        ->and($row['email_status'])->toBe('failed')
        ->and($row['email_error'])->toBe('institution_suspended');
});

it('keeps the retry counter by voter and code, and clears it on an early return [FR-CRED-04] (review fix 5)', function () {
    [$user, $election, $voter, $code] = rvSetup();
    config([
        'credentials.email_tries' => 3,
        'mail.default' => 'unreachable',
        'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1],
    ]);

    (new SendCredentialEmail($voter, $election, $code))->handle();
    $key = 'credential-email-failures:'.$voter.':'.bin2hex(Codes::hash($code));
    expect((int) Cache::get($key))->toBe(1)
        // The marker was put back for the retry.
        ->and(Accounts::credentialRow($voter)['email_sent_at'])->toBeNull();

    // The code is replaced: the message is moot and its counter goes.
    (new SendCredentialEmail($voter, $election, $code))->handle();
    expect((int) Cache::get($key))->toBe(2);
    rvSet($voter, ['code_hash' => Codes::hash(Codes::fresh())]);
    (new SendCredentialEmail($voter, $election, $code))->handle();

    expect(Cache::get($key))->toBeNull();
});

it('releases with a delay on an exception that is not the transport instead of spinning [FR-CRED-04] (review fix 5)', function () {
    [$user, $election, $voter, $code] = rvSetup();
    Event::listen('eloquent.retrieved: '.Credential::class, function (): void {
        throw new RuntimeException('The database is not there.');
    });
    $job = new SendCredentialEmail($voter, $election, $code);
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => is_int($delay) && $delay > 0));
    $job->setJob($queued);

    $job->handle();

    Event::forget('eloquent.retrieved: '.Credential::class);
    expect(Accounts::mail())->toBe([]);
});

it('has a backoff and no time limit that gives a waiting message up [FR-CRED-04] (review fix 5)', function () {
    $job = new SendCredentialEmail('v', 'e', 'c');

    expect($job->backoff())->toBeArray()->not->toBeEmpty()
        ->and(method_exists($job, 'retryUntil'))->toBeFalse();
});

it('throttles by institution so one institution waiting does not hold another [FR-CRED-04] (review fix 5)', function () {
    config(['credentials.email_per_minute' => 1, 'credentials.email_platform_per_minute' => 100]);
    [, , $a] = rvSetup();
    [, , $b] = rvSetup();
    $middleware = new ThrottleCredentialEmails;
    $make = fn (string $voter) => new class($voter)
    {
        public ?int $released = null;

        public function __construct(private readonly string $voter) {}

        public function voterUuid(): string
        {
            return $this->voter;
        }

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };
    $ran = 0;
    $run = function (object $job) use ($middleware, &$ran): void {
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });
    };

    $first = $make($a);
    $second = $make($a);
    $other = $make($b);
    $run($first);
    $run($second);
    $run($other);

    expect($first->released)->toBeNull()
        ->and($second->released)->toBeGreaterThan(0)
        ->and($other->released)->toBeNull()
        ->and($ran)->toBe(2);
});

it('holds every institution under the platform cap [FR-CRED-04] (review fix 5)', function () {
    config(['credentials.email_per_minute' => 100, 'credentials.email_platform_per_minute' => 1]);
    [, , $a] = rvSetup();
    [, , $b] = rvSetup();
    $middleware = new ThrottleCredentialEmails;
    $ran = 0;
    $released = [];

    foreach ([$a, $b] as $voter) {
        $job = new class($voter)
        {
            public ?int $released = null;

            public function __construct(private readonly string $voter) {}

            public function voterUuid(): string
            {
                return $this->voter;
            }

            public function release(int $delay): void
            {
                $this->released = $delay;
            }
        };
        $middleware->handle($job, function () use (&$ran): void {
            $ran++;
        });
        $released[] = $job->released;
    }

    expect($ran)->toBe(1)->and($released[0])->toBeNull()->and($released[1])->toBeGreaterThan(0);
});

it('gives each run its own identifier, even for two runs in the same second [FR-CRED-04] (review fix 3)', function () {
    [$user, $election, $rose] = rvSetup([], null, false);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'file' => 'csv'])->assertStatus(202);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'email' => 'jean@example.ht']);
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Marie Joseph']);
    $second = $this->browser->post("/api/v1/elections/{$election}/credential-runs", ['scope' => 'all', 'file' => 'xlsx'])->assertStatus(202);

    $rows = collect(Accounts::generatedFileRows($election));
    $slips = $rows->where('kind', 'slips_pdf')->values();
    $codes = $rows->whereIn('kind', ['codes_csv', 'codes_xlsx'])->values();
    expect($rows->pluck('status')->unique()->all())->toBe(['ready'])
        ->and($slips->pluck('run')->unique())->toHaveCount(2)
        ->and($slips[0]['run'])->toBe($codes[0]['run'])
        ->and($slips[1]['run'])->toBe($codes[1]['run'])
        // Each file of codes holds the voters of its own run, not the two runs added.
        ->and($codes[0]['voter_count'])->toBe(1)
        ->and($codes[1]['voter_count'])->toBe(2)
        ->and($second->json('data.voters'))->toBe(2);
});

it('gives a reissue a run of its own [FR-CRED-04] (review fix 3)', function () {
    [$user, $election, $rose] = rvSetup([], null, false);
    $this->browser->login($user['email'], $user['password'])->assertOk();

    $this->browser->post("/api/v1/voters/{$rose}/credential")->assertCreated();

    expect(Accounts::generatedFileRows($election)[0]['run'])->toMatch(UUID_V4);
});

it('fails the messages a crashed file had queued, and only those [FR-CRED-04] (review fix 6)', function () {
    [$user, $election, $rose] = rvSetup([], 'queued', false);
    $jean = Accounts::plantVoter(['election' => $election, 'full_name' => 'Jean Pierre', 'email' => 'jean@example.ht']);
    Accounts::plantCredential(['voter' => $rose, 'email_status' => 'queued']);
    Accounts::plantCredential(['voter' => $jean, 'email_status' => 'queued']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    $db = DB::connection(useMigratorConnection());
    $fileId = $db->table('generated_files')->where('uuid', $file)->value('id');
    $db->table('generated_files')->where('uuid', $file)->update(['stored_file' => '7d2f1c5e-8a41-4f3b-9c55-2a6e0b9d1f10']);
    rvSet($rose, ['email_file_id' => $fileId]);

    app(GenerateSlipFile::class)->fail($file, new RuntimeException('Killed.'));

    $row = Accounts::credentialRow($rose);
    expect($row['email_status'])->toBe('failed')
        ->and($row['email_error'])->toBe('send_failed')
        ->and(Accounts::credentialRow($jean)['email_status'])->toBe('queued')
        ->and(collect(Accounts::generatedFileRows($election))->firstWhere('uuid', $file)['status'])->toBe('failed');
});

it('fails the messages of a file taken for lost when the next run starts [FR-CRED-04] (review fix 6)', function () {
    [$user, $election, $rose] = rvSetup([], 'queued');
    Accounts::plantVoter(['election' => $election, 'full_name' => 'Zoé Nouvelle']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1, 'created_at' => now('UTC')->subMinutes(20)->toDateTimeString()]);
    $db = DB::connection(useMigratorConnection());
    rvSet($rose, ['email_file_id' => $db->table('generated_files')->where('uuid', $file)->value('id')]);
    $model = Election::withoutInstitutionScope()->where('uuid', $election)->firstOrFail();
    Auth::guard()->setUser(User::withoutGlobalScopes()->where('institution_id', $model->institution_id)->firstOrFail());

    app(StartCredentialRun::class)($model, 'all', null, null);

    $row = Accounts::credentialRow($rose);
    expect($row['email_status'])->toBe('failed')->and($row['email_error'])->toBe('send_failed');
});

it('fails the messages of a file the sweep takes for stuck [FR-CRED-04] (review fix 6)', function () {
    [$user, $election, $rose] = rvSetup([], 'queued');
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'created_at' => now('UTC')->subHours(2)->toDateTimeString()]);
    $db = DB::connection(useMigratorConnection());
    rvSet($rose, ['email_file_id' => $db->table('generated_files')->where('uuid', $file)->value('id')]);

    expect(Artisan::call('files:purge'))->toBe(0);

    expect(Accounts::credentialRow($rose)['email_status'])->toBe('failed');
});

it('removes the partial it wrote when the write of the file of codes is undone [FR-CRED-04] (review fix 10)', function () {
    [$user, $election] = rvSetup([], null, false);
    $at = now('UTC')->toDateTimeString();
    $slips = Accounts::plantGeneratedFile(['election' => $election, 'created_at' => $at, 'status' => 'queued']);
    $codes = Accounts::plantGeneratedFile(['election' => $election, 'created_at' => $at, 'status' => 'queued']);
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $codes)->update(['kind' => 'codes_csv']);
    $model = GeneratedFile::withoutInstitutionScope()->where('uuid', $slips)->firstOrFail();
    $before = Codes::storedFiles();

    Event::listen('eloquent.saving: '.GeneratedFile::class, function (): void {
        throw new RuntimeException('The database went away.');
    });

    try {
        app(AppendCodeRows::class)($model, [['Rose', '', 'ABCDE-FGHJK', 'http://x']]);
    } catch (RuntimeException) {
        // The failure goes to the caller, which does not fail the slips.
    } finally {
        Event::forget('eloquent.saving: '.GeneratedFile::class);
    }

    expect(Codes::storedFiles())->toBe($before);
});

it('writes a name or title with a line break on one line of the text part [NFR-SEC-02] (review fix 7)', function () {
    $mail = new CredentialMail(
        voterName: "Rose\r\n\tDésir\u{0085}Jean",
        electionTitle: "Conseil\nSuite\u{2028}Fin",
        electionUuid: '00000000-0000-4000-8000-000000000001',
        institution: "École\x00Nationale",
        startsAt: Carbon::parse('2030-03-05 12:00:00', 'UTC'),
        endsAt: Carbon::parse('2030-03-05 19:00:00', 'UTC'),
        timezone: 'America/Port-au-Prince',
        code: 'ABCDE-FGHJK',
    );

    $text = $mail->render();
    $content = $mail->content();

    expect($content->with['intro'])->toContain('Rose')->toContain('Désir Jean')
        ->and($content->with['intro'])->toContain('École Nationale')
        ->and($content->with['intro'])->not->toMatch('/[\x00-\x08\x0A-\x1F\x7F]/')
        ->and($content->with['preheader'])->toContain('Conseil Suite Fin')
        ->and($text)->not->toBeEmpty();
});

it('names the file of codes of the voters with no group after them [FR-CRED-04] (review fix 9)', function () {
    expect(SlipFileName::codes(null, 'csv', true))->toBe('codes-sans-groupe.csv')
        ->and(SlipFileName::codes(null, 'csv'))->toBe('codes-tous.csv')
        ->and(SlipFileName::codes('4e Année', 'xlsx', true))->toBe('codes-4e-annee.xlsx');
});
