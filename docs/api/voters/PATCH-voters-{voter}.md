# PATCH /api/v1/voters/{voter}

Changes the fields of one voter.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-06, FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| voter | UUID | A voter of the caller's institution whose election is a draft, scheduled or open |

Body, JSON: any of `full_name`, `group`, `identifier`, `email`, `phone`, with the rules of
[POST](POST-elections-{election}-voters.md). A field that is absent keeps its value; `null` or blank clears `group`,
`identifier`, `email` and `phone`; `full_name` cannot be cleared. An empty object is allowed and changes nothing.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | One or more fields | 200 (the voter, `updated_at` moves) | | |
| 2 | `group` is the name of another group of the election, or a new name | 200 (the other group is used, or created) | | |
| 3 | `group` is `null` | 200 (`group: null`) | | |
| 4 | The same values as stored | 200 (nothing changes) | | |
| 5 | An empty object | 200 (nothing changes) | | |
| 6 | `full_name` blank or too long; `identifier` or `email` used by another voter of the election; `email`, `phone`, `group` invalid | 422 | `validation_failed` (as POST) | |
| 7 | A new group is needed and the election already has 100 groups | 409 | `group_limit_reached` | |
| 8 | The election is closed, published or archived | 409 | `election_voters_locked` | |
| 9 | Not a UUID, unknown, or another institution's voter | 404 | `not_found` | |
| 10 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 11 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 12 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |
| 15 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 200 — scenarios 1 to 5

The voter resource of [GET /elections/{election}/voters](GET-elections-{election}-voters.md).

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the body (422), then
the state (409). A voter's own identifier or email is not a duplicate of itself.

## Notes

The election cannot be changed through this endpoint: `election`, `id` and every `*_id` in the body are ignored.
