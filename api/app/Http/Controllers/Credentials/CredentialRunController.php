<?php

namespace App\Http\Controllers\Credentials;

use App\Actions\Credentials\GenerateSlipFile;
use App\Actions\Credentials\StartCredentialRun;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credentials\StartCredentialRunRequest;
use App\Http\Resources\GeneratedFileResource;
use App\Jobs\GenerateSlipFileJob;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Voter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class CredentialRunController extends Controller
{
    /**
     * Start a run of access codes.
     *
     * Gives a code to the voters of the scope (`all`, one `group`, `ungrouped`, or `failed_email`
     * for a new code to the voters whose email failed) who have none and writes their slips, one
     * PDF per group (parts of 1 000 voters at most). With `email` the voters served who have an
     * address are emailed their link and code in the background; `confirm_emails` is the count the
     * person saw (409 `email_count_changed` with `error.count`, 409 `nothing_to_email`); with `email` the user's
     * own email must be verified (403 `email_not_verified`) and the institution's emails of the last 24
     * hours are limited (409 `email_quota_exceeded`, `error.count` is the number left). `file` is
     * `xlsx`, `csv` or null. The answer comes before the work: the files are `queued`, and polled
     * with `GET /generated-files/{file}`. A draft, scheduled or open election (409
     * `election_codes_locked`), one run at a time (409 `run_in_progress`), something to serve (409
     * `nothing_to_generate`). Owner or manager. Limited to 20 requests per hour per user.
     */
    #[Response(status: 202, type: 'array{data: array{files: list<array{id: string, kind: string, group: array{id: string, name: string}|null, part: int, parts: int, voter_count: int, status: \'queued\'|\'ready\'|\'failed\', failure: string|null, size_bytes: int|null, expires_at: string, created_at: string}>, voters: int, skipped: int, emails: int}}')]
    public function __invoke(StartCredentialRunRequest $request, Election $election, StartCredentialRun $start): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('issueCredentials', [Voter::class, $election])) {
            throw new ApiException(403, 'forbidden');
        }

        $run = $start($election, $request->scope(), $request->group(), $request->user(), $request->wantsEmail(), $request->confirmEmails(), $request->fileFormat());

        $uuids = array_map(fn (GeneratedFile $file): string => $file->uuid, $run['files']);

        try {
            // After the commit of the files: one job per file, each holds its file's UUID only and
            // queues the job of the next file when it is done.
            GenerateSlipFileJob::dispatch($uuids[0])->afterCommit();
        } catch (Throwable $e) {
            // The queue cannot be reached: the files must not stay queued for ever.
            $generate = app(GenerateSlipFile::class);

            foreach ($uuids as $uuid) {
                $generate->fail($uuid);
            }

            throw $e;
        }

        // Counts only: never a name, a code or a group.
        Log::info('credentials.run', ['outcome' => 'queued', 'files' => count($uuids), 'voters' => $run['voters'], 'skipped' => $run['skipped'], 'emails' => $run['emails']]);

        return response()->json([
            'data' => [
                'files' => array_map(fn (GeneratedFile $file): array => (new GeneratedFileResource($file))->resolve(), $run['files']),
                'voters' => $run['voters'],
                'skipped' => $run['skipped'],
                'emails' => $run['emails'],
            ],
        ], 202);
    }
}
