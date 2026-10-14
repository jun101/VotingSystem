# PUT /api/v1/elections/{election}/ballots/order

Saves the display order of all the ballots of a draft election in one request (drag and drop and the up and down
buttons both use it).

| | |
|---|---|
| Slice | 06a |
| Requirements | FR-BAL-01, FR-BAL-04 (order on the page; the order on the ballot paper follows it) |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| ballots | array of UUID | Required. **Exactly** the UUIDs of the election's ballots, each once, in the wanted order |

```json
{ "ballots": ["7f0c…", "0b0e…", "c93d…"] }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | The same set in another order | 200 | | |
| 2 | The same order as stored | 200 (nothing changes) | | |
| 3 | `ballots` missing, not an array, holds more than 50 items, or an item is not a UUID | 422 | `validation_failed` (`ballots: required`, `ballots: array`, `ballots: max`, `ballots.0: uuid`) | |
| 4 | A UUID is repeated, missing, unknown, or belongs to another election or institution | 422 | `validation_failed` (`ballots: set_mismatch`) | |
| 5 | The set is right and the election is not a draft | 409 | `election_not_editable` | |
| 5b | The set is wrong and the election is not a draft | 422 | `validation_failed` (`ballots: set_mismatch`) | |
| 6 | The election has no ballot and `ballots` is `[]` | 200 (`data: []`) | | |
| 7 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 11 | Body is not valid JSON | 400 | `malformed_request` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than PUT | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1, 2 and 6

The same page as [GET /elections/{election}/ballots](GET-elections-{election}-ballots.md) with `per_page` 100, in the
new order, so the screen can replace its list with it.

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). A UUID of another institution gives the same 422 as an
unknown one (nothing says it exists). Order of the checks: the record (404), then the body (422), then the state (409).

## Side effects

`position` of each ballot becomes its index in the list, from 1, in one transaction with the ballots locked, so two
simultaneous reorders end in one of the two orders, never a mix.
