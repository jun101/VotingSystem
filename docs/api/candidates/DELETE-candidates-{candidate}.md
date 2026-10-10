# DELETE /api/v1/candidates/{candidate}

Deletes a candidate of a draft election.

| | |
|---|---|
| Slice | 06c (06d: the photo files go with it) |
| Requirements | FR-CAND-02, FR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| candidate | UUID | A candidate of the caller's institution whose election is a **draft** |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A candidate of a draft | 204 |  | |
| 2 | The election is not a draft | 409 | `election_not_editable` | |
| 3 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 7 | Too many requests | 429 | `too_many_attempts` | |
| 8 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body.

### 409, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md). Scenario 3 gives the same body for every cause.

## Side effects

- The positions after it in the ballot close up in the same transaction; the counts of the ballot and the party go down.
- The deletion is final: there is no trash.
