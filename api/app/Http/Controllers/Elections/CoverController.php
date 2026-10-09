<?php

namespace App\Http\Controllers\Elections;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ReadsUploadedFile;
use App\Http\Controllers\Controller;
use App\Http\Resources\ElectionResource;
use App\Models\Election;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class CoverController extends Controller
{
    use ReadsUploadedFile;

    /**
     * Replace the cover of an election.
     *
     * Send one `file` part (`multipart/form-data`) under the rules of the institution's logo: JPEG,
     * PNG or WebP recognised by content, 5 MB, 8 000 pixels on a side, 40 million pixels, not animated.
     * Two WebP files are written, 480 and 960 pixels wide, ratio kept, never enlarged, metadata
     * dropped, the upload never kept; the previous cover's files are deleted afterwards. A draft only.
     * Owner or manager. Limited to 20 requests per hour per user.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function update(Request $request, Election $election, ImageReEncoder $images): ElectionResource
    {
        $this->allow($request, $election);
        // The record is found (404), then the state is judged (409), then the file.
        $election->assertEditable();

        $file = $this->uploadedFile($request);

        // Nothing is logged about the upload: not its name, not its size, not its bytes.
        $uuid = $images->store($file->getRealPath(), ImageReEncoder::COVER_SIZES, byWidth: true);

        $previous = null;

        try {
            DB::transaction(function () use (&$election, &$previous, $uuid): void {
                // Two uploads at once: the second waits, and reads the first one's cover as the
                // previous one, so no file is left behind.
                $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();
                $election = $locked ?? $election;
                $election->assertEditable();
                $previous = $election->cover_file;
                $election->cover_file = $uuid;
                $election->save();
            });
        } catch (Throwable $e) {
            // The new files are of no use: the previous cover stays.
            $images->delete($uuid, ImageReEncoder::COVER_SIZES);

            throw $e;
        }

        $images->delete($previous, ImageReEncoder::COVER_SIZES);

        Log::info('election.cover', ['outcome' => 'replaced']);

        return new ElectionResource($election);
    }

    /**
     * Remove the cover of an election.
     *
     * Answers 204 whether or not there was a cover. A draft only. Owner or manager.
     */
    public function destroy(Request $request, Election $election, ImageReEncoder $images): Response
    {
        $this->allow($request, $election);
        $election->assertEditable();

        $previous = $election->cover_file;

        if ($previous !== null) {
            $election->cover_file = null;
            $election->save();
            $images->delete($previous, ImageReEncoder::COVER_SIZES);
        }

        Log::info('election.cover', ['outcome' => 'removed']);

        return response()->noContent();
    }

    private function allow(Request $request, Election $election): void
    {
        if (! Gate::forUser($request->user())->allows('changeCover', $election)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}
