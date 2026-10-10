# PATCH /api/v1/groups/{group}

Renames a group.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| group | UUID | A group of the caller's institution whose election is a draft or scheduled |

Body, JSON: `name`, required, with the rules of [POST](POST-elections-{election}-groups.md). The new name may differ only
by case or spaces from the group's own name (a way to fix a capital letter).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A new name | 200 (the group; its voters follow it) | | |
| 2 | The same name, or the same name in other case | 200 | | |
| 3 | `name` missing, blank or longer than 100; used by another group of the election | 422 | `validation_failed` (`name: required`, `name: max`, `name: unique`) | |
| 4 | The election is open, closed, published or archived | 409 | `election_voters_locked` | |
| 5 | Not a UUID, unknown, or another institution's group | 404 | `not_found` | |
| 6 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 8 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 9 | Too many requests | 429 | `too_many_attempts` | |
| 10 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |
| 11 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 200 — scenarios 1 and 2

The group resource of [GET /elections/{election}/groups](GET-elections-{election}-groups.md).

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order: 404, 422, 409.
