# GET /api/v1/elections/{election}/groups

The groups of an election with their voter counts, for the groups card of the voters page (screen A08).

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

Query: `page`, `per_page` as in [API conventions](../README.md). An election holds at most 100 groups, so the screen
asks for `per_page=100` and gets them all.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election with groups | 200 (by name ignoring case, then creation order) | | |
| 2 | An election with no group | 200 (`data: []`, `meta.total: 0`, `voters_total` and `ungrouped` still given) | | |
| 3 | The election is not a draft | 200 (reading is always allowed) | | |
| 4 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Too many requests | 429 | `too_many_attempts` | |
| 9 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{ "data": [ <group>, … ], "meta": { "page": 1, "per_page": 100, "total": 4, "voters_total": 312, "ungrouped": 5 } }
```

`total` counts groups; `voters_total` counts every voter of the election; `ungrouped` counts the voters without a group.

Each group:

```json
{
  "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60",
  "name": "4e année",
  "voters_count": 62,
  "created_at": "2026-10-10T14:02:11Z",
  "updated_at": "2026-10-10T14:02:11Z"
}
```

`id` is the group's UUID. `voters_count` is the number of voters of the election in the group.

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md).
