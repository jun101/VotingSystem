<?php

namespace App\Console\Commands;

use App\Support\Exports\FormulaGuard;
use App\Support\Voting\AuditSeal;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Reads the sealed vote audit of one election and writes it as a CSV (docs/api/system/COMMAND-vote-audit-read.md,
 * FR-SEC-09). The platform operator runs it on their own machine, with the private key given for this use only.
 *
 * This is the one deliberate exception to "the application never reads `vote_audit`": it reads through the
 * `audit_reader` connection (a SELECT-only account on the operator's side), never writes to the database, and
 * refuses to run where `APP_ENV` is `production`. The key, the voters, the votes and the candidates are never
 * printed and never logged: the log line holds the operator, the election, the reason, a count and the outcome.
 */
class ReadVoteAudit extends Command
{
    protected $signature = 'vote-audit:read
        {election : The election UUID}
        {--reason= : Why the audit is read (5 to 300 characters)}
        {--operator= : The operator name, written to the log}
        {--key-file= : File holding the private key as 64 hex characters}
        {--key-stdin : Read the private key from standard input}
        {--out= : The CSV file to write (it must not exist)}
        {--log= : The operator log file (default storage/logs/vote-audit-reads.log)}';

    protected $description = 'Read the sealed vote audit of one election into a CSV (operator machine only)';

    private const HEADER = ['record', 'election', 'ballot', 'ballot_title', 'voter', 'voter_name', 'candidates', 'voted_at'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This command is never run on the server.');

            return self::FAILURE;
        }

        $reason = trim($this->text($this->option('reason')));
        $operator = trim($this->text($this->option('operator')));
        $out = $this->text($this->option('out'));
        $keyFile = $this->text($this->option('key-file'));
        $stdin = $this->option('key-stdin') === true;
        $election = $this->text($this->argument('election'));

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) {
            return $this->refuse('--reason is required, 5 to 300 characters.');
        }

        if ($operator === '') {
            return $this->refuse('--operator is required.');
        }

        if ($out === '') {
            return $this->refuse('--out is required.');
        }

        if (($keyFile === '') === (! $stdin)) {
            return $this->refuse('Give exactly one of --key-file and --key-stdin.');
        }

        if (file_exists($out)) {
            return $this->refuse('The output file already exists.');
        }

        $hex = $stdin ? $this->text($this->secret('Private key')) : $this->readKeyFile($keyFile);
        $secret = preg_match('/\A[0-9a-fA-F]{64}\z/', trim($hex)) === 1 ? hex2bin(trim($hex)) : false;
        unset($hex);

        if ($secret === false) {
            return $this->refuse('The key must be 64 hexadecimal characters.');
        }

        $log = $this->text($this->option('log'));
        $log = $log !== '' ? $log : storage_path('logs/vote-audit-reads.log');
        $entry = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'operator' => $operator, 'election' => $election, 'reason' => $reason];

        $db = DB::connection('audit_reader');
        $electionId = Str::isUuid($election) ? $db->table('elections')->where('uuid', $election)->value('id') : null;

        if ($electionId === null) {
            $this->writeLog($log, $entry + ['records' => 0, 'outcome' => 'unknown_election']);
            $this->error('Unknown election.');

            return self::FAILURE;
        }

        $rows = $this->records($db, is_numeric($electionId) ? (int) $electionId : 0, $secret);

        if ($rows === null) {
            $this->writeLog($log, $entry + ['records' => 0, 'outcome' => 'key_rejected']);
            $this->error('The key does not open the records.');

            return self::FAILURE;
        }

        if (! $this->writeCsv($out, $rows)) {
            $this->error('The output file could not be written.');

            return self::FAILURE;
        }

        $this->writeLog($log, $entry + ['records' => count($rows), 'outcome' => 'read']);
        $this->info(count($rows).' records written.');

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function seconds(string $bytes): int
    {
        $unpacked = unpack('J', $bytes);

        return is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function readKeyFile(string $path): string
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return $content === false ? '' : $content;
    }

    /**
     * The election's records, opened and joined; null when one record does not open with the key.
     *
     * @return list<list<string>>|null
     */
    private function records(ConnectionInterface $db, int $electionId, string $secret): ?array
    {
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
        $plains = [];

        foreach ($db->table('vote_audit')->where('election_id', $electionId)->get() as $row) {
            $plain = is_string($row->payload) ? sodium_crypto_box_seal_open($row->payload, $pair) : false;

            if ($plain === false || strlen($plain) !== AuditSeal::CONTENT_BYTES || ord($plain[0]) !== AuditSeal::VERSION) {
                return null;
            }

            $plains[] = $plain;
        }

        $parsed = [];

        foreach ($plains as $plain) {
            $uuid = fn (int $at): string => Uuid::fromBytes(substr($plain, $at, 16))->toString();
            $parsed[] = [
                'record' => $uuid(1), 'election' => $uuid(17), 'vote' => $uuid(33), 'voter' => $uuid(49), 'ballot' => $uuid(65),
                'at' => $this->seconds(substr($plain, 81, 8)),
            ];
        }

        $ballots = $db->table('ballots')->where('election_id', $electionId)->pluck('title', 'uuid');
        $voters = $db->table('voters')->where('election_id', $electionId)->pluck('full_name', 'uuid');
        $choices = $db->table('vote_choices')->whereIn('vote_uuid', array_column($parsed, 'vote'))->get();
        $people = $db->table('candidates')->whereIn('id', $choices->pluck('candidate_id')->unique()->all())->get()->keyBy('id');
        $candidates = [];

        foreach ($choices as $choice) {
            $person = $people[$choice->candidate_id] ?? null;
            $candidates[$this->text($choice->vote_uuid)][] = $person === null ? '' : trim($this->text($person->first_name).' '.$this->text($person->last_name));
        }

        $rows = [];

        foreach ($parsed as $p) {
            $list = $candidates[$p['vote']] ?? [];
            sort($list);
            $rows[] = [
                $p['record'], $p['election'], $p['ballot'],
                $this->text($ballots[$p['ballot']] ?? ''),
                $p['voter'],
                $this->text($voters[$p['voter']] ?? ''),
                implode('; ', $list),
                gmdate('Y-m-d\TH:i:s\Z', $p['at']),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a[7], $a[4], $a[2]] <=> [$b[7], $b[4], $b[2]]);

        return $rows;
    }

    /** @param  list<list<string>>  $rows */
    private function writeCsv(string $path, array $rows): bool
    {
        $mask = umask(0177);

        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($mask);
        }

        if ($handle === false) {
            return false;
        }

        try {
            @chmod($path, 0600);
            fputcsv($handle, self::HEADER, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(FormulaGuard::guard(...), $row), ',', '"', '');
            }
        } catch (Throwable) {
            fclose($handle);
            @unlink($path);

            return false;
        }

        return fclose($handle);
    }

    /** @param  array<string, mixed>  $line */
    private function writeLog(string $path, array $line): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        file_put_contents($path, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }
}
