# POST /api/v1/voter-imports/{import}/confirm

Confirms a checked import: the valid rows are written in the background.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03, FR-VOT-04, NFR-PERF-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| import | UUID, path | An import of the caller's institution, status `checked`, whose election is a draft or scheduled. No body |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A checked import with valid rows | 202 (status `importing`, nothing written yet) | | |
| 2 | The job ends: every valid row is written, its groups created | status `done`, `imported_rows = valid_rows` | | |
| 3 | A row became a duplicate between the check and the job (a voter added meanwhile) | status `done`, that row counted in `invalid_rows` and in the error file | | |
| 4 | The import is not `checked` (`uploaded`, `importing`, `done`, `failed`) | 409 | `import_not_checked` | |
| 5 | `valid_rows` is 0 | 409 | `nothing_to_import` | |
| 6 | The election would pass 10 000 voters | 409 | `voter_limit_reached` | |
| 7 | The election is no longer a draft or scheduled | 409 | `election_voters_locked` | |
| 8 | Not a UUID, unknown, or another institution's import | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 202 — scenario 1

The import resource of [GET /voter-imports/{import}](GET-voter-imports-{import}.md), status `importing`.

### 409, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the state (409;
scenario 7, then 4, 5, 6).

## Notes

The job writes the rows in chunks of 500, each chunk in one transaction that locks the election row first (as
[POST voters](../voters/POST-elections-{election}-voters.md)), re-checks the identifier and the email against the
voters that exist at that moment, and creates the groups it needs (the same find-or-create by name as slice 07). It
updates `processed_rows` after each chunk. A chunk is all or nothing; if the job stops half way (a crash, a database
error) the voters of the finished chunks stay, the status is `failed` with `failure: import_failed`, and `imported_rows`
says how many were written. A rejected row is never written. Codes are never created by an import.
