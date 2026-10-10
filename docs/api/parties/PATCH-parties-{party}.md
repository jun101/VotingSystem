# PATCH /api/v1/parties/{party}

Changes a party of a draft election. Every field is optional; only the fields present are changed.

| | |
|---|---|
| Slice | 06b |
| Requirements | FR-CAND-01 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| party | UUID | A party of the caller's institution whose election is a **draft** |

Body, JSON: `name`, `acronym`, `colour` with the rules of
[POST /elections/{election}/parties](POST-elections-{election}-parties.md), none required.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid body with some fields | 200 |  | |
| 2 | Empty body, or no known field | 200 (nothing changes) |  | |
| 3 | `acronym` sent as an empty string or `null` | 200 (stored as `null`) |  | |
| 4 | `name` blank, `null` or longer than 100 | 422 | `validation_failed` (`name: required`, `name: max`) | |
| 5 | `name` used by another party of the election | 422 | `validation_failed` (`name: unique`); its own current name is fine | |
| 6 | `colour` not `#RRGGBB`, or `acronym` too long | 422 | `validation_failed` (`colour: hex_colour`, `acronym: max`) | |
| 7 | The election is not a draft | 409 | `election_not_editable` | |
| 8 | Not a UUID, unknown, or another institution's party | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Body is not valid JSON | 400 | `malformed_request` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

The party resource (one item) of [GET /elections/{election}/parties](GET-elections-{election}-parties.md).

### 409, 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), then the body (422),
then the state (409).

## Notes

`id`, `election`, `logo`, `candidates_count` in the body are ignored (the logo has its own endpoint in 06d).
