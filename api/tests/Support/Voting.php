<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Builds an election that can be voted in, and reads back what a vote left (slice 12,
 * docs/slices/12-voting-flow.md): the voter's codes, the ballots with their candidates, the audit key pair.
 *
 * Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Voting
{
    /**
     * An institution with an open election (started an hour ago, ends in two days) holding:
     * - ballots `P` (general, 1 seat, blank allowed, candidates A B C), `S` (general, 2 seats, blank not allowed, candidates D E F)
     *   and `G` (group "4e année" only, 1 seat, blank allowed, candidates H I);
     * - groups "4e année" and "5e année";
     * - voters Wideline (4e année), Paul (5e année) and Marc (no group), each with a code.
     *
     * @param  array{status?: string, starts_at?: string, ends_at?: string, language?: string, candidate_order?: string, institution?: string}  $o
     * @return array{institution: string, election: string, groups: array<string, string>, ballots: array<string, string>, candidates: array<string, string>, voters: array<string, string>, codes: array<string, string>}
     */
    public static function election(array $o = []): array
    {
        $institution = $o['institution'] ?? Accounts::user()['institution'];
        $election = Accounts::plantElection([
            'institution' => $institution,
            'title' => 'Conseil des élèves 2026',
            'status' => $o['status'] ?? 'open',
            'starts_at' => $o['starts_at'] ?? now('UTC')->subHour()->toDateTimeString(),
            'ends_at' => $o['ends_at'] ?? now('UTC')->addDays(2)->toDateTimeString(),
            'language' => $o['language'] ?? 'fr',
            'candidate_order' => $o['candidate_order'] ?? 'manual',
        ]);
        $g4 = Accounts::plantGroup(['election' => $election, 'name' => '4e année']);
        $g5 = Accounts::plantGroup(['election' => $election, 'name' => '5e année']);
        $party = Accounts::plantParty(['election' => $election, 'name' => 'Avenir Étudiant', 'acronym' => 'AE']);

        $p = Accounts::plantBallot(['election' => $election, 'title' => 'Président(e)', 'seats' => 1, 'allow_blank' => true]);
        $s = Accounts::plantBallot(['election' => $election, 'title' => 'Secrétaires', 'seats' => 2, 'allow_blank' => false]);
        $g = Accounts::plantBallot(['election' => $election, 'title' => 'Délégué(e) de classe', 'seats' => 1, 'allow_blank' => true, 'groups' => [$g4]]);

        $candidates = [];
        foreach (['A' => $p, 'B' => $p, 'C' => $p, 'D' => $s, 'E' => $s, 'F' => $s, 'H' => $g, 'I' => $g] as $name => $ballot) {
            $candidates[$name] = Accounts::plantCandidate([
                'ballot' => $ballot,
                'first_name' => "Candidat {$name}",
                'last_name' => "Nom {$name}",
                'party' => $name === 'A' ? $party : null,
                'slogan' => "Slogan {$name}",
            ]);
        }

        $voters = [
            'Wideline' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Wideline Joseph', 'group' => $g4]),
            'Paul' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Paul Étienne', 'group' => $g5]),
            'Marc' => Accounts::plantVoter(['election' => $election, 'full_name' => 'Marc Sans-groupe']),
        ];
        $codes = [];
        foreach ($voters as $name => $voter) {
            $codes[$name] = Accounts::plantCredential(['voter' => $voter]);
        }

        return [
            'institution' => $institution,
            'election' => $election,
            'groups' => ['4e' => $g4, '5e' => $g5],
            'ballots' => ['P' => $p, 'S' => $s, 'G' => $g],
            'candidates' => $candidates,
            'voters' => $voters,
            'codes' => $codes,
        ];
    }

    /** The body of a submission for the ballots P and S (and G when given): the first candidates by default. */
    public static function body(array $e, array $choices = []): array
    {
        $choices += ['P' => ['A'], 'S' => ['D', 'E']];
        $ballots = [];
        foreach ($choices as $key => $names) {
            if ($names === null) {
                continue;
            }
            $ballots[] = ['ballot' => $e['ballots'][$key], 'candidates' => array_map(fn ($n) => $e['candidates'][$n], $names)];
        }

        return ['ballots' => $ballots];
    }

    /** Makes the test's audit key pair and gives the server its public half. Returns the secret key (raw bytes). */
    public static function keypair(): string
    {
        $pair = sodium_crypto_box_keypair();
        config(['votes.audit_public_key' => bin2hex(sodium_crypto_box_publickey($pair))]);

        return $pair;
    }

    /**
     * The audit records of an election, opened with the key pair.
     *
     * @return list<array{version: int, record: string, election: string, vote: string, voter: string, ballot: string, at: int}>
     */
    public static function audit(string $election, string $pair): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');
        $out = [];

        foreach ($db->table('vote_audit')->where('election_id', $id)->get() as $row) {
            $plain = sodium_crypto_box_seal_open($row->payload, $pair);
            if ($plain === false) {
                throw new \RuntimeException('audit payload cannot be opened');
            }
            $uuid = fn (int $at) => Uuid::fromBytes(substr($plain, $at, 16))->toString();
            $out[] = [
                'version' => ord($plain[0]),
                'record' => $uuid(1),
                'election' => $uuid(17),
                'vote' => $uuid(33),
                'voter' => $uuid(49),
                'ballot' => $uuid(65),
                'at' => (int) unpack('J', substr($plain, 81, 8))[1],
                'row_uuid' => $row->uuid,
                'size' => strlen($row->payload),
            ];
        }

        return $out;
    }

    /** A fresh browser for one voter: the cookies of two voters never mix. */
    public static function browser($test): AuthClient
    {
        return new AuthClient($test);
    }

    /** Opens a voter session on that browser. Returns the response. */
    public static function enter(AuthClient $browser, string $election, string $code)
    {
        return $browser->post("/api/v1/vote/{$election}/session", ['code' => $code], [], false);
    }

    /** Forgets the rate-limit counters and sessions held in the cache (between tests). */
    public static function forgetCache(): void
    {
        Cache::flush();
    }

    /** Counts of the secrecy tables of an election: [participations, votes, vote_choices, vote_audit]. */
    public static function counts(string $election): array
    {
        $db = DB::connection(useMigratorConnection());
        $id = $db->table('elections')->where('uuid', $election)->value('id');
        $votes = $db->table('votes')->where('election_id', $id)->pluck('uuid')->all();

        return [
            $db->table('participations')->where('election_id', $id)->count(),
            count($votes),
            $votes === [] ? 0 : $db->table('vote_choices')->whereIn('vote_uuid', $votes)->count(),
            $db->table('vote_audit')->where('election_id', $id)->count(),
        ];
    }
}
