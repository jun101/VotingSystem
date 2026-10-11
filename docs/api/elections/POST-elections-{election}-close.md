# POST /api/v1/elections/{election}/close

Closes an open election now, instead of waiting for its end. It cannot be undone.

| | |
|---|---|
| Slice | 13 |
| Requirements | FR-ELEC-03, FR-ELEC-05 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `open` |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| confirmation | string | Required. The election's title, as written (case and surrounding spaces ignored, accents counted) |

```json
{ "confirmation": "Conseil des élèves 2026" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An open election, the right title | 200 (the election with `status` `closed`, `closed_at` now) | | |
| 2 | The same title in other case and with spaces around | 200 | | |
| 3 | A title that does not match, or no `confirmation` | 422 | `validation_failed` (`confirmation: mismatch`, `confirmation: required`) | |
| 4 | The election is a draft, scheduled, closed, published or archived | 409 | `election_not_open` | |
| 5 | A voter's session in progress, the next call | 409 | `election_not_open` (slice 12 endpoints) | |
| 6 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 7 | Not signed in, suspended institution, CSRF, too many requests, another method, bad JSON | 401, 403, 419, 429, 405, 400 | the shared codes | |

## Responses

### 200

The election as in [GET election](GET-elections-{election}.md), `status` `closed`, `closed_at` set.

## Notes

The election row is locked and its status read again under the lock. Closing writes nothing about votes and reads no
vote. After closing, vote attempts answer 409 `election_not_open` (the time check and the status both say so). The
generated slip and code files are removed by the daily purge.
