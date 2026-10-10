# DELETE /api/v1/parties/{party}/logo

Removes the logo of a party of a draft election, with its files.

| | |
|---|---|
| Slice | 06b2 |
| Requirements | FR-CAND-01, FR-CAND-04 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| party | UUID | A party of the caller's institution whose election is a **draft** |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A party with a logo | 204 (files deleted, `logo` is `null`) | | |
| 2 | A party with no logo, or the removal repeated | 204 | | |
| 3 | The election is not a draft | 409 | `election_not_editable` | |
| 4 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Another method than PUT, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body.

### 409, 404, 401, 403, 419, 405

The shared error shape of [API conventions](../README.md).
