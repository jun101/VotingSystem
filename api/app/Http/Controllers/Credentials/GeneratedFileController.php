<?php

namespace App\Http\Controllers\Credentials;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\GeneratedFileResource;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Voter;
use App\Support\Credentials\SlipFileName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GeneratedFileController extends Controller
{
    /**
     * List the files of an election.
     *
     * The slip PDFs, newest first, ties by group name then part (the voters with no group last).
     * Empty once the election is closed, published or archived: the files are deleted at the end.
     * Owner or manager.
     *
     * @response array{data: list<array{id: string, kind: string, group: array{id: string, name: string}|null, part: int, parts: int, voter_count: int, status: 'queued'|'ready'|'failed', failure: string|null, size_bytes: int|null, expires_at: string, created_at: string}>}
     */
    public function index(Request $request, Election $election): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('viewAny', [Voter::class, $election])) {
            throw new ApiException(403, 'forbidden');
        }

        if (! $election->isCodesOpen()) {
            return response()->json(['data' => []]);
        }

        $files = GeneratedFile::query()
            ->where('election_id', $election->id)
            ->with(['group', 'election'])
            ->get()
            ->sortBy(fn (GeneratedFile $file): array => [
                -($file->created_at?->getTimestamp() ?? 0),
                $file->group === null ? 1 : 0,
                Str::lower(Str::ascii($file->group->name ?? '')),
                $file->part,
                $file->id,
            ])
            ->values();

        return response()->json([
            'data' => $files->map(fn (GeneratedFile $file): array => (new GeneratedFileResource($file))->resolve())->all(),
        ]);
    }

    /**
     * One file's status.
     *
     * For polling after a run: `queued`, `ready` (with `size_bytes`) or `failed` (`failure`
     * `generation_failed`). Owner or manager.
     *
     * @response array{data: array{id: string, kind: string, group: array{id: string, name: string}|null, part: int, parts: int, voter_count: int, status: 'queued'|'ready'|'failed', failure: string|null, size_bytes: int|null, expires_at: string, created_at: string}}
     */
    public function show(Request $request, GeneratedFile $file): GeneratedFileResource
    {
        $this->allow($request, $file);

        $file->load(['group', 'election']);

        return new GeneratedFileResource($file);
    }

    /**
     * Download a file.
     *
     * The PDF of slips (`coupons-{group}.pdf`), or the Excel or CSV file of codes (`codes-{group}.xlsx`,
     * `codes-tous.csv`), decrypted on the way out, with a fixed name, never cached.
     * 409 `file_not_ready` for a queued or failed file; 404 once the election is closed,
     * published or archived. Owner or manager. Limited to 120 requests per hour per user.
     *
     * @response 200 string
     */
    public function download(Request $request, GeneratedFile $file): Response
    {
        $this->allow($request, $file);

        $election = $file->election;

        // The file is deleted at the end of the election: it is gone for the caller from then on.
        if ($election === null || ! $election->isCodesOpen()) {
            throw new ApiException(404, 'not_found');
        }

        if ($file->status !== GeneratedFile::STATUS_READY || $file->stored_file === null) {
            throw new ApiException(409, 'file_not_ready');
        }

        try {
            $encrypted = Storage::disk('credentials')->get($file->stored_file);
        } catch (Throwable) {
            // A file that cannot be read is a missing file for the caller.
            throw new ApiException(404, 'not_found');
        }

        if (! is_string($encrypted)) {
            throw new ApiException(404, 'not_found');
        }

        // Decrypted in memory: no clear copy is written to a disk.
        $pdf = Crypt::decryptString($encrypted);
        [$name, $type] = match ($file->kind) {
            GeneratedFile::KIND_CODES_XLSX => [
                SlipFileName::codes($file->group?->name, 'xlsx', $file->ungrouped),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            GeneratedFile::KIND_CODES_CSV => [SlipFileName::codes($file->group?->name, 'csv', $file->ungrouped), 'text/csv; charset=UTF-8'],
            default => [SlipFileName::for($file->group?->name, $file->part, $file->parts), 'application/pdf'],
        };

        // The user and file UUIDs and the count: never the content.
        Log::info('credentials.download', [
            'outcome' => 'sent',
            'user' => $request->user()?->uuid,
            'file' => $file->uuid,
            'voters' => $file->voter_count,
        ]);

        return response($pdf, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function allow(Request $request, GeneratedFile $file): void
    {
        if (! Gate::forUser($request->user())->allows('view', $file)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}
