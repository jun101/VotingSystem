# DELETE /api/v1/elections/{election}/cover

Removes the cover picture of a draft election.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-01 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A cover exists | 204 | | |
| 2 | There is no cover | 204 | | |
| 3 | The election is not a draft | 409 | `election_not_editable` | |
| 4 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Another method than PUT, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body. `elections.cover_file` is `null` and the files are deleted. Idempotent.

### 409, 404, 401, 403, 419, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
