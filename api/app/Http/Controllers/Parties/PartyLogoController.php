<?php

namespace App\Http\Controllers\Parties;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\ReadsUploadedFile;
use App\Http\Controllers\Controller;
use App\Http\Resources\PartyResource;
use App\Models\Election;
use App\Models\Party;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartyLogoController extends Controller
{
    use ReadsUploadedFile;

    /**
     * Replace the logo of a party.
     *
     * Send one `file` part (`multipart/form-data`) under the rules of the election cover: JPEG,
     * PNG or WebP recognised by content, 5 MB, 8 000 pixels on a side, 40 million pixels, not
     * animated. Two WebP files are written, 96 and 192 pixels wide, ratio kept, never enlarged,
     * metadata dropped, the upload never kept; the previous logo's files are deleted afterwards.
     * A draft election only. Owner or manager. Limited to 20 requests per hour per user.
     *
     * @response array{data: array{id: string, name: string, acronym: string|null, colour: string, logo: array{sm: string, md: string}|null, candidates_count: int, created_at: string, updated_at: string}}
     */
    public function update(Request $request, Party $party, ImageReEncoder $images): PartyResource
    {
        $this->allow($request, $party);
        // The record is found (404), then the state is judged (409), then the file.
        $this->electionOf($party)->assertEditable();

        $file = $this->uploadedFile($request);

        // Nothing is logged about the upload: not its name, not its size, not its bytes.
        $uuid = $images->store($file->getRealPath(), ImageReEncoder::PARTY_LOGO_SIZES, byWidth: true);

        $previous = null;
        $saved = $party;

        try {
            DB::transaction(function () use ($party, $uuid, &$previous, &$saved): void {
                $saved = $this->lockParty($party);
                // Two uploads at once: the second waits, and reads the first one's logo as the
                // previous one, so no file is left behind.
                $previous = $saved->logo_file;
                $saved->logo_file = $uuid;
                $saved->save();
            });
        } catch (Throwable $e) {
            // The new files are of no use: the previous logo stays.
            $images->delete($uuid, ImageReEncoder::PARTY_LOGO_SIZES);

            throw $e;
        }

        $images->delete($previous, ImageReEncoder::PARTY_LOGO_SIZES);

        Log::info('party.logo', ['outcome' => 'replaced']);

        return new PartyResource($saved);
    }

    /**
     * Remove the logo of a party.
     *
     * Answers 204 whether or not there was a logo. A draft election only. Owner or manager.
     * Limited to 120 requests per hour per user.
     */
    public function destroy(Request $request, Party $party, ImageReEncoder $images): Response
    {
        $this->allow($request, $party);
        $this->electionOf($party)->assertEditable();

        $previous = null;

        DB::transaction(function () use ($party, &$previous): void {
            $locked = $this->lockParty($party);
            $previous = $locked->logo_file;

            if ($previous !== null) {
                $locked->logo_file = null;
                $locked->save();
            }
        });

        // The files go after the commit.
        $images->delete($previous, ImageReEncoder::PARTY_LOGO_SIZES);

        Log::info('party.logo', ['outcome' => 'removed']);

        return response()->noContent();
    }

    /** The party row under its lock, after the election's state is judged again under the election's lock. */
    private function lockParty(Party $party): Party
    {
        $election = Election::query()->whereKey($this->electionOf($party)->getKey())->lockForUpdate()->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        $election->assertEditable();

        $locked = Party::query()->whereKey($party->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        return $locked;
    }

    private function electionOf(Party $party): Election
    {
        return $party->election ?? throw new ApiException(404, 'not_found');
    }

    private function allow(Request $request, Party $party): void
    {
        if (! Gate::forUser($request->user())->allows('update', $party)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}
