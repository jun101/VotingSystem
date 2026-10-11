<?php

namespace App\Http\Controllers\Publication;

use App\Actions\Elections\ComposePublishedResults;
use App\Http\Controllers\Controller;
use App\Support\Publication\PublishedElections;
use App\Support\Reports\ResultsReportWriter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Response;

class PublicReportController extends Controller
{
    /**
     * The results report of a published election.
     *
     * A PDF for anyone, following the election's `results_display`: a `winners` election's report has no counts
     * and no bulletin check line. Any status but `published`, an unknown election, a value that is not a UUID and
     * a suspended institution answer the same 404. Generated on request. Limited to 30 requests per minute per
     * address and election.
     */
    #[ResponseDoc(status: 200, description: 'The PDF report, as an attachment.')]
    public function __invoke(string $election, PublishedElections $elections, ComposePublishedResults $composer, ResultsReportWriter $writer): Response
    {
        $found = $elections->find($election);
        $full = $found->results_display->value === 'full';

        return ReportController::pdf($found, $writer->write($composer->report($found, true, $full)));
    }
}
