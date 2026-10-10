# DELETE /api/v1/parties/{party}

Deletes a party of a draft election. Its candidates stay and become independent.

| | |
|---|---|
| Slice | 06b (06c: the candidates keep their place; 06d: the logo file is removed) |
| Requirements | FR-CAND-01, FR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| party | UUID | A party of the caller's institution whose election is a **draft** |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A party of a draft | 204 |  | |
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

- From slice 06c, the candidates of the party have their party cleared (`party_id` set to null); none is deleted.
- The deletion is final: there is no trash.
