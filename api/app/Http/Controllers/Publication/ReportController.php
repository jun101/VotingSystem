<?php

namespace App\Http\Controllers\Publication;

use App\Actions\Elections\ComposePublishedResults;
use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Support\Reports\ResultsReportWriter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    /**
     * The results report.
     *
     * The official results of a closed, published or archived election as a US Letter PDF, always with the full
     * counts and the bulletin check, in the election's language (409 `results_not_available` for any earlier
     * status). Generated on request, never stored. Owner or manager. Limited to 20 requests per hour per user.
     */
    #[ResponseDoc(status: 200, description: 'The PDF report, as an attachment.')]
    public function __invoke(Election $election, ComposePublishedResults $composer, ResultsReportWriter $writer): Response
    {
        if (! Gate::forUser(request()->user())->allows('view', $election)) {
            throw new ApiException(403, 'forbidden');
        }

        if (! in_array($election->status, [ElectionStatus::Closed, ElectionStatus::Published, ElectionStatus::Archived], true)) {
            throw new ApiException(409, 'results_not_available');
        }

        return self::pdf($election, $writer->write($composer->report($election, false, true)));
    }

    /** The PDF as an attachment named after the election (`rapport-{slug}.pdf`, `report-{slug}.pdf`). */
    public static function pdf(Election $election, string $body): Response
    {
        $english = $election->language === 'en';
        $slug = Str::slug(mb_substr($election->title, 0, 100));

        if ($slug === '') {
            $slug = $english ? 'results' : 'resultats';
        }

        return response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.($english ? 'report-' : 'rapport-').$slug.'.pdf"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
