<?php

namespace App\Support\Reports;

use RuntimeException;

/**
 * The results report as a PDF: US Letter portrait, core font (Helvetica) so the text stays extractable, each
 * text its own string in reading order. Generated on request and never stored. The document names no uuid, no
 * numeric key and no voter. Stateless: one `ReportDocument` per call, discarded after use (Octane).
 */
final class ResultsReportWriter
{
    public function write(ResultsReport $report): string
    {
        // Before TCPDF is first loaded: its configuration reads this constant once. The core fonts (metrics
        // only) are kept in the repository. A constant is fixed for the process and holds no request data.
        if (! defined('K_PATH_FONTS')) {
            $fonts = realpath(resource_path('pdf-fonts'));

            if ($fonts === false) {
                throw new RuntimeException('The PDF font folder is missing.');
            }

            define('K_PATH_FONTS', $fonts.'/');
        }

        return PlainPageBoxes::apply((new ReportDocument($report))->render());
    }
}
