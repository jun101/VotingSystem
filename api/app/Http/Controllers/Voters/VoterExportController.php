<?php

namespace App\Http\Controllers\Voters;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voters\ExportVotersRequest;
use App\Models\Election;
use App\Models\Voter;
use App\Support\Exports\VotersFileWriter;
use App\Support\Voters\VoterFilters;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoterExportController extends Controller
{
    /** An election holds at most this many voters, so a file is never longer. */
    private const LIMIT = Voter::LIMIT;

    /**
     * Export the voters.
     *
     * An Excel or CSV file of the voters of an election, written as a stream: full name, group,
     * identifier, email, phone, and `has voted` for `voted=all`. `voted` is `no` (default), `yes`
     * or `all`; `group` is a group's UUID or `none`; `q` searches as in the list. Sorted by group
     * name then full name, the voters with no group last. Never a code, a hash, a link or a time of
     * vote; every text cell is guarded against formulas. Owner or manager. Limited to 20 requests
     * per hour per user.
     */
    public function __invoke(ExportVotersRequest $request, Election $election, VotersFileWriter $writer): StreamedResponse
    {
        if (! Gate::forUser($request->user())->allows('viewAny', [Voter::class, $election])) {
            throw new ApiException(403, 'forbidden');
        }

        $voted = $request->voted();
        $csv = $request->fileFormat() === 'csv';
        $language = $election->language === 'en' ? 'en' : 'fr';

        $query = Voter::query()
            ->where('voters.election_id', $election->getKey())
            ->leftJoin('voter_groups', 'voter_groups.id', '=', 'voters.voter_group_id')
            ->select(['voters.full_name', 'voters.identifier', 'voters.email', 'voters.phone', 'voter_groups.name as group_name'])
            ->selectRaw('EXISTS (SELECT 1 FROM participations WHERE participations.voter_id = voters.id) as has_voted')
            // The voters with no group last, then by group name and by full name, accents and case ignored.
            ->orderByRaw('voter_groups.name IS NULL')
            ->orderByRaw('voter_groups.name COLLATE utf8mb4_unicode_ci')
            ->orderByRaw('voters.full_name COLLATE utf8mb4_unicode_ci')
            ->orderBy('voters.id')
            ->limit(self::LIMIT);

        VoterFilters::apply(
            $query,
            $election->id,
            $request->search(),
            $request->group(),
            $voted === 'all' ? null : $voted,
        );

        $slug = Str::slug(mb_substr($election->title, 0, 100));
        $name = 'electeurs-'.($slug === '' ? 'election' : $slug).'.'.($csv ? 'csv' : 'xlsx');
        $withVoted = $voted === 'all';

        $body = function () use ($query, $writer, $csv, $language, $withVoted): void {
            // One row at a time: nothing is kept.
            $rows = $query->toBase()->cursor();

            if ($csv) {
                $handle = fopen('php://output', 'w');

                if ($handle !== false) {
                    $writer->csv($rows, $language, $withVoted, $handle);
                    fclose($handle);
                }

                return;
            }

            $writer->xlsx($rows, $language, $withVoted);
        };

        return response()->stream($body, 200, [
            'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
