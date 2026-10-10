# PATCH /api/v1/ballots/{ballot}

Changes a ballot of a draft election. Every field is optional; only the fields present are changed.

| | |
|---|---|
| Slice | 06a |
| Requirements | FR-BAL-01, FR-BAL-02 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| ballot | UUID | A ballot of the caller's institution whose election is a **draft** |

Body, JSON: `title`, `description`, `seats`, `allow_blank` with the rules of
[POST /elections/{election}/ballots](POST-elections-{election}-ballots.md), none required. `position` is not accepted
here (see [PUT order](PUT-elections-{election}-ballots-order.md)).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid body with some fields | 200 | | |
| 2 | Empty body, or no known field | 200 (nothing changes) | | |
| 3 | `description` sent as an empty string or `null` | 200 (stored as `null`) | | |
| 4 | `title` blank, `null` or longer than 200 | 422 | `validation_failed` (`title: required`, `title: max`) | |
| 5 | The other rules of POST | 422 | `validation_failed` (`seats: max`, …) | |
| 6 | The election is not a draft | 409 | `election_not_editable` | |
| 7 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 11 | Body is not valid JSON | 400 | `malformed_request` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

The ballot resource (one item) of [GET /elections/{election}/ballots](GET-elections-{election}-ballots.md).

### 409, 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), then the body (422),
then the state (409).

## Notes

`position`, `id`, `election`, `candidates_count` in the body are ignored.
