<?php

namespace App\Http\Controllers\Credentials;

use App\Actions\Credentials\CredentialState;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Voter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CredentialStateController extends Controller
{
    /**
     * The state of the access codes of an election.
     *
     * Totals and one line per group (sorted by name, accents and case ignored; the voters with no
     * group last, with a null `id` and `name`): voters, voters with a code, with an email, and
     * the files ready. `run_in_progress` is true while a file is queued. Any status of the
     * election; once it is closed no file counts as ready. Owner or manager.
     *
     * @response array{data: array{voters: int, with_code: int, without_code: int, with_email: int, run_in_progress: bool, groups: list<array{group: array{id: string, name: string}|null, voters: int, with_code: int, with_email: int, files_ready: int}>}}
     */
    public function __invoke(Request $request, Election $election, CredentialState $state): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('viewAny', [Voter::class, $election])) {
            throw new ApiException(403, 'forbidden');
        }

        return response()->json(['data' => $state($election)]);
    }
}
