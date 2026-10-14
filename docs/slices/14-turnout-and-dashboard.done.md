# Slice 14 — done (2026-10-12)

Branch `slice/14-turnout-and-dashboard`, stacked on `slice/12b-voter-hardening` (and so on slice 13).

## Built
- Server: `GET /elections/{id}/turnout`, voters list `has_voted` / `voted_at` and filter `voted`, `GET /elections/{id}/voters/export`
  (xlsx and csv, FormulaGuard), `GET /dashboard`; limiter `turnout-read` (120 per minute per user) and `voters-export` (20 per hour).
- Web: turnout page A12 (SVG chart, group bars, non-voters list with filters and exports, 15 s polling), voters page
  flag and filter, dashboard with real data, link from the election page.

## Verified
Acceptance Slice14 and Slice07, full API suite (2236 passed), `make lint`, `make generate-check`, web unit tests (593),
browser e2e slice14 (desktop and phone), slice07, slice13. Not verified: 1920 px layout by eye, reduced motion,
English UI in the browser, the export limit in a browser.
Known, not caused here: `e2e/slice03/shell.spec.ts:114` (`data-nimg`) fails with and without this slice.

## Review (correctness and security)
No blocking finding. Fixed: percentage never shows 100 before everyone has voted; the series end is exclusive; polling
stops at the end and a chart bucket change survives an in-flight poll; start times in the election's zone; dashboard
polling retries and starts when a scheduled election opens; the Excel export stages its files in a private folder that is
always removed; a failed export stream is cut off instead of ending as a truncated file; a read limiter on the two read
endpoints; `counts.open` agrees with the `open` list.

## Decisions taken without Jun
- A phone number that starts with `+` and holds only digits, spaces, dots, dashes and brackets is left unquoted in exports.
- `upcoming` items do not carry a time zone (the web takes it from the election); the dashboard `voters` figure counts
  every election of the institution.
