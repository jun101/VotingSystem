<?php

/*
 * php artisan vote-audit:read — docs/api/system/COMMAND-vote-audit-read.md
 * FR-SEC-09: the audit is read only by the operator, with the private key given for one use, and every use is logged.
 */

use Illuminate\Support\Facades\Route;
use Tests\Support\Voting;

const AUDIT_UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

/** An election with two votes. Returns [election data, key file path, out path, log path]. */
function auditReadSetup($test): array
{
    $e = Voting::election();
    foreach (['Wideline', 'Paul'] as $who) {
        $voter = Voting::browser($test);
        Voting::enter($voter, $e['election'], $e['codes'][$who])->assertStatus(201);
        $voter->post("/api/v1/vote/{$e['election']}/submit", Voting::body($e, $who === 'Paul' ? ['S' => ['D', 'E']] : ['G' => ['H']]), [], false)->assertStatus(201);
    }
    $dir = sys_get_temp_dir().'/audit-read-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $key = $dir.'/key.hex';
    file_put_contents($key, bin2hex(sodium_crypto_box_secretkey($test->auditPair)));

    return [$e, $key, $dir.'/out.csv', $dir.'/reads.log'];
}

function auditRead($test, string $election, array $options)
{
    return $test->artisan('vote-audit:read', ['election' => $election] + $options);
}

it('writes one CSV row per vote with the voter, the ballot, the candidates and the time, and logs the use [FR-SEC-09] (scenarios 1, 8)', function () {
    [$e, $key, $out, $log] = auditReadSetup($this);

    auditRead($this, $e['election'], ['--reason' => 'Contestation du résultat', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log])->assertExitCode(0);

    $rows = array_map('str_getcsv', file($out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $header = array_shift($rows);
    expect($header)->toBe(['record', 'election', 'ballot', 'ballot_title', 'voter', 'voter_name', 'candidates', 'voted_at'])
        ->and($rows)->not->toBeEmpty();
    $byVoter = collect($rows)->map(fn ($r) => array_combine($header, $r))->groupBy('voter');
    expect($byVoter->keys()->all())->toContain($e['voters']['Wideline'])->toContain($e['voters']['Paul'])
        ->and($byVoter[$e['voters']['Wideline']]->pluck('voter_name')->unique()->all())->toBe(['Wideline Joseph'])
        ->and($byVoter[$e['voters']['Wideline']]->pluck('ballot_title')->all())->toContain('Délégué(e) de classe')
        ->and($byVoter[$e['voters']['Wideline']]->firstWhere('ballot_title', 'Délégué(e) de classe')['candidates'])->toBe('Candidat H Nom H')
        ->and(abs(strtotime($rows[0][7]) - time()))->toBeLessThan(120)
        ->and(substr(sprintf('%o', fileperms($out)), -4))->toBe('0600');
    $line = json_decode(trim(file_get_contents($log)), true);
    expect($line)->toMatchArray(['operator' => 'Jun', 'election' => $e['election'], 'reason' => 'Contestation du résultat', 'outcome' => 'read'])
        ->and($line['records'])->toBe(count($rows))
        ->and(file_get_contents($log))->not->toContain(file_get_contents($key))->not->toContain('Wideline')->not->toContain($e['voters']['Wideline']);
});

it('reads the key from standard input with --key-stdin [FR-SEC-09] (scenario 1)', function () {
    [$e, $key, $out, $log] = auditReadSetup($this);

    $this->artisan('vote-audit:read', ['election' => $e['election'], '--reason' => 'Vérification', '--operator' => 'Jun', '--key-stdin' => true, '--out' => $out, '--log' => $log])
        ->expectsQuestion('Private key', trim(file_get_contents($key)))
        ->assertExitCode(0);

    expect(is_file($out))->toBeTrue();
});

it('writes only the header and logs zero records for an election with no vote [FR-SEC-09] (scenario 2)', function () {
    [, $key, $out, $log] = auditReadSetup($this);
    $empty = Voting::election();

    auditRead($this, $empty['election'], ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log])->assertExitCode(0);

    expect(file($out))->toHaveCount(1)->and(json_decode(trim(file_get_contents($log)), true)['records'])->toBe(0);
});

it('writes no file and logs key_rejected for a wrong key [FR-SEC-09] (scenario 3)', function () {
    [$e, , $out, $log] = auditReadSetup($this);
    $wrong = dirname($out).'/wrong.hex';
    file_put_contents($wrong, bin2hex(sodium_crypto_box_secretkey(sodium_crypto_box_keypair())));

    auditRead($this, $e['election'], ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $wrong, '--out' => $out, '--log' => $log])->assertExitCode(1);

    expect(is_file($out))->toBeFalse()->and(json_decode(trim(file_get_contents($log)), true)['outcome'])->toBe('key_rejected');
});

it('refuses bad options and reads nothing [FR-SEC-09] (scenario 4)', function (array $change) {
    [$e, $key, $out, $log] = auditReadSetup($this);
    $options = ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log];
    foreach ($change as $name => $value) {
        if ($value === null) {
            unset($options[$name]);
        } else {
            $options[$name] = $value;
        }
    }

    auditRead($this, $e['election'], $options)->assertExitCode(1);

    expect(is_file($out))->toBeFalse();
})->with([
    'no reason' => [['--reason' => null]],
    'short reason' => [['--reason' => 'ok']],
    'no operator' => [['--operator' => null]],
    'no out' => [['--out' => null]],
    'no key' => [['--key-file' => null]],
    'both keys' => [['--key-stdin' => true]],
]);

it('refuses a key that is not 64 hex characters [FR-SEC-09] (scenario 4)', function () {
    [$e, $key, $out, $log] = auditReadSetup($this);
    file_put_contents($key, 'not a key');

    auditRead($this, $e['election'], ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log])->assertExitCode(1);

    expect(is_file($out))->toBeFalse();
});

it('refuses an unknown election or a value that is not a UUID, and logs it [FR-SEC-09] (scenario 5)', function (string $election) {
    [, $key, $out, $log] = auditReadSetup($this);

    auditRead($this, $election, ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log])->assertExitCode(1);

    expect(is_file($out))->toBeFalse();
})->with([AUDIT_UNKNOWN, 'not-a-uuid', '12']);

it('does not overwrite an existing output file [FR-SEC-09] (scenario 6)', function () {
    [$e, $key, $out, $log] = auditReadSetup($this);
    file_put_contents($out, 'precious');

    auditRead($this, $e['election'], ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log])->assertExitCode(1);

    expect(file_get_contents($out))->toBe('precious');
});

it('refuses to run in production, where the server is [NFR-SEC-09] (scenario 7)', function () {
    [$e, $key, $out, $log] = auditReadSetup($this);
    app()->detectEnvironment(fn () => 'production');

    auditRead($this, $e['election'], ['--reason' => 'Vérification', '--operator' => 'Jun', '--key-file' => $key, '--out' => $out, '--log' => $log])->assertExitCode(1);

    expect(is_file($out))->toBeFalse();
});

it('is the only way to the audit: no route, and the application never selects from vote_audit [FR-SEC-09]', function () {
    $found = [];
    foreach (Route::getRoutes() as $route) {
        if (preg_match('#audit#i', $route->uri()) && ! str_starts_with($route->uri(), 'api/v1/audit')) {
            $found[] = $route->uri();
        }
    }
    $users = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), "table('vote_audit')")
            && str_contains((string) file_get_contents($file->getPathname()), 'select(')) {
            $users[] = $file->getPathname();
        }
    }

    expect($found)->toBe([])->and($users)->toBe([]);
});
