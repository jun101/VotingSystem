<?php

namespace App\Actions\Elections;

use App\Models\Candidate;
use App\Models\Election;
use App\Models\Institution;
use App\Support\Media\ImageReEncoder;
use App\Support\Reports\ResultsReport;
use Illuminate\Support\Facades\DB;

/**
 * The results of an election as the outside world sees them (docs/api/publication/): the public JSON of a
 * published election and the data of the PDF report. The numbers are those of `ComputeResults`, never counted
 * again; nothing here reads `vote_audit`, a voter, a check (in the JSON) or a numeric key.
 *
 * Stateless: the election is an argument.
 */
final class ComposePublishedResults
{
    public function __construct(private readonly ComputeResults $results) {}

    /**
     * The `data` of GET /public/elections/{election}/results. The election is published and was found by its
     * uuid, without the tenant scope (nobody is signed in).
     *
     * @return array<string, mixed>
     */
    public function payload(Election $election): array
    {
        $computed = ($this->results)($election, null, true);
        $winnersOnly = $election->results_display->value === 'winners';
        [$eligible, $voted] = $this->turnout($election);

        // The photos of the candidates, by candidate uuid: the same public path the voters get. Off the tenant
        // scope for the same reason as the counting; limited to this election.
        $photos = Candidate::withoutInstitutionScope()
            ->where('election_id', $election->id)
            ->whereNotNull('photo_file')
            ->pluck('photo_file', 'uuid')
            ->all();
        $order = $winnersOnly ? $this->ballotOrder($election) : [];

        $ballots = [];

        foreach ($computed as $ballot) {
            $shown = [];
            $tied = $ballot['tie']['candidates'] ?? [];
            $candidates = $ballot['candidates'];
            $winners = $ballot['winners'];
            $tie = $ballot['tie'];

            // The `winners` display lists the shown candidates in the ballot's own order, never by votes: the
            // order would give the ranking away. The `full` display keeps the order of the votes.
            if ($winnersOnly) {
                $candidates = $this->rowsInBallotOrder($candidates, $order);
                $winners = $this->uuidsInBallotOrder($winners, $order);
                $tied = $this->uuidsInBallotOrder($tied, $order);

                if ($tie !== null) {
                    $tie['candidates'] = $tied;
                }
            }

            foreach ($candidates as $candidate) {
                if ($winnersOnly && ! in_array($candidate['id'], $winners, true) && ! in_array($candidate['id'], $tied, true)) {
                    continue;
                }

                $photo = $photos[$candidate['id']] ?? null;
                $row = [
                    'id' => $candidate['id'],
                    'first_name' => $candidate['first_name'],
                    'last_name' => $candidate['last_name'],
                    'party' => $candidate['party'],
                    'photo' => is_string($photo) ? '/media/'.ImageReEncoder::nameOf($photo, 480) : null,
                ];

                if (! $winnersOnly) {
                    $row['votes'] = $candidate['votes'];
                    $row['percentage'] = $candidate['percentage'];
                }

                $shown[] = $row;
            }

            $names = array_map(static fn (array $group): string => $group['name'], $ballot['groups']);

            $ballots[] = $winnersOnly
                ? [
                    'id' => $ballot['id'], 'title' => $ballot['title'], 'seats' => $ballot['seats'], 'groups' => $names,
                    'candidates' => $shown, 'winners' => $winners, 'tie' => $tie, 'note' => $ballot['note'],
                ]
                : [
                    'id' => $ballot['id'], 'title' => $ballot['title'], 'seats' => $ballot['seats'], 'groups' => $names,
                    'cast' => $ballot['cast'], 'blank' => $ballot['blank'],
                    'candidates' => $shown, 'winners' => $ballot['winners'], 'tie' => $ballot['tie'], 'note' => $ballot['note'],
                ];
        }

        $institution = Institution::query()->whereKey($election->institution_id)->first();
        $logo = $institution?->logo_file;

        return [
            'id' => $election->uuid,
            'title' => $election->title,
            'language' => $election->language,
            'timezone' => $election->timezone,
            'starts_at' => $election->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $election->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'published_at' => $election->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'display' => $election->results_display->value,
            'institution' => [
                'id' => $institution->uuid ?? '',
                'name' => $institution->name ?? '',
                'logo' => $logo === null ? null : [
                    'sm' => '/media/'.ImageReEncoder::nameOf($logo, 64),
                    'md' => '/media/'.ImageReEncoder::nameOf($logo, 160),
                    'lg' => '/media/'.ImageReEncoder::nameOf($logo, 480),
                ],
            ],
            'turnout' => ['eligible' => $eligible, 'voted' => $voted, 'percentage' => ComputeTurnout::percentage($voted, $eligible)],
            'ballots' => $ballots,
        ];
    }

    /**
     * The data of the report.
     *
     * @param  bool  $public  nobody is signed in: the tenant scope is off (the election was found by its uuid)
     * @param  bool  $full  the counts are shown (always for the institution; the election's setting for the public)
     */
    public function report(Election $election, bool $public, bool $full): ResultsReport
    {
        $computed = ($this->results)($election, null, $public);

        // Without the counts (the public report of a `winners` display) the candidates, the winners and the tied
        // are in the ballot's own order, never by votes: the order would give the ranking away.
        if (! $full) {
            $order = $this->ballotOrder($election);

            foreach ($computed as $i => $ballot) {
                $computed[$i]['candidates'] = $this->rowsInBallotOrder($ballot['candidates'], $order);
                $computed[$i]['winners'] = $this->uuidsInBallotOrder($ballot['winners'], $order);

                if ($ballot['tie'] !== null) {
                    $computed[$i]['tie']['candidates'] = $this->uuidsInBallotOrder($ballot['tie']['candidates'], $order);
                }
            }
        }

        [$eligible, $voted] = $this->turnout($election);
        $institution = Institution::query()->whereKey($election->institution_id)->first();

        return new ResultsReport(
            institution: $institution->name ?? '',
            title: $election->title,
            language: $election->language === 'en' ? 'en' : 'fr',
            timezone: $election->timezone,
            startsAt: $election->starts_at,
            endsAt: $election->ends_at,
            eligible: $eligible,
            voted: $voted,
            percentage: ComputeTurnout::percentage($voted, $eligible),
            full: $full,
            showCheck: $full,
            ballots: $computed,
        );
    }

    /**
     * The rank of each candidate of the election on its ballot: candidate uuid => rank (position, then id). Off the
     * tenant scope like the counting; limited to this election. The rank is only compared, never output.
     *
     * @return array<string, int>
     */
    private function ballotOrder(Election $election): array
    {
        $rank = [];

        // Nobody is signed in on the public pages, the scope would match nothing: limited to this election.
        $uuids = Candidate::withoutInstitutionScope()
            ->where('election_id', $election->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('uuid');

        foreach ($uuids as $i => $uuid) {
            if (is_string($uuid)) {
                $rank[$uuid] = $i;
            }
        }

        return $rank;
    }

    /**
     * Candidate uuids sorted by rank; unknown ranks keep their order (the sort is stable).
     *
     * @param  list<string>  $uuids
     * @param  array<string, int>  $order
     * @return list<string>
     */
    private function uuidsInBallotOrder(array $uuids, array $order): array
    {
        usort($uuids, static fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $uuids;
    }

    /**
     * Candidate rows (with the uuid as `id`) sorted by rank.
     *
     * @template T of array{id: string}
     *
     * @param  list<T>  $rows
     * @param  array<string, int>  $order
     * @return list<T>
     */
    private function rowsInBallotOrder(array $rows, array $order): array
    {
        usort($rows, static fn (array $a, array $b): int => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * The voters of the election and those who voted. The numbers only: no voter is read.
     *
     * @return array{0: int, 1: int}
     */
    private function turnout(Election $election): array
    {
        return [
            DB::table('voters')->where('election_id', $election->id)->count(),
            DB::table('participations')->where('election_id', $election->id)->count(),
        ];
    }
}
