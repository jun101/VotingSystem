# POST /api/v1/elections/{election}/voter-imports

Uploads a voter file. Nothing is imported: the file is checked in the background and the result is read with
[GET /voter-imports/{import}](GET-voter-imports-{import}.md).

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03, FR-VOT-04, NFR-SEC-05, NFR-SEC-06, NFR-PERF-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 uploads per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| election | UUID, path | An election of the caller's institution that is a **draft or scheduled** |

Body, `multipart/form-data`:

| Field | Type | Rules |
|---|---|---|
| file | file | Required. An `.xlsx` or a `.csv`, **judged by its content, not by its name or declared type**: an `.xlsx` is a ZIP holding `[Content_Types].xml` and `xl/workbook.xml`; a `.csv` is text, UTF-8 (a byte-order mark is allowed) or Windows-1252, with no NUL byte. At most 5 MB (5 242 880 bytes). The ZIP of an `.xlsx` may not expand beyond 100 MB (zip bomb: `file: too_large`) |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid `.xlsx` | 202 | | |
| 2 | A valid `.csv` (separator `,` or `;`) | 202 | | |
| 3 | `file` missing | 422 | `validation_failed` (`file: required`) | |
| 4 | The content is neither a spreadsheet nor a text file (a PNG renamed `.xlsx`, a ZIP that is not a workbook, a binary renamed `.csv`) | 422 | `validation_failed` (`file: invalid_type`) | |
| 5 | The file is empty (0 byte) | 422 | `validation_failed` (`file: empty`) | |
| 6 | The file is over 5 MB | 422 | `validation_failed` (`file: too_large`) | |
| 7 | An earlier import of this election is waiting (`uploaded`) or running (`importing`) | 409 | `import_in_progress` | |
| 8 | The election is open, closed, published or archived | 409 | `election_voters_locked` | |
| 9 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 10 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 11 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 12 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 202 — scenarios 1 and 2

The import resource of [GET /voter-imports/{import}](GET-voter-imports-{import}.md), status `uploaded`. Header
`Location` is `/api/v1/voter-imports/{id}`. The earlier imports of the election that were `checked` or `failed` are
deleted with their file and rows (one import at a time). The problems found **inside** the file (no header, more than
10 000 rows, unreadable cells) are not 422: the background check ends in status `failed` with a `failure` code.

### 409 — scenarios 7 and 8

```json
{ "error": { "code": "import_in_progress", "message": "Un import est déjà en cours pour cette élection." } }
```

### 422, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the status (409
`election_voters_locked`), the file (422), then the running import (409 `import_in_progress`).

## Notes

The file is stored under its own random UUID on a private disk that the proxy never serves; the original name is kept
for display only and never used as a path. Cells are read as values: a formula is never evaluated (its cached value is
used; a formula with no cached value is kept as its text and treated as plain text, and the error file protects it). The upload and the check never log a cell.
