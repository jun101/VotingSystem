# DELETE /api/v1/voters/{voter}

Deletes one voter.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-06, FR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| voter | UUID | A voter of the caller's institution whose election is a **draft or scheduled** |

A voter of an open election is not deleted through the application yet: slice 12 knows who has voted and will allow
deleting a voter who has not.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A voter of a draft or scheduled election | 204 (the voter's group stays, even when it becomes empty) | | |
| 2 | The election is open, closed, published or archived | 409 | `election_voters_locked` | |
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
