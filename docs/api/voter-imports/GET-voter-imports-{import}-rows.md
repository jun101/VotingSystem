# GET /api/v1/voter-imports/{import}/rows

Lists the rows of a checked import, with their status: the preview of the check step.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| import | UUID, path | An import of the caller's institution, status `checked`, `importing` or `done` |
| filter | string, query | `all` (default), `ok` or `errors` (every invalid row, duplicates included) |
| page | integer, query | From 1, default 1, at most 1 000 000 |

Pages hold 50 rows, in file order.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `filter=all` | 200 | | |
| 2 | `filter=ok` | 200 (valid rows only) | | |
| 3 | `filter=errors` | 200 (invalid rows only) | | |
| 4 | A page past the end | 200, `data: []` | | |
| 5 | `filter` or `page` invalid | 422 | `validation_failed` | |
| 6 | The import is `uploaded` or `failed`: there are no rows | 409 | `import_not_checked` | |
| 7 | Not a UUID, unknown, or another institution's import | 404 | `not_found` | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200

```json
{ "data": [
  { "line": 2, "status": "ok", "reasons": [], "full_name": "Rose-Marie Désir", "group": "4e année", "identifier": "E-2041", "email": "rm.desir@example.ht", "phone": "+509 3712 4455" },
  { "line": 4, "status": "invalid", "reasons": ["full_name_required"], "full_name": null, "group": "5e année", "identifier": "E-2107", "email": null, "phone": null },
  { "line": 6, "status": "duplicate", "reasons": ["duplicate_identifier_in_file"], "full_name": "Wideline Joseph", "group": "4e année", "identifier": "E-2041", "email": null, "phone": null }
], "meta": { "page": 1, "per_page": 50, "total": 312 } }
```

| Field | Notes |
|---|---|
| line | The line number in the file (the header is line 1). For a CSV it counts records, as a spreadsheet application shows them: a cell with a line break inside quotes is one line |
| status | `ok`, `invalid` or `duplicate` (a row with a duplicate reason; it may also hold others) |
| reasons | Codes: `full_name_required`, `full_name_max`, `group_max`, `identifier_max`, `email_invalid`, `email_max`, `phone_invalid`, `phone_max`, `group_limit_reached`, `duplicate_identifier_in_file`, `duplicate_email_in_file`, `identifier_exists`, `email_exists`. A duplicate in the file points to the **first** row; that first row stays valid |
| values | The cell values after the rules of [POST voters](../voters/POST-elections-{election}-voters.md) (trimmed, blank is `null`, email lower-cased); the text is shown as is, never as HTML |

`total` is the number of rows of the chosen filter.

### 409, 422, 404, 401, 403, 429, 405

The shared error shape of [API conventions](../README.md).
