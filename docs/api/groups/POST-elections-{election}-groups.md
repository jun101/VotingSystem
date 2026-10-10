# POST /api/v1/elections/{election}/groups

Adds an empty group.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution whose status is **draft or scheduled** |

Body, JSON: `name`, required, 1 to 100 characters after trimming, unique in the election ignoring case and surrounding
spaces; accents count (`4e année` and `4e annee` are two groups).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A new name | 201 (`voters_count: 0`) | | |
| 2 | `name` missing, blank or longer than 100 | 422 | `validation_failed` (`name: required`, `name: max`) | |
| 3 | `name` already used in this election (case and spaces ignored, accents not) | 422 | `validation_failed` (`name: unique`) | |
| 4 | The election already has 100 groups | 409 | `group_limit_reached` | |
| 5 | The election is open, closed, published or archived | 409 | `election_voters_locked` | |
| 6 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |
| 12 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 201 — scenario 1

The group resource of [GET /elections/{election}/groups](GET-elections-{election}-groups.md), one item. Header
`Location` is `/api/v1/groups/{id}`.

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the body (422), then
the state (409; scenario 5 before 4). The unique check and the unique index `(election_id, name_key)` both exist.
