<?php

namespace App\Http\Controllers\VoterImports;

use App\Enums\VoterImportStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VoterImports\ListImportRowsRequest;
use App\Http\Requests\VoterImports\TemplateRequest;
use App\Http\Requests\VoterImports\UpdateImportMappingRequest;
use App\Http\Resources\VoterImportResource;
use App\Jobs\CheckVoterImport;
use App\Models\Election;
use App\Models\User;
use App\Models\VoterImport;
use App\Models\VoterImportRow;
use App\Support\Imports\ImportFileInspector;
use App\Support\Imports\VoterFileTemplate;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class VoterImportController extends Controller
{
    /**
     * Download the template.
     *
     * An `.xlsx` (default, with an `Instructions` sheet in the signed-in user's language) or a
     * `.csv` (UTF-8 with a byte-order mark). The header `nom_complet`, `groupe`, `identifiant`,
     * `courriel`, `telephone` and three example rows; never a formula. An election in any
     * status. Owner or manager. Limited to 240 requests per hour per user.
     */
    public function template(TemplateRequest $request, Election $election, VoterFileTemplate $template): HttpResponse
    {
        $this->allow('create', [VoterImport::class, $election]);

        $user = $request->user();
        $csv = $request->templateFormat() === 'csv';
        $language = $user instanceof User && $user->language === 'en' ? 'en' : 'fr';

        return response($csv ? $template->csv() : $template->xlsx($language), 200, [
            'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modele-electeurs.'.($csv ? 'csv' : 'xlsx').'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Upload a voter file.
     *
     * `multipart/form-data` with `file`: an `.xlsx` or a `.csv`, judged by its content, 5 MB at
     * most. A draft or scheduled election only. Nothing is imported: the file is checked in the
     * background (status `uploaded`, then `checked` or `failed`). One import at a time; a new
     * upload deletes the earlier `checked` and `failed` ones. Owner or manager. Limited to 20
     * uploads per hour per user.
     */
    #[Response(status: 202, type: 'array{data: array{id: string, election: string, status: "uploaded"|"checked"|"importing"|"done"|"failed", original_name: string, columns: list<string>, mapping: array{full_name: int|null, group: int|null, identifier: int|null, email: int|null, phone: int|null}|null, total_rows: int, valid_rows: int, invalid_rows: int, duplicate_rows: int, new_groups: list<string>, processed_rows: int, imported_rows: int, failure: string|null, has_error_file: bool, created_at: string, finished_at: string|null}}')]
    public function store(Request $request, Election $election, ImportFileInspector $inspector): JsonResponse
    {
        $this->allow('create', [VoterImport::class, $election]);

        $election->assertVotersDeletable();

        $file = $this->uploadedFile($request);
        $inspector->kind($file);

        $user = $request->user();
        $stored = Str::uuid()->toString();
        $disk = Storage::disk('imports');
        $disk->putFileAs('', $file, $stored);

        /** @var list<string> $replaced */
        $replaced = [];

        try {
            $import = DB::transaction(function () use ($election, $user, $file, $stored, &$replaced): VoterImport {
                // The row is read again under its lock: two uploads at once never both pass.
                $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw new ApiException(404, 'not_found');
                }

                $locked->assertVotersDeletable();

                $running = VoterImport::query()
                    ->where('election_id', $locked->id)
                    ->whereIn('status', [VoterImportStatus::Uploaded->value, VoterImportStatus::Importing->value])
                    ->exists();

                if ($running) {
                    throw new ApiException(409, 'import_in_progress');
                }

                // One import at a time: the earlier checked and failed ones go with their rows and file.
                $earlier = VoterImport::query()
                    ->where('election_id', $locked->id)
                    ->whereIn('status', [VoterImportStatus::Checked->value, VoterImportStatus::Failed->value])
                    ->get();

                foreach ($earlier as $old) {
                    $replaced[] = $old->stored_file;
                    $old->delete();
                }

                $import = new VoterImport;
                $import->forceFill([
                    'election_id' => $locked->id,
                    'user_id' => $user instanceof User ? $user->id : null,
                    'original_name' => $this->displayName($file),
                    'stored_file' => $stored,
                    'status' => VoterImportStatus::Uploaded,
                ])->save();
                $import->setRelation('election', $locked);

                return $import;
            });
        } catch (Throwable $e) {
            $disk->delete($stored);

            throw $e;
        }

        $disk->delete($replaced);

        try {
            CheckVoterImport::dispatch($import->uuid);
        } catch (Throwable $e) {
            // The queue could not be reached: nothing will check this file, so it does not stay.
            $import->delete();
            $disk->delete($stored);

            throw $e;
        }

        Log::info('voter_import.upload', ['outcome' => 'queued']);

        return (new VoterImportResource($import))->response()
            ->setStatusCode(202)
            ->header('Location', '/api/v1/voter-imports/'.$import->uuid);
    }

    /**
     * Read an import.
     *
     * Its status, its summary and its progress. Any status. Owner or manager. Limited to 240
     * requests per hour per user.
     *
     * @response array{data: array{id: string, election: string, status: "uploaded"|"checked"|"importing"|"done"|"failed", original_name: string, columns: list<string>, mapping: array{full_name: int|null, group: int|null, identifier: int|null, email: int|null, phone: int|null}|null, total_rows: int, valid_rows: int, invalid_rows: int, duplicate_rows: int, new_groups: list<string>, processed_rows: int, imported_rows: int, failure: string|null, has_error_file: bool, created_at: string, finished_at: string|null}}
     */
    public function show(VoterImport $import): VoterImportResource
    {
        $this->allow('view', $import);

        return new VoterImportResource($import);
    }

    /**
     * Correct the column mapping.
     *
     * Body `mapping`: an object of voter fields to a column index or `null`; a key left out
     * keeps its value. A `checked` import of a draft or scheduled election; it is checked
     * again in the background (202, status `uploaded`). Owner or manager. Limited to 240
     * requests per hour per user.
     */
    #[Response(status: 202, type: 'array{data: array{id: string, election: string, status: "uploaded"|"checked"|"importing"|"done"|"failed", original_name: string, columns: list<string>, mapping: array{full_name: int|null, group: int|null, identifier: int|null, email: int|null, phone: int|null}|null, total_rows: int, valid_rows: int, invalid_rows: int, duplicate_rows: int, new_groups: list<string>, processed_rows: int, imported_rows: int, failure: string|null, has_error_file: bool, created_at: string, finished_at: string|null}}')]
    public function update(UpdateImportMappingRequest $request, VoterImport $import): JsonResponse
    {
        $this->allow('update', $import);

        $mapping = $request->merged();

        if ($mapping === null) {
            throw ValidationException::withMessages(['mapping' => ['invalid']]);
        }

        $this->electionOf($import)->assertVotersDeletable();

        if ($import->status !== VoterImportStatus::Checked) {
            throw new ApiException(409, 'import_not_checked');
        }

        $changed = DB::transaction(function () use ($import, $mapping): VoterImport {
            $election = Election::query()->whereKey($import->election_id)->lockForUpdate()->first();

            if ($election === null) {
                throw new ApiException(404, 'not_found');
            }

            $election->assertVotersDeletable();

            $locked = VoterImport::query()->whereKey($import->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if ($locked->status !== VoterImportStatus::Checked) {
                throw new ApiException(409, 'import_not_checked');
            }

            VoterImportRow::query()->where('voter_import_id', $locked->id)->delete();

            $locked->forceFill([
                'mapping' => $mapping,
                'status' => VoterImportStatus::Uploaded,
                'failure' => null,
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'duplicate_rows' => 0,
                'new_groups' => [],
                'processed_rows' => 0,
                'imported_rows' => 0,
                'finished_at' => null,
            ])->save();
            $locked->setRelation('election', $election);

            return $locked;
        });

        CheckVoterImport::dispatch($changed->uuid);

        Log::info('voter_import.map', ['outcome' => 'queued']);

        return (new VoterImportResource($changed))->response()->setStatusCode(202);
    }

    /**
     * List the rows of a checked import.
     *
     * 50 a page, in file order; `filter` is `all`, `ok` or `errors` (every invalid row,
     * duplicates included). An `uploaded` or `failed` import has no rows (409
     * `import_not_checked`). Owner or manager. Limited to 240 requests per hour per user.
     *
     * @response array{data: list<array{line: int, status: "ok"|"invalid"|"duplicate", reasons: list<string>, full_name: string|null, group: string|null, identifier: string|null, email: string|null, phone: string|null}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function rows(ListImportRowsRequest $request, VoterImport $import): JsonResponse
    {
        $this->allow('view', $import);

        if (in_array($import->status, [VoterImportStatus::Uploaded, VoterImportStatus::Failed], true)) {
            throw new ApiException(409, 'import_not_checked');
        }

        $query = VoterImportRow::query()->where('voter_import_id', $import->id)->orderBy('line');

        match ($request->filter()) {
            'ok' => $query->where('status', 'ok'),
            'errors' => $query->where('status', '!=', 'ok'),
            default => null,
        };

        $page = $query->paginate(ListImportRowsRequest::PER_PAGE, page: $request->page());

        return response()->json([
            'data' => $page->getCollection()->map(fn (VoterImportRow $row): array => [
                'line' => $row->line,
                'status' => $row->status->value,
                'reasons' => $row->reasons,
                'full_name' => $row->full_name,
                'group' => $row->group_name,
                'identifier' => $row->identifier,
                'email' => $row->email,
                'phone' => $row->phone,
            ])->values()->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Cancel an import.
     *
     * Its file and its rows are deleted; the voters already written stay. Any status except
     * `importing` (409 `import_in_progress`); an `uploaded` import is cancelled and the check
     * ends without writing. Owner or manager. Limited to 240 requests per hour per user.
     */
    public function destroy(VoterImport $import): HttpResponse
    {
        $this->allow('delete', $import);

        if ($import->status === VoterImportStatus::Importing) {
            throw new ApiException(409, 'import_in_progress');
        }

        $stored = DB::transaction(function () use ($import): string {
            $locked = VoterImport::query()->whereKey($import->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if ($locked->status === VoterImportStatus::Importing) {
                throw new ApiException(409, 'import_in_progress');
            }

            // The rows go with it (foreign key cascade).
            $locked->delete();

            return $locked->stored_file;
        });

        Storage::disk('imports')->delete($stored);

        Log::info('voter_import.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    private function electionOf(VoterImport $import): Election
    {
        return $import->election ?? throw new ApiException(404, 'not_found');
    }

    /**
     * The `file` part, or the 422 that says why there is none. A file above what PHP takes is
     * `too_large` too: the contract has one answer for "over 5 MB".
     */
    private function uploadedFile(Request $request): UploadedFile
    {
        $file = $request->file('file');

        if ($file instanceof UploadedFile) {
            if ($file->isValid()) {
                return $file;
            }

            $code = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
                UPLOAD_ERR_NO_FILE => 'required',
                default => 'invalid_type',
            };

            throw ValidationException::withMessages(['file' => [$code]]);
        }

        // Above post_max_size PHP drops the whole body: there is no part, but the request said it was large.
        $length = $request->server('CONTENT_LENGTH');

        if ($length !== null && (int) $length > $this->postMaxBytes()) {
            throw ValidationException::withMessages(['file' => ['too_large']]);
        }

        throw ValidationException::withMessages(['file' => [$request->has('file') ? 'invalid_type' : 'required']]);
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

    /** The name as shown to the user: no directory, no control character, 255 characters at most. */
    private function displayName(UploadedFile $file): string
    {
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 255));

        return $name === '' ? 'fichier' : $name;
    }

    /**
     * Owners and managers of the election's institution may; anyone else (a platform admin) is
     * refused. Another institution's record never gets here: the binding answered 404.
     *
     * @param  array{0: class-string<VoterImport>, 1: Election}|VoterImport  $subject
     */
    private function allow(string $ability, array|VoterImport $subject): void
    {
        if (! Gate::forUser(request()->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}
