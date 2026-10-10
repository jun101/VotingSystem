# Slice 08 — Voter import

Brief · 2026-10-10 · branch `slice/08-import` (from `staging`)

## Goal

An owner or a manager downloads a template, uploads an `.xlsx` or `.csv` of voters, corrects the column mapping, sees
every row checked, confirms, and watches the valid rows being imported in the background. The rejected rows come back
as a spreadsheet to correct. Nothing is written before the confirmation. Screens A09 (template and file) and A10 (check
and import).

## Requirements covered

FR-VOT-03, FR-VOT-04, NFR-SEC-05 (file upload limiter), NFR-SEC-06 (content check, no formula evaluation, protected
export), NFR-PERF-03 (background jobs), NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-10)

- Reading library: **openspout** (streams, reads values only, never evaluates a formula).
- A duplicate is **the same identifier or the same email**, in the file or already in the election. The same name in the
  same group is not a duplicate (as slice 07).
- Import allowed in a **draft or scheduled** election only (an import creates groups).
- The file of rejected rows is an **`.xlsx`** with a `ligne` and a `raison` column, same columns as the template.
- The mockup [material-voter-import.html](../design/mockups/material-voter-import.html), approved.

## Rules decided in the contract

| Rule | Value |
|---|---|
| Who | Owner and manager |
| File | `.xlsx` or `.csv` judged by content, 5 MB at most, 10 000 data rows at most (else status `failed`, `too_many_rows`) |
| Template | `GET …/voter-imports/template?format=xlsx\|csv`: `nom_complet`, `groupe`, `identifiant`, `courriel`, `telephone`, three example rows |
| Columns | Header names matched case, accent and space insensitively, French or English (`nom_complet`, `full_name`, `nom`, `name`; `groupe`, `group`, `classe`; `identifiant`, `identifier`, `matricule`, `id`; `courriel`, `email`, `e-mail`, `mail`; `telephone`, `phone`, `tel`); the user corrects the mapping with PATCH |
| Row rules | As POST voters (trim, blank is null, email lower-cased and strict, phone characters, lengths); empty rows are skipped |
| Groups | Created from the `groupe` column, found by name as slice 07; past 100 groups, rows needing a new one are invalid (`group_limit_reached`) |
| Duplicates | `duplicate_identifier_in_file`, `duplicate_email_in_file` (the first row stays valid), `identifier_exists`, `email_exists` |
| One at a time | One import per election in `uploaded` or `importing`; a new upload deletes the earlier `checked` and `failed` ones |
| Confirm | 409 `voter_limit_reached` if the valid rows would pass 10 000 voters; the job re-checks duplicates per chunk of 500 |
| Rate limits | `voter-imports-upload` 20 an hour per user; `voter-imports` 240 an hour per user |

## Read before coding

[database.md](../design/database.md) `voter_imports`, `voter_import_rows` and section 8 · [architecture.md](../design/architecture.md)
(queue worker, private storage) · [frontend.md](../design/frontend.md) (the import routes, stepper, `ok-ink` tokens) · the
eight endpoint files in [voter-imports/](../api/voter-imports/) · the slice 07 code (`Voter`, `VoterGroup`,
`GroupByName`, `VoterController`, `VotersPage`), which this slice follows.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `GET /elections/{election}/voter-imports/template` | [GET-elections-{election}-voter-imports-template.md](../api/voter-imports/GET-elections-{election}-voter-imports-template.md) |
| `POST /elections/{election}/voter-imports` | [POST-elections-{election}-voter-imports.md](../api/voter-imports/POST-elections-{election}-voter-imports.md) |
| `GET /voter-imports/{import}` | [GET-voter-imports-{import}.md](../api/voter-imports/GET-voter-imports-{import}.md) |
| `PATCH /voter-imports/{import}` | [PATCH-voter-imports-{import}.md](../api/voter-imports/PATCH-voter-imports-{import}.md) |
| `GET /voter-imports/{import}/rows` | [GET-voter-imports-{import}-rows.md](../api/voter-imports/GET-voter-imports-{import}-rows.md) |
| `POST /voter-imports/{import}/confirm` | [POST-voter-imports-{import}-confirm.md](../api/voter-imports/POST-voter-imports-{import}-confirm.md) |
| `GET /voter-imports/{import}/errors` | [GET-voter-imports-{import}-errors.md](../api/voter-imports/GET-voter-imports-{import}-errors.md) |
| `DELETE /voter-imports/{import}` | [DELETE-voter-imports-{import}.md](../api/voter-imports/DELETE-voter-imports-{import}.md) |

## What to build

The slice is cut in four runs, each ending green before the next (as slice 06).

### 08a. Server: template, upload and check

| Item | Requirement |
|---|---|
| Dependency | `openspout/openspout` (current major), added to `composer.json`; no formula is ever evaluated |
| Migrations | `voter_imports` and `voter_import_rows` as in database.md (`uuid` on imports; tenant column on both) |
| Models | `VoterImport`, `VoterImportRow`: `HasUuid`, `BelongsToInstitution`; `Election::voterImports()` |
| Template | Built on the fly (xlsx with an `Instructions` sheet in the user's language; csv UTF-8 with BOM) |
| Upload | Content check (ZIP with `[Content_Types].xml` and `xl/workbook.xml`, or text with no NUL, UTF-8 or Windows-1252), size, zip-bomb bound; stored under a random UUID on a private disk (never a path from the name); queue job `CheckVoterImport` |
| Check job | Streams the file; reads the first sheet; detects the separator `,` or `;`; proposes the mapping; validates each row; writes `voter_import_rows`, counts, `new_groups`, status `checked` or `failed`; never logs a cell |
| Mapping | PATCH re-runs the check with the new mapping |
| Rows | Paged 50, filter `all`, `ok`, `errors` |
| Delete | Removes file and rows; an `uploaded` import is cancelled (the job checks the import still exists) |
| Isolation | The routes are in `Tenancy::TENANT_ROUTES` (added with the acceptance tests) |
| Test environment | The `test` service uses `QUEUE_CONNECTION=sync` so a job runs inside the request that dispatches it |

### 08b. Server: confirm, import and error file

| Item | Requirement |
|---|---|
| Confirm | Status and limit checks; sets `importing`; dispatches `RunVoterImport` |
| Import job | Chunks of 500, one transaction each, election row locked first, duplicates re-checked, groups by name (`GroupByName`), `processed_rows` updated; ends `done` (or `failed`, `import_failed`) |
| Error file | `.xlsx` of the rejected rows (template columns, `ligne`, `raison`), cells beginning `=`, `+`, `-`, `@` prefixed with `'`; reason words in the user's language |
| Clean-up | Scheduled command deleting imports older than 7 days that are not `importing` (file, rows) |
| Election delete | Deletes its imports, files and rows |

### 08c. Web: A09, the template and the file

Route `/admin/elections/{election}/voters/import`; follow the mockup (steps 1 and 2). Test ids:

| Element | Test id |
|---|---|
| Page, stepper | `import-page`, `import-step-1` … `import-step-4` (`aria-current="step"` on the active one) |
| Template buttons | `import-template-xlsx`, `import-template-csv` |
| Template preview and instructions | `import-template-preview`, `import-instructions` |
| Drop zone | `import-dropzone` (button and drop), `import-file-input` (hidden input), `import-file-name`, `import-upload` (starts the upload), `import-error` (a 422 or 409 in words) |
| Locked | `import-locked-notice` (election not a draft or scheduled; the zone is not rendered) |
| Back | `import-back` to the voters page |

The voters page hero gets `voters-import` ("Importer un fichier"), shown only in a draft or scheduled election, linking to
this route. After the upload the page goes to A10.

### 08d. Web: A10, check and import

Route `/admin/elections/{election}/voters/import/{import}`; steps 3 and 4 of the mockup. Polls the import while it is
`uploaded` or `importing` (every 2 seconds). Test ids:

| Element | Test id |
|---|---|
| Page | `check-page`, `check-checking` (spinner while `uploaded`), `check-failed` (status `failed`, with the failure in words and `check-retry` back to A09) |
| Summary tiles | `check-total`, `check-valid`, `check-invalid`, `check-duplicates`, `check-new-groups` (the names) |
| Mapping | `map-select-full_name`, `map-select-group`, `map-select-identifier`, `map-select-email`, `map-select-phone` (options: the file's columns and "Ne pas importer"); a change sends the PATCH and shows `check-checking` again |
| Preview | `check-rows` (table), `check-row-{n}` (n from 1 on the page), `check-row-status-{n}`, `check-filter-errors` (switch), `check-pager`, `check-prev`, `check-next` |
| Actions | `check-error-file` (download, shown when there are rejected rows), `check-cancel` (DELETE, back to the voters page), `check-confirm` ("Importer N électeurs", disabled when 0 valid) |
| Progress | `import-progress` (`role="progressbar"`, `aria-valuenow`), `import-progress-text` ("120 sur 300") |
| Done | `import-done`, `import-done-count`, `import-done-errors` (rejected rows, with the error file link), `import-done-voters` (link to the voters page) |
| Text and colour | French and English message files only; tokens only |
| Phone | One column, the preview table scrolls inside its box, no sideways scroll of the page |

### Tests (written before the code, the coder never edits them)

- API: `api/tests/Acceptance/Slice08/` — one file per endpoint file; helpers in `api/tests/Support/Accounts.php`
  (`plantImport`, `importRow(s)`) and `api/tests/Support/Spreadsheets.php` (builds small `.xlsx` and `.csv` upload bodies,
  reads an `.xlsx` response back).
- Browser: `web/e2e/slice08/import.spec.ts`, `web/e2e/support/imports.ts`.

Tests written by the coder: unit tests of the pure helpers (header matching, separator detection, reason words,
progress text, mapping validation) and a component test that a non draft or scheduled election shows no upload zone.

## Done when

`make lint`, the unit tests and both acceptance folders pass with no edit; `docs/api/openapi.json` and
`web/src/lib/api/schema.d.ts` regenerated by the generator; a 10 000-row file checks and imports within a minute on the
dev stack with no memory error; the pages looked at at 1920 × 1080, 1280 × 720 and 360 × 780.

## Not in this slice

Loading voters from a past election (slice 09), codes (10), emails (11), "has voted" (12). Import of candidates is not
planned.
