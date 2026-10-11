# PUT /api/v1/ballots/{ballot}/result-note

Sets or clears the public note shown with a ballot's result.

| | |
|---|---|
| Slice | 15 |
| Requirements | FR-RES-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 60 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| ballot | UUID | A ballot of the caller's institution whose election is `closed`, `published` or `archived` |

Body, JSON: `{ "note": "text or null" }`. `note` is trimmed, 1 to 1 000 characters, control characters other than line
breaks removed; `null` or an empty string clears it.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A text | 200 (the ballot's `note` is set) | | |
| 2 | `null` or empty | 200 (cleared) | | |
| 3 | More than 1 000 characters, or not a string | 422 | `validation_failed` (`note: max`, `note: invalid`) | |
| 4 | The election is a draft, scheduled or open | 409 | `results_not_available` | |
| 5 | Not a UUID, unknown, or another institution's ballot | 404 | `not_found` | |
| 6 | Not signed in, suspended institution, CSRF, too many requests, another method, bad JSON | 401, 403, 419, 429, 405, 400 | the shared codes | |

## Responses

### 200

`{ "data": { "id": "<ballot uuid>", "note": "…" } }`
