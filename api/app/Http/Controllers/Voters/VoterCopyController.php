<?php

namespace App\Http\Controllers\Voters;

use App\Actions\Voters\CopyVoters;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voters\CopyVotersRequest;
use App\Models\Election;
use App\Models\Voter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class VoterCopyController extends Controller
{
    /**
     * Load the voters of another election.
     *
     * Copies the voters of `source`, another election of the institution in any status, into this
     * draft or scheduled election, with their groups (found by name or created; `rename` gives a
     * group another name). `groups` limits the copy to some groups of the source and
     * `include_ungrouped` says whether the voters with no group come too. A voter whose identifier
     * or email the election already has is skipped and counted. Credentials, codes and dates are
     * never copied. One transaction; a 409 leaves the election as it was. Owner or manager.
     * Limited to 20 requests per hour per user.
     */
    #[Response(status: 201, type: 'array{data: array{copied: int, skipped: int, groups_created: int}}')]
    public function __invoke(CopyVotersRequest $request, Election $election, CopyVoters $copy): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('create', [Voter::class, $election])) {
            throw new ApiException(403, 'forbidden');
        }

        $result = $copy(
            $election,
            $request->sourceElection(),
            $request->groupUuids(),
            $request->includeUngrouped(),
            $request->renames(),
        );

        // Counts only: never a name, an identifier or an email.
        Log::info('voter.copy', ['outcome' => 'copied', 'copied' => $result['copied'], 'skipped' => $result['skipped']]);

        return response()->json(['data' => $result], 201);
    }
}
