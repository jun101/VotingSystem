<?php

/*
 * The email that carries an access code (slice 11a): the job sends once and marks the voter `sent`,
 * a transport error is retried and, on the last try, marks the voter `failed` with a fixed reason
 * and returns normally, a deleted voter or a replaced code is ignored, the payload is encrypted,
 * the rate limit releases without failing, and a run that fails puts the old code back.
 */

use App\Actions\Credentials\GenerateSlipFile;
use App\Exceptions\ApiErrorRenderer;
use App\Exceptions\ApiException;
use App\Jobs\Middleware\ThrottleCredentialEmails;
use App\Jobs\SendCredentialEmail;
use App\Support\Credentials\SlipSheet;
use App\Support\Credentials\SlipWriter;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Accounts;
use Tests\Support\Codes;

beforeEach(function () {
    Accounts::reset();
    Codes::useTempDisk();
    RateLimiter::clear('credential-emails');
});

/** A draft election with one voter holding a `queued` code. Returns [election, voter, code]. */
function emailJobSetup(?string $address = 'rose@example.ht', string $status = 'queued'): array
{
    $user = Accounts::user(['email' => 'owner.'.bin2hex(random_bytes(4)).'@example.test']);
    $election = Accounts::plantElection(['institution' => $user['institution'], 'language' => 'en', 'title' => 'Student council']);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => $address]);
    $code = Accounts::plantCredential(['voter' => $voter, 'email_status' => $status]);

    return [$election, $voter, $code];
}

/** Makes the mailer fail at once: nothing listens on the port. */
function emailJobUnreachable(int $tries): void
{
    config([
        'credentials.email_tries' => $tries,
        'mail.default' => 'unreachable',
        'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1],
    ]);
}

it('sends the message once and marks the voter sent [FR-CRED-04] (email job 1)', function () {
    [$election, $voter, $code] = emailJobSetup();

    (new SendCredentialEmail($voter, $election, $code))->handle();

    $row = Accounts::credentialRow($voter);
    $mail = Accounts::mailTo('rose@example.ht');
    expect($mail)->toHaveCount(1)
        ->and($mail[0]['text'])->toContain($code)->and($mail[0]['text'])->toContain("/vote/{$election}#{$code}")
        ->and($mail[0]['html'])->toContain('lang="en"')
        ->and($row['email_status'])->toBe('sent')
        ->and($row['email_sent_at'])->not->toBeNull()
        ->and($row['email_error'])->toBeNull();
});

it('marks the voter failed with a fixed reason on the last try and returns normally [FR-CRED-04] (email job 2)', function () {
    [$election, $voter, $code] = emailJobSetup();
    emailJobUnreachable(1);

    (new SendCredentialEmail($voter, $election, $code))->handle();

    $row = Accounts::credentialRow($voter);
    expect($row['email_status'])->toBe('failed')
        ->and($row['email_error'])->toBe('send_failed');
});

it('releases the message with a delay while tries are left and keeps it queued [FR-CRED-04] (email job 3)', function () {
    [$election, $voter, $code] = emailJobSetup();
    emailJobUnreachable(3);

    $job = new SendCredentialEmail($voter, $election, $code);
    $queued = Mockery::mock(QueueJob::class);
    $queued->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => is_int($delay) && $delay > 0));
    $job->setJob($queued);
    $job->handle();

    expect(Accounts::credentialRow($voter)['email_status'])->toBe('queued');

    // The third failed send (the counter is the voter's, not the queue's attempts) ends in `failed`.
    $again = Mockery::mock(QueueJob::class);
    $again->shouldReceive('release')->once();
    $job->setJob($again);
    $job->handle();
    expect(Accounts::credentialRow($voter)['email_status'])->toBe('queued');

    $last = Mockery::mock(QueueJob::class);
    $last->shouldNotReceive('release');
    $job->setJob($last);
    $job->handle();
    expect(Accounts::credentialRow($voter)['email_status'])->toBe('failed');
});

it('ignores a voter deleted meanwhile [FR-CRED-04] (email job 4)', function () {
    [$election, $voter, $code] = emailJobSetup();
    DB::connection(useMigratorConnection())->table('voters')->where('uuid', $voter)->delete();

    (new SendCredentialEmail($voter, $election, $code))->handle();

    expect(Accounts::mail())->toBe([]);
});

it('ignores a code that was replaced meanwhile and leaves the status of the new one [FR-CRED-04] (email job 5)', function () {
    [$election, $voter, $code] = emailJobSetup();

    (new SendCredentialEmail($voter, $election, Codes::fresh()))->handle();

    expect(Accounts::mail())->toBe([])
        ->and(Accounts::credentialRow($voter)['email_status'])->toBe('queued');
});

it('marks a voter with no address failed without sending [FR-CRED-04] (email job 6)', function () {
    [$election, $voter, $code] = emailJobSetup(null);

    (new SendCredentialEmail($voter, $election, $code))->handle();

    expect(Accounts::mail())->toBe([])
        ->and(Accounts::credentialRow($voter)['email_status'])->toBe('failed');
});

it('holds only the two UUIDs and the code, encrypted in the queue payload [NFR-SEC-02] (email job 7)', function () {
    [$election, $voter, $code] = emailJobSetup();
    $job = new SendCredentialEmail($voter, $election, $code);

    $queue = app('queue')->connection('sync');
    $create = new ReflectionMethod($queue, 'createPayload');
    $payload = json_decode($create->invoke($queue, $job, 'default'), true);
    $command = $payload['data']['command'];

    expect($command)->not->toContain($code)->and($command)->not->toContain($voter)->and($command)->not->toContain($election)
        ->and($command)->not->toContain('rose@example.ht')
        ->and(json_encode($payload))->not->toContain($code);

    $plain = unserialize(app('encrypter')->decrypt($command));
    expect($plain)->toBeInstanceOf(SendCredentialEmail::class)
        ->and(array_values((array) $plain))->toContain($code)->toContain($voter)->toContain($election)
        ->and((array) $plain)->not->toHaveKey('email');
});

it('releases a message over the per-minute limit without failing it [FR-CRED-04] (email job 8)', function () {
    config(['credentials.email_per_minute' => 1]);
    $middleware = new ThrottleCredentialEmails;
    $job = new class
    {
        public ?int $released = null;

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };
    $ran = 0;

    $middleware->handle($job, function () use (&$ran): void {
        $ran++;
    });
    expect($ran)->toBe(1)->and($job->released)->toBeNull();

    $middleware->handle($job, function () use (&$ran): void {
        $ran++;
    });
    expect($ran)->toBe(1)->and($job->released)->toBeGreaterThan(0);
});

it('reports the count of an email_count_changed answer [FR-CRED-07] (email job 9)', function () {
    $response = (new ApiErrorRenderer)(new ApiException(409, 'email_count_changed', count: 7), Request::create('/api/v1/x', 'POST'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['error']['count'])->toBe(7)
        ->and($response->getData(true)['error']['code'])->toBe('email_count_changed');
});

/** A queued `failed_email` file for the election (one voter, no group). Returns its uuid. */
function emailJobReplaceFile(string $election): string
{
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $file)->update(['send_email' => 1, 'replace' => 1]);

    return $file;
}

it('gives a new code to the voter whose email failed and emails it [FR-CRED-04] (email job 10)', function () {
    [$election, $voter, $old] = emailJobSetup('rose@example.ht', 'failed');
    $file = emailJobReplaceFile($election);
    app()->instance(SlipWriter::class, new class implements SlipWriter
    {
        public function write(SlipSheet $sheet): string
        {
            return '%PDF-1.4 '.count($sheet->slips).' slips';
        }
    });

    app(GenerateSlipFile::class)($file);

    $row = Accounts::credentialRow($voter);
    $mail = Accounts::mailTo('rose@example.ht');
    expect($row['reissue_count'])->toBe(1)
        ->and($row['code_hash'])->not->toBe(Codes::hash($old))
        ->and($row['email_status'])->toBe('sent')
        ->and($mail)->toHaveCount(1)
        ->and($mail[0]['text'])->not->toContain($old)
        ->and(Accounts::generatedFileRows($election)[0]['status'])->toBe('ready');
});

it('puts the old code and its failed status back and sends nothing when the file fails [FR-CRED-04] (email job 11)', function () {
    [$election, $voter, $old] = emailJobSetup('rose@example.ht', 'failed');
    $file = emailJobReplaceFile($election);
    app()->instance(SlipWriter::class, new class implements SlipWriter
    {
        public function write(SlipSheet $sheet): string
        {
            throw new RuntimeException('The engine broke.');
        }
    });

    app(GenerateSlipFile::class)($file);

    $row = Accounts::credentialRow($voter);
    expect($row['code_hash'])->toBe(Codes::hash($old))
        ->and($row['reissue_count'])->toBe(0)
        ->and($row['email_status'])->toBe('failed')
        ->and(Accounts::mail())->toBe([])
        ->and(Accounts::generatedFileRows($election)[0]['status'])->toBe('failed');
});

it('leaves no queued status and no code for a first run that fails [FR-CRED-04] (email job 12)', function () {
    $user = Accounts::user(['email' => 'owner.'.bin2hex(random_bytes(4)).'@example.test']);
    $election = Accounts::plantElection(['institution' => $user['institution']]);
    $voter = Accounts::plantVoter(['election' => $election, 'full_name' => 'Rose Désir', 'email' => 'rose@example.ht']);
    $file = Accounts::plantGeneratedFile(['election' => $election, 'status' => 'queued', 'voter_count' => 1]);
    DB::connection(useMigratorConnection())->table('generated_files')->where('uuid', $file)->update(['send_email' => 1]);
    app()->instance(SlipWriter::class, new class implements SlipWriter
    {
        public function write(SlipSheet $sheet): string
        {
            throw new RuntimeException('The engine broke.');
        }
    });

    app(GenerateSlipFile::class)($file);

    expect(Accounts::credentialRow($voter))->toBeNull()
        ->and(Accounts::mail())->toBe([]);
    Cache::flush();
});
