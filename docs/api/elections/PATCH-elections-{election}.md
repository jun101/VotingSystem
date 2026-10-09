# PATCH /api/v1/elections/{election}

Changes a draft election. Every field is optional; only the fields present are changed.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-01, FR-ELEC-02, FR-ELEC-03 (Draft) |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

Body, JSON: the fields of [POST /elections](POST-elections.md), none required. The same rules apply; `ends_at` must
be after `starts_at` **as they will be after the change** (a request that moves only one of them is checked against
the other one that is stored).

```json
{ "title": "Conseil des élèves 2026 (révisé)", "ends_at": "2026-10-16T21:00:00Z" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid body with some fields | 200 | | |
| 2 | Empty body, or no known field | 200 (nothing changes) | | |
| 3 | An optional field sent as an empty string or `null` (`description`) | 200 (stored as `null`) | | |
| 4 | `title` blank or `null` | 422 | `validation_failed` (`title: required`) | |
| 5 | The other rules of POST (length, date, time zone, choices) | 422 | `validation_failed` (`ends_at: after_start`, …) | |
| 6 | The election is not a draft | 409 | `election_not_editable` | |
| 7 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 11 | Body is not valid JSON | 400 | `malformed_request` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than GET, HEAD, PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

The election resource of [GET /elections/{election}](GET-elections-{election}.md) with the new values.

### 409 — scenario 6

```json
{ "error": { "code": "election_not_editable", "message": "Cette élection ne peut plus être modifiée." } }
```

### 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table. Scenario 7 gives the same
body and headers for every cause. Order of the checks: the record (404), then the body (422), then the state (409).

## Notes

- Fields that are not in the table of POST (`status`, `cover`, `institution`, `id`) are ignored: the status changes
  only through its own endpoints (slice 09 and after), the cover through
  [PUT /elections/{election}/cover](PUT-elections-{election}-cover.md).
- Only a draft can change in this slice; slices 09 and 13 define what a scheduled or an open election lets through
  (for instance extending the end of an open one).
