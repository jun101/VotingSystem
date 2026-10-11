<?php

namespace App\Http\Controllers\Audit;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ExportAuditRequest;
use App\Http\Requests\Audit\ListAuditRequest;
use App\Http\Resources\AuditEntryResource;
use App\Http\Resources\PageOf;
use App\Models\AuditEntry;
use App\Models\User;
use App\Support\Audit\AuditFilters;
use App\Support\Exports\AuditFileWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AuditController extends Controller
{
    /** The most rows one file holds. */
    private const EXPORT_LIMIT = 10000;

    /**
     * The audit log.
     *
     * The entries of the institution, newest first, 50 per page (1 to 100). Filters: `action` (a code, or a
     * family prefix ending with a dot), `election`, `user` (a uuid, or `system` for the scheduler), `from` and
     * `to` (`YYYY-MM-DD`, UTC, inclusive); one that names nothing gives an empty list. Entries of other
     * institutions and of the platform never appear. Owner only. The shared API limit.
     *
     * @response array{data: list<array{id: string, action: string, actor: array{id: string|null, name: string}, election: array{id: string, title: string}|null, subject: array{type: string, id: string}|null, details: array<string, mixed>, ip_address: string|null, created_at: string}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListAuditRequest $request): PageOf
    {
        // The tenant scope limits the query to the signed-in owner's institution (a platform entry has none).
        $query = AuditFilters::apply(AuditEntry::query()->with(['election', 'user']), $request)
            ->orderByDesc('audit_entries.created_at')
            ->orderByDesc('audit_entries.id');

        return PageOf::from($query->paginate($request->perPage(), page: $request->page()), AuditEntryResource::class);
    }

    /**
     * Export the audit log.
     *
     * An Excel or CSV file of the entries the same filters select, newest first, at most 10 000, written as a
     * stream: date (UTC), action code, person, election, subject, details as compact JSON, address. Every text
     * cell is guarded against formulas. Owner only. Limited to 20 requests per hour per user.
     *
     * @response 200 string
     */
    public function export(ExportAuditRequest $request, AuditFileWriter $writer): StreamedResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->institution === null) {
            throw new ApiException(403, 'forbidden');
        }

        $institution = $user->institution;
        $csv = $request->fileFormat() === 'csv';
        $language = $institution->language === 'en' ? 'en' : 'fr';

        $query = AuditFilters::apply(AuditEntry::query(), $request)
            ->leftJoin('elections', 'elections.id', '=', 'audit_entries.election_id')
            ->select([
                'audit_entries.created_at', 'audit_entries.action', 'audit_entries.actor_name', 'audit_entries.subject_type',
                'audit_entries.subject_uuid', 'audit_entries.details', 'audit_entries.ip_address', 'elections.title as election_title',
            ])
            ->orderByDesc('audit_entries.created_at')
            ->orderByDesc('audit_entries.id')
            ->limit(self::EXPORT_LIMIT);

        $slug = Str::slug(mb_substr($institution->name, 0, 100));
        $name = ($language === 'en' ? 'audit-log-' : 'journal-').($slug === '' ? 'institution' : $slug).'.'.($csv ? 'csv' : 'xlsx');

        $body = function () use ($query, $writer, $csv, $language): void {
            try {
                // One row at a time: nothing is kept.
                $rows = $query->toBase()->cursor();

                if ($csv) {
                    $handle = fopen('php://output', 'w');

                    if ($handle === false) {
                        throw new \RuntimeException('The output stream cannot be opened.');
                    }

                    try {
                        $writer->csv($rows, $language, $handle);
                    } finally {
                        fclose($handle);
                    }

                    return;
                }

                $writer->xlsx($rows, $language);
            } catch (Throwable $e) {
                // The headers are gone: a clean end would hand over a truncated file as if it were whole. Outcome
                // only (no row); the exception goes on so the client sees a failed download.
                Log::error('audit.export.failed', ['exception' => $e::class, 'format' => $csv ? 'csv' : 'xlsx']);

                throw $e;
            }
        };

        return response()->stream($body, 200, [
            'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
