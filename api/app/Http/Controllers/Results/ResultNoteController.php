<?php

namespace App\Http\Controllers\Results;

use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Results\ResultNoteRequest;
use App\Models\Ballot;
use App\Models\Election;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ResultNoteController extends Controller
{
    /**
     * Set or clear the note of a result.
     *
     * The public note shown with a ballot's result: trimmed, up to 1 000 characters, control
     * characters other than line breaks removed; `null` or empty clears it. Only for a closed,
     * published or archived election (409 `results_not_available`). Owner or manager. Limited to
     * 60 requests per hour per user.
     *
     * @response array{data: array{id: string, note: string|null}}
     */
    public function __invoke(ResultNoteRequest $request, Ballot $ballot): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('update', $ballot)) {
            throw new ApiException(403, 'forbidden');
        }

        $note = $request->note();

        $saved = DB::transaction(function () use ($ballot, $note): Ballot {
            // The election's status is read under its lock: a change that landed meanwhile counts.
            $election = Election::query()->whereKey($ballot->election_id)->lockForUpdate()->first();

            if ($election === null) {
                throw new ApiException(404, 'not_found');
            }

            if (! in_array($election->status, [ElectionStatus::Closed, ElectionStatus::Published, ElectionStatus::Archived], true)) {
                throw new ApiException(409, 'results_not_available');
            }

            $ballot->result_note = $note;
            $ballot->save();

            return $ballot;
        });

        Log::info('ballot.result_note', ['outcome' => $note === null ? 'cleared' : 'set']);

        return response()->json(['data' => ['id' => $saved->uuid, 'note' => $saved->result_note]]);
    }
}
