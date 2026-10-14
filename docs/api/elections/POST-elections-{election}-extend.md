# POST /api/v1/elections/{election}/extend

Moves the end of a scheduled or open election later.

| | |
|---|---|
| Slice | 13 |
| Requirements | FR-ELEC-05 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user (shared with close) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `scheduled` or `open` |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| ends_at | datetime (ISO 8601, UTC or with offset) | Required. After the current end; at most 30 days after the current end |

```json
{ "ends_at": "2026-10-17T19:00:00Z" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An open election, a later end | 200 (`ends_at` changed, status unchanged) | | |
| 2 | A scheduled election, a later end | 200 | | |
| 3 | An open election whose end has just passed but the scheduler has not run | 409 (the election is over) | `election_not_extendable` | |
| 4 | An end equal to or before the current end | 422 | `validation_failed` (`ends_at: not_after`) | |
| 5 | An end more than 30 days after the current end | 422 | `validation_failed` (`ends_at: too_far`) | |
| 6 | `ends_at` missing or not a date | 422 | `validation_failed` (`ends_at: required`, `ends_at: invalid`) | |
| 7 | A draft, closed, published or archived election | 409 | `election_not_extendable` | |
| 8 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 9 | Not signed in, suspended institution, CSRF, too many requests, another method, bad JSON | 401, 403, 419, 429, 405, 400 | the shared codes | |

## Responses

### 200

The election as in [GET election](GET-elections-{election}.md) with the new `ends_at`.

## Notes

A voter who votes after the old end but before the new one is accepted: the transaction reads the election's current
end. Files of slips expire at the election's current end (slice 10 review).

Order of the checks: election (404), then the shape of the body (422 `ends_at: required` or `invalid`, even when the
election is in another state), then the state under the row lock (409), then the value (422 `not_after`, `too_far`).
