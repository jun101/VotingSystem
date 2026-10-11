# GET /api/v1/elections/{election}/report

The official results report as a PDF, for institution users.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-RES-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `closed`, `published` or `archived` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A closed, published or archived election | 200, a PDF, always the full counts | | |
| 2 | A draft, scheduled or open election | 409 | `results_not_available` | |
| 3 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 4 | Not signed in, suspended institution, too many requests, another method | 401, 403, 429, 405 | the shared codes | |

## Responses

### 200

`Content-Type: application/pdf`, `Content-Disposition: attachment; filename="rapport-{slug}.pdf"` (`report-{slug}.pdf`
for an English election; the slug of the title), `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. US
Letter. Holds the institution's name, the election's title and period in its time zone, the turnout, and for each
ballot its title and seats, the candidates with votes and percentages, the blank votes, the winners, a tie as a tie, the
bulletin check (recorded and expected) and the public note. Texts in the election's language. No voter name, no code, no
numeric id.
