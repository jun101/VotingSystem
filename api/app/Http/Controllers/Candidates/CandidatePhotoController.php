<?php

namespace App\Http\Controllers\Candidates;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ReadsUploadedFile;
use App\Http\Controllers\Controller;
use App\Http\Resources\CandidateResource;
use App\Models\Candidate;
use App\Models\Election;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class CandidatePhotoController extends Controller
{
    use ReadsUploadedFile;

    /**
     * Replace the photo of a candidate.
     *
     * Send one `file` part (`multipart/form-data`) under the rules of the election cover: JPEG,
     * PNG or WebP recognised by content, 5 MB, 8 000 pixels on a side, 40 million pixels, not
     * animated. Two WebP files are written, 160 and 480 pixels wide, ratio kept, never enlarged,
     * metadata dropped, the upload never kept; the previous photo's files are deleted afterwards.
     * A draft election only. Owner or manager. Limited to 20 requests per hour per user.
     *
     * @response array{data: array{id: string, ballot: string, party: string|null, first_name: string, last_name: string, sex: 'male'|'female', slogan: string|null, biography: string|null, photo: array{sm: string, md: string}|null, position: int, created_at: string, updated_at: string}}
     */
    public function update(Request $request, Candidate $candidate, ImageReEncoder $images): CandidateResource
    {
        $this->allow($request, $candidate);
        // The record is found (404), then the state is judged (409), then the file.
        $this->electionOf($candidate)->assertEditable();

        $file = $this->uploadedFile($request);

        // Nothing is logged about the upload: not its name, not its size, not its bytes.
        $uuid = $images->store($file->getRealPath(), ImageReEncoder::CANDIDATE_PHOTO_SIZES, byWidth: true);

        $previous = null;
        $saved = $candidate;

        try {
            DB::transaction(function () use ($candidate, $uuid, &$previous, &$saved): void {
                $saved = $this->lockCandidate($candidate);
                // Two uploads at once: the second waits, and reads the first one's photo as the
                // previous one, so no file is left behind.
                $previous = $saved->photo_file;
                $saved->photo_file = $uuid;
                $saved->save();
            });
        } catch (Throwable $e) {
            // The new files are of no use: the previous photo stays.
            $images->delete($uuid, ImageReEncoder::CANDIDATE_PHOTO_SIZES);

            throw $e;
        }

        $images->delete($previous, ImageReEncoder::CANDIDATE_PHOTO_SIZES);

        Log::info('candidate.photo', ['outcome' => 'replaced']);

        return new CandidateResource($saved->load('ballot', 'party'));
    }

    /**
     * Remove the photo of a candidate.
     *
     * Answers 204 whether or not there was a photo. A draft election only. Owner or manager.
     * Limited to 120 requests per hour per user.
     */
    public function destroy(Request $request, Candidate $candidate, ImageReEncoder $images): Response
    {
        $this->allow($request, $candidate);
        $this->electionOf($candidate)->assertEditable();

        $previous = null;

        DB::transaction(function () use ($candidate, &$previous): void {
            $locked = $this->lockCandidate($candidate);
            $previous = $locked->photo_file;

            if ($previous !== null) {
                $locked->photo_file = null;
                $locked->save();
            }
        });

        // The files go after the commit.
        $images->delete($previous, ImageReEncoder::CANDIDATE_PHOTO_SIZES);

        Log::info('candidate.photo', ['outcome' => 'removed']);

        return response()->noContent();
    }

    /**
     * The election, the ballot and the candidate rows under their locks (in that order, as every
     * other write), the election's state judged again under its lock.
     */
    private function lockCandidate(Candidate $candidate): Candidate
    {
        $election = Election::query()->whereKey($this->electionOf($candidate)->getKey())->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertEditable();

        // The ballot row is locked too, before the candidate.
        $candidate->ballot()->getQuery()->lockForUpdate()->first();

        $locked = Candidate::query()->whereKey($candidate->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        return $locked;
    }

    private function electionOf(Candidate $candidate): Election
    {
        return $candidate->election ?? throw new ApiException(404, 'not_found');
    }

    private function allow(Request $request, Candidate $candidate): void
    {
        if (! Gate::forUser($request->user())->allows('update', $candidate)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}
