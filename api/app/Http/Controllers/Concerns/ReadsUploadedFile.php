<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** The `file` part of an upload (a logo, a cover), or the error that says why there is none. */
trait ReadsUploadedFile
{
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
