# POST /api/v1/elections/{election}/unpublish

Takes the results off the public page. The election goes back to `closed`.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-ELEC-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user (shared) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `published` |

No body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A published election | 200 (status `closed`, `published_at` cleared) | | |
| 2 | Any other status | 409 | `election_not_published` | |
| 3 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 4 | Not signed in, suspended institution, CSRF, too many requests, another method | 401, 403, 419, 429, 405 | the shared codes | |

## Responses

### 200

The election with `status` `closed`. The public endpoints answer 404 for it at once.
