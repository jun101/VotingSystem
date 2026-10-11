<?php

namespace App\Http\Controllers\Credentials;

use App\Actions\Credentials\ReissueCredential;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credentials\ReissueCredentialRequest;
use App\Http\Resources\GeneratedFileResource;
use App\Models\Voter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VoterCredentialController extends Controller
{
    /**
     * Give one voter a code.
     *
     * The first code, or a new one that replaces a lost slip (the old one no longer matches any
     * voter, `reissue_count` goes up). The code is in the answer once, with a ready one-slip file
     * and the voter; the answer is never cached. A draft, scheduled or open election (409
     * `election_codes_locked`); a file that cannot be written gives a 500 and keeps the old code.
     * With `email` true the new code is also emailed to the voter in the background (`email`
     * `queued` in the answer; 422 `email: no_address` for a voter with no address, and nothing
     * changes). Owner or manager. Limited to 60 requests per hour per user.
     */
    #[Response(status: 201, type: 'array{data: array{voter: array{id: string, full_name: string, group: array{id: string, name: string}|null}, code: string, reissue_count: int, email: \'queued\'|null, file: array{id: string, kind: string, group: array{id: string, name: string}|null, part: int, parts: int, voter_count: int, status: \'queued\'|\'ready\'|\'failed\', failure: string|null, size_bytes: int|null, expires_at: string, created_at: string}}}')]
    public function __invoke(ReissueCredentialRequest $request, Voter $voter, ReissueCredential $reissue): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('issueCredentials', $voter)) {
            throw new ApiException(403, 'forbidden');
        }

        // The voter is found (404), then the election's state is judged (409) under the lock.
        $result = $reissue($voter, $request->user(), $request->wantsEmail());

        $group = $voter->group;

        // No numeric id, and no log line: this answer is the one place a code is shown.
        return response()->json([
            'data' => [
                'voter' => [
                    'id' => $voter->uuid,
                    'full_name' => $voter->full_name,
                    'group' => $group === null ? null : ['id' => $group->uuid, 'name' => $group->name],
                ],
                'code' => $result['code'],
                'reissue_count' => $result['reissue_count'],
                'email' => $result['email'],
                'file' => (new GeneratedFileResource($result['file']))->resolve(),
            ],
        ], 201, ['Cache-Control' => 'no-store']);
    }
}
