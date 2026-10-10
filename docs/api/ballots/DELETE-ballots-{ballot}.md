# DELETE /api/v1/ballots/{ballot}

Deletes a ballot of a draft election, with its candidates.

| | |
|---|---|
| Slice | 06a (06c: the candidates go with it) |
| Requirements | FR-BAL-01, FR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| ballot | UUID | A ballot of the caller's institution whose election is a **draft** |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A ballot of a draft | 204 | | |
| 2 | The election is not a draft | 409 | `election_not_editable` | |
| 3 | Not a UUID, unknown, already deleted, or another institution's | 404 | `not_found` | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 7 | Too many requests | 429 | `too_many_attempts` | |
| 8 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body.

### 409, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md).

## Side effects

- The positions of the ballots after it close the gap (1, 2, 3… with no hole), in the same transaction.
- The election's `ballots_count` goes down by one.
- From slice 06c, its candidates are deleted with it; their photo files are removed in slice 06d.
- The deletion is final: there is no trash.
