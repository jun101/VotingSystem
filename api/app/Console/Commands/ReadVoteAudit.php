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

        if (! is_string(config('database.connections.audit_reader.username')) || config('database.connections.audit_reader.username') === '') {
            return $this->refuse('No audit_reader account is configured (DB_AUDIT_READER_*).');
        }

        $hex = $stdin ? $this->readKeyFromInput() : $this->readKeyFile($keyFile);
        $secret = preg_match('/\A[0-9a-fA-F]{64}\z/', trim($hex)) === 1 ? hex2bin(trim($hex)) : false;
        unset($hex);

        if ($secret === false) {
            return $this->refuse('The key must be 64 hexadecimal characters.');
        }

        $log = $this->text($this->option('log'));
        $log = $log !== '' ? $log : storage_path('logs/vote-audit-reads.log');
        $entry = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'operator' => $operator, 'election' => $election, 'reason' => $reason];

        try {
            $db = DB::connection('audit_reader');
            $electionId = Str::isUuid($election) ? $db->table('elections')->where('uuid', $election)->value('id') : null;

            if ($electionId === null) {
                return $this->logged($log, $entry + ['records' => 0, 'outcome' => 'unknown_election'], 'Unknown election.');
            }

            $rows = $this->records($db, is_numeric($electionId) ? (int) $electionId : 0, $secret);
        } catch (Throwable) {
            // The use got as far as reading: it is on the log, with no message that could carry the key or a record.
            return $this->logged($log, $entry + ['records' => 0, 'outcome' => 'error'], 'The records could not be read.');
        } finally {
            sodium_memzero($secret);
        }

        if ($rows === null) {
            return $this->logged($log, $entry + ['records' => 0, 'outcome' => 'key_rejected'], 'The key does not open the records.');
        }

        // The use is logged before the file exists: no CSV without its line.
        if (! $this->writeLog($log, $entry + ['records' => count($rows), 'outcome' => 'read'])) {
            $this->error('The log line could not be written: nothing was kept.');

            return self::FAILURE;
        }

        if (! $this->writeCsv($out, $rows)) {
            $this->error('The output file could not be written.');

            return self::FAILURE;
        }

        $this->info(count($rows).' records written.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $line */
    private function logged(string $log, array $line, string $message): int
    {
        if (! $this->writeLog($log, $line)) {
            $message .= ' The log line could not be written either.';
        }

        $this->error($message);

        return self::FAILURE;
    }

    /** The key from a hidden prompt, or from a pipe when the input is not a terminal (`cat key | … --key-stdin`). */
    private function readKeyFromInput(): string
    {
        if ($this->input->isInteractive()) {
            return $this->text($this->secret('Private key'));
        }

        $content = stream_get_contents(STDIN);

        return $content === false ? '' : $content;
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
    private function records(ConnectionInterface $db, int $electionId, #[\SensitiveParameter] string $secret): ?array
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
        // In chunks: one placeholder per vote would pass the 65 535 of a prepared statement on a large election.
        /** @var array<string, list<int>> $voteCandidates vote uuid => candidate ids */
        $voteCandidates = [];

        foreach (array_chunk(array_column($parsed, 'vote'), 1000) as $chunk) {
            foreach ($db->table('vote_choices')->whereIn('vote_uuid', $chunk)->get(['vote_uuid', 'candidate_id']) as $choice) {
                $voteCandidates[$this->text($choice->vote_uuid)][] = is_numeric($choice->candidate_id) ? (int) $choice->candidate_id : 0;
            }
        }

        $ids = array_values(array_unique(array_merge([], ...array_values($voteCandidates))));
        /** @var array<int, string> $names candidate id => full name */
        $names = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($db->table('candidates')->whereIn('id', $chunk)->get(['id', 'first_name', 'last_name']) as $person) {
                $names[is_numeric($person->id) ? (int) $person->id : 0] = trim($this->text($person->first_name).' '.$this->text($person->last_name));
            }
        }

        $candidates = [];

        foreach ($voteCandidates as $vote => $candidateIds) {
            foreach ($candidateIds as $candidateId) {
                $candidates[$vote][] = $names[$candidateId] ?? '';
            }
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
    private function writeLog(string $path, array $line): bool
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // A log that did not exist is created for the operator alone.
        $mask = umask(0177);

        try {
            return @file_put_contents($path, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX) !== false;
        } finally {
            umask($mask);
        }
    }
}
