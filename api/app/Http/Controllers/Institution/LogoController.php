<?php

namespace App\Http\Controllers\Institution;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionProfileResource;
use App\Models\User;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LogoController extends Controller
{
    /**
     * Replace the institution's logo.
     *
     * Send one `file` part (`multipart/form-data`): a JPEG, PNG or WebP of at most 5 MB, 8 000
     * pixels on a side and 40 million pixels. It is recognised by its content, re-encoded into
     * three WebP sizes (64, 160, 480 pixels), stripped of every piece of metadata, and the
     * upload itself is never kept. The previous logo's files are deleted once the new one is
     * stored. Owner only. Limited to 10 requests per hour per user.
     *
     * @response array{data: array{id: string, name: string, type: 'school'|'university'|'association'|'other', description: string|null, address: string|null, city: string|null, phone: string|null, contact_email: string|null, timezone: string, language: 'fr'|'en', logo: array{sm: string, md: string, lg: string}|null}}
     */
    public function update(Request $request, ImageReEncoder $images): InstitutionProfileResource
    {
        $user = $request->user();

        if (! $user instanceof User || $user->institution === null) {
            throw new ApiException(403, 'forbidden');
        }

        $file = $this->uploadedFile($request);

        // Nothing is logged about the upload: not its name, not its size, not its bytes.
        $uuid = $images->store($file->getRealPath());

        $institution = $user->institution;
        $previous = $institution->logo_file;

        try {
            DB::transaction(function () use ($institution, $uuid): void {
                $institution->logo_file = $uuid;
                $institution->save();
            });
        } catch (\Throwable $e) {
            // The new files are of no use: the previous logo stays.
            $images->delete($uuid);

            throw $e;
        }

        // The old files go once the database points at the new ones.
        $images->delete($previous);

        Log::info('institution.logo', ['outcome' => 'replaced']);

        return new InstitutionProfileResource($institution);
    }

    /**
     * Remove the institution's logo.
     *
     * The institution is shown with its initials again. Answers 204 whether or not there was a
     * logo. Owner only.
     */
    public function destroy(Request $request, ImageReEncoder $images): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->institution === null) {
            throw new ApiException(403, 'forbidden');
        }

        $institution = $user->institution;
        $previous = $institution->logo_file;

        if ($previous !== null) {
            $institution->logo_file = null;
            $institution->save();
            $images->delete($previous);
        }

        Log::info('institution.logo', ['outcome' => 'removed']);

        return response()->noContent();
    }

    /** The `file` part, or the error that says why there is none. */
    private function uploadedFile(Request $request): UploadedFile
    {
        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            if ($file->isValid()) {
                return $file;
            }

            // PHP itself refused the file for its size (upload_max_filesize).
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new ApiException(413, 'file_too_large');
            }

            throw ValidationException::withMessages(['file' => [$file->getError() === UPLOAD_ERR_NO_FILE ? 'required' : 'invalid']]);
        }

        // Above post_max_size PHP drops the whole body: there is no part, but the request said
        // it was large.
        if ($request->server('CONTENT_LENGTH') !== null && (int) $request->server('CONTENT_LENGTH') > $this->postMaxBytes()) {
            throw new ApiException(413, 'file_too_large');
        }

        throw ValidationException::withMessages(['file' => [$request->has('file') ? 'invalid' : 'required']]);
    }

    private function postMaxBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
