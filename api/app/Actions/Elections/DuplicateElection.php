<?php

namespace App\Actions\Elections;

use App\Models\Ballot;
use App\Models\Election;
use App\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * A new draft with the settings of another election (docs/api/elections/POST-elections-{election}-duplicate.md).
 * Copied: description, dates, time zone, language, candidate order, results display. Never the
 * cover (its files are not shared), the status and its dates, or the link to a first round.
 * Every ballot and every party (without its logo) is copied with it, in the same transaction.
 */
final class DuplicateElection
{
    private const TITLE_LIMIT = 200;

    public function __invoke(Election $source, ?string $title): Election
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

        return DB::transaction(function () use ($source, $copy): Election {
            $copy->save();

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
            }

            foreach ($source->parties()->get() as $party) {
                $new = new Party([
                    'name' => $party->name,
                    'acronym' => $party->acronym,
                    'colour' => $party->colour,
                ]);
                $new->election_id = $copy->id;
                $new->save();
            }

            return $copy;
        });
    }
}
