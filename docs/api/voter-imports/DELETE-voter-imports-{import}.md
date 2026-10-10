# DELETE /api/v1/voter-imports/{import}

Cancels an import: its file and its rows are deleted. Nothing was written to the election.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| import | UUID, path | An import of the caller's institution, in any status except `importing` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A `checked`, `failed` or `done` import | 204 (file, rows and error file deleted; the voters already written stay) | | |
| 2 | An `uploaded` import (still being checked) | 204 (the check job ends without writing) | | |
| 3 | An `importing` import | 409 | `import_in_progress` | |
| 4 | Not a UUID, unknown, or another institution's import | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Too many requests | 429 | `too_many_attempts` | |
| 9 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body.

### 409, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md).
