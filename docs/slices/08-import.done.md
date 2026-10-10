# Slice 08 done (2026-10-10)

Importing voters from a spreadsheet: template, upload, column mapping, row-by-row check, confirmation, background import with progress, and a file of the rejected rows. Screens A09 and A10. FR-VOT-03, FR-VOT-04, NFR-SEC-05, NFR-SEC-06, NFR-PERF-03.

- API: eight endpoints (template, upload, show, mapping, rows, confirm, error file, cancel) in `docs/api/voter-imports/`; tables `voter_imports` and `voter_import_rows`; queue jobs `CheckVoterImport` and `RunVoterImport` (chunks of 500, one transaction each, election row locked first); daily `imports:purge`.
- Rules: `.xlsx` or `.csv` judged by content, 5 MB, 10 000 rows (11 000 lines read, empty ones included), 50 columns; a duplicate is the same identifier or email, in the file or in the election; draft or scheduled election only; a formula is never evaluated and the error file quotes cells that start with `=`, `+`, `-`, `@`.
- Pages: `/admin/elections/{election}/voters/import` (template, drop zone) and `/voters/import/{import}` (summary, mapping, preview with errors filter, error-file download, cancel, confirm, progress, result). The voters page gets "Importer un fichier".
- Also: the right-rail cards of the voters and import pages keep the height of their content.
- Checks at the end: whole API suite 1636 passed, web unit 433, browser slice08 30 passed (slice07 36, theme 94), lint clean. A 10 000-row file is checked in about 5 seconds.
- Review: `08-import.review.md`, one round (correctness, security, id-leak), all findings closed.
- Test changes after the test commit: a wait for the address in two browser tests, and content checks on the downloads (both by the verification step).
- Not in this slice: loading voters from a past election and group scope on ballots (slice 09), codes (10), emails (11), "has voted" (12).
