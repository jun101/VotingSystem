<?php

namespace App\Actions\Elections;

use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * A new draft with the settings of another election (docs/api/elections/POST-elections-{election}-duplicate.md).
 * Copied: description, dates, time zone, language, candidate order, results display. Never the
 * cover (its files are not shared), the status and its dates, or the link to a first round.
 * Every ballot and every party (without its logo) is copied with it, in the same transaction, and
 * on request every candidate (without its photo) into the matching new ballot.
 */
final class DuplicateElection
{
    private const TITLE_LIMIT = 200;

    public function __invoke(Election $source, ?string $title, bool $copyCandidates = false): Election
    {
        $prefix = $source->language === 'en' ? 'Copy of ' : 'Copie de ';

        $copy = new Election([
            'title' => $title ?? mb_substr($prefix.$source->title, 0, self::TITLE_LIMIT),
            'description' => $source->description,
            'starts_at' => $source->starts_at,
            'ends_at' => $source->ends_at,
            'timezone' => $source->timezone,
            'language' => $source->language,
            'candidate_order' => $source->candidate_order,
            'results_display' => $source->results_display,
        ]);

        return DB::transaction(function () use ($source, $copy, $copyCandidates): Election {
            $copy->save();

            /** @var array<int, int> $ballotIds the source ballot's key to its copy's */
            $ballotIds = [];
            /** @var array<int, int> $partyIds */
            $partyIds = [];

            foreach ($source->ballots()->get() as $ballot) {
                $new = new Ballot([
                    'title' => $ballot->title,
                    'description' => $ballot->description,
                    'seats' => $ballot->seats,
                    'allow_blank' => $ballot->allow_blank,
                ]);
                $new->election_id = $copy->id;
                $new->position = $ballot->position;
                $new->save();
                $ballotIds[$ballot->id] = $new->id;
            }

            foreach ($source->parties()->get() as $party) {
                $new = new Party([
                    'name' => $party->name,
                    'acronym' => $party->acronym,
                    'colour' => $party->colour,
                ]);
                $new->election_id = $copy->id;
                $new->save();
                $partyIds[$party->id] = $new->id;
            }

            if ($copyCandidates) {
                $this->copyCandidates($source, $copy, $ballotIds, $partyIds);
            }

            return $copy;
        });
    }

    /**
     * @param  array<int, int>  $ballotIds
     * @param  array<int, int>  $partyIds
     */
    private function copyCandidates(Election $source, Election $copy, array $ballotIds, array $partyIds): void
    {
        $candidates = Candidate::query()
            ->where('election_id', $source->id)
            ->orderBy('ballot_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            $new = new Candidate([
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'sex' => $candidate->sex,
                'slogan' => $candidate->slogan,
                'biography' => $candidate->biography,
            ]);
            $new->election_id = $copy->id;
            $new->ballot_id = $ballotIds[$candidate->ballot_id];
            $new->party_id = $candidate->party_id === null ? null : ($partyIds[$candidate->party_id] ?? null);
            $new->position = $candidate->position;
            $new->save();
        }
    }
}
