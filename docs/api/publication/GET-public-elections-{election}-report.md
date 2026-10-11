# GET /api/v1/public/elections/{election}/report

The results report of a published election as a PDF, for anyone.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-RES-06 |
| Caller | Anyone |
| Rate limit | 30 requests per minute per address and election |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A published election | 200, a PDF following the election's `results_display` (a `winners` election's report has no counts) | | |
| 2 | Any status but `published`, unknown, not a UUID, suspended institution | 404 | `not_found` | |
| 3 | Too many requests, another method | 429, 405 | the shared codes | |

## Responses

### 200

The same document as [GET report](GET-elections-{election}-report.md), without the bulletin check line when the
display is `winners`. Same headers.
