# Slice 16 — done (2026-10-12)

Branch `slice/16-publication`, stacked on `slice/15-results`. Acceptance tests first (65 API, 10 browser), then 16a server,
16b web, review (correctness and security), fixes.

## Built
- Publish, unpublish, archive, unarchive (row lock; a failing bulletin check blocks publication); admin PDF report; public
  results, public PDF and public institution endpoints (no sign-in, no session, no cookie, identical 404, per-address limits).
- Web: publication card in A13 (publish dialog with display choice and small-group warning), public results page with
  metadata, share links, preview image (1200 x 630), PDF and image downloads, institution page, public error page.

## Verified
Slice15 and Slice16 API tests, full API suite (2350 passed; the one failure was a missing comment, fixed and re-run on its
suite), `make lint`, web unit tests (617), browser e2e slice16 and slice15 (16 passed), earlier slices on the first run.
Not verified by eye: 320, 1280 and 1920 px layouts; the production font load of the preview image.

## Review
Blocking, fixed: the public pages did not forward the visitor's address, so all visitors shared one rate-limit bucket.
Also fixed: the winners-only display no longer reveals the ranking (candidates and winners in ballot order); no results link
for a suspended institution; the preview image is not cached after an unpublish; absolute URLs come from `SITE_URL`;
the proxy always overwrites its internal headers; PDF: header redrawn after a page break, per-language dates, binary-search
word cut, characters outside cp1252 transliterated; blank percentage rule; wording of the institution list.

## Known limits (for Jun)
- PDF characters outside Western European (CJK and some others) print as `?`: TCPDF has no bundled Unicode font here. A
  Unicode TTF would have to be added to the repository.
- A failing API on a public page shows an error page with HTTP 500 (not 503); only the preview image answers 503.
- Production needs `SITE_URL` set (the share links and preview tags use it).
- A group ballot of one voter publishes that voter's choice; the publish dialog warns when a ballot has fewer than 3
  eligible voters.

## Decisions taken without Jun
Public report follows the display setting; report omits a generation date; archive acts at once with no confirmation.
