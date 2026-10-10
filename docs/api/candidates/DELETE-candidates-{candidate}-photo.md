# DELETE /api/v1/candidates/{candidate}/photo

Removes the photo of a candidate of a draft election, with its files.

| | |
|---|---|
| Slice | 06d |
| Requirements | FR-CAND-02, FR-CAND-04 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| candidate | UUID | A candidate of the caller's institution whose election is a **draft** |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A candidate with a photo | 204 (files deleted, `photo` is `null`) | | |
| 2 | A candidate with no photo, or the removal repeated | 204 | | |
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
