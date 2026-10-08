# DELETE /api/v1/elections/{election}

Deletes a draft election, with everything that belongs to it.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-03 (Draft) |
| Caller | Institution user: owner or manager |
| Rate limit | 60 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

No query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A draft | 204 | | |
| 2 | The election is not a draft | 409 | `election_not_editable` | |
| 3 | Not a UUID, unknown, already deleted, or another institution's | 404 | `not_found` | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 7 | Too many requests | 429 | `too_many_attempts` | |
| 8 | Another method than GET, HEAD, PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body. The row is deleted for good (no soft delete), and so are its cover files. What hangs from an election in
later slices (ballots, parties, candidates, voters, groups) is removed with it by the database, never left behind.

### 409 — scenario 2

```json
{ "error": { "code": "election_not_editable", "message": "Cette élection ne peut plus être supprimée." } }
```

### 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table. Scenario 3 gives the same body
and headers for every cause.

## Notes

A second round that was started from this election (slice 15) keeps living: its link to this election is cleared.
