# PUT /api/v1/ballots/{ballot}/candidates/order

Saves the display order of all the candidates of a ballot of a draft election in one request.

| | |
|---|---|
| Slice | 06c |
| Requirements | FR-CAND-02, FR-BAL-04 (manual order; a shuffled election shuffles per voter in slice 12) |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| ballot | UUID | A ballot of the caller's institution whose election is a **draft** |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| candidates | array of UUID | Required. **Exactly** the UUIDs of the ballot's candidates, each once, in the wanted order |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | The same set in another order | 200 |  | |
| 2 | The same order as stored, or `[]` for a ballot with no candidate | 200 (nothing changes) |  | |
| 3 | `candidates` missing, not an array, or an item not a UUID | 422 | `validation_failed` (`candidates: required`, `candidates: array`, `candidates.0: uuid`) | |
| 4 | A UUID repeated, missing, unknown, or of another ballot, election or institution | 422 | `validation_failed` (`candidates: set_mismatch`), the same body for each | |
| 5 | The election is not a draft | 409 | `election_not_editable` | |
| 6 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 10 | Body is not valid JSON | 400 | `malformed_request` | |
| 11 | Too many requests | 429 | `too_many_attempts` | |
| 12 | Another method than PUT | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

```json
{ "data": [ <candidate>, … ] }
```

The ballot's candidates, in the new order, each as the resource of
[POST /ballots/{ballot}/candidates](POST-ballots-{ballot}-candidates.md).

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the body (422), the
state (409).

## Side effects

`position` of each candidate becomes its index in the list, from 1, in one transaction with the ballot's candidates
locked.
