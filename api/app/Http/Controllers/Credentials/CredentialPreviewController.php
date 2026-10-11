<?php

namespace App\Http\Controllers\Credentials;

use App\Actions\Credentials\PreviewCredentialRun;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credentials\PreviewCredentialRunRequest;
use App\Models\Election;
use App\Models\Voter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CredentialPreviewController extends Controller
{
    /**
     * Preview a run of access codes.
     *
     * What a run would do for a scope (`all`, one `group`, `ungrouped` or `failed_email`), without
     * doing it: voters who would get a code, those with an email address, those with none (left
     * for print, the first 100 named by name) and those who already have a code. The numbers the
     * person confirms before emails are sent; the run checks them again. A draft, scheduled or
     * open election (409 `election_codes_locked`). Owner or manager. Limited to 120 requests per
     * hour per user.
     *
     * @response array{data: array{to_generate: int, with_email: int, without_email: int, skipped: int, voters_without_email: list<array{id: string, full_name: string, group: array{id: string, name: string}|null}>}}
     */
    public function __invoke(PreviewCredentialRunRequest $request, Election $election, PreviewCredentialRun $preview): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('issueCredentials', [Voter::class, $election])) {
            throw new ApiException(403, 'forbidden');
        }

        $election->assertCodesOpen();

        return response()->json(['data' => $preview($election, $request->scope(), $request->group())]);
    }
}
