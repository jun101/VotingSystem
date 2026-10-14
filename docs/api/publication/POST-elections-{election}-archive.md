# POST /api/v1/elections/{election}/archive and /unarchive

Archives a finished election (read-only, hidden from the default list), or brings it back.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-ELEC-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user (shared) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | `archive`: an election that is `closed` or `published`. `unarchive`: one that is `archived` |

No body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `archive` on a closed election | 200 (status `archived`, `archived_at` now) | | |
| 2 | `archive` on a published election | 200 (status `archived`; the public pages answer 404 from then on) | | |
| 3 | `archive` on a draft, scheduled, open or archived election | 409 | `election_not_archivable` | |
| 4 | `unarchive` on an archived election | 200 (status `closed`, `archived_at` and `published_at` cleared) | | |
| 5 | `unarchive` on any other status | 409 | `election_not_archived` | |
| 6 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 7 | Not signed in, suspended institution, CSRF, too many requests, another method | 401, 403, 419, 429, 405 | the shared codes | |

## Responses

### 200

The election with its new status.

## Notes

Archiving a published election also takes it off the public page: only `published` is public. Results stay readable by
institution users ([GET results](../results/GET-elections-{election}-results.md) answers for `archived`).
