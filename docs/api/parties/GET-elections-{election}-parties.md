# GET /api/v1/elections/{election}/parties

The parties (slates, lists) of an election, for the ballots page (screen A06). Parties are optional.

| | |
|---|---|
| Slice | 06b |
| Requirements | FR-CAND-01 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

Query: `page`, `per_page` as in [API conventions](../README.md). An election holds at most 30 parties
([POST](POST-elections-{election}-parties.md) scenario 7), so the screen asks for `per_page=100` and gets them all.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election with parties | 200 (by name, ignoring case, then creation order) |  | |
| 2 | An election with no party | 200 (`data: []`, `meta.total: 0`) |  | |
| 3 | The election is not a draft | 200 (reading is always allowed) |  | |
| 4 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{ "data": [ <party>, … ], "meta": { "page": 1, "per_page": 100, "total": 2 } }
```

Each party:

```json
{
  "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60",
  "name": "Avenir Étudiant",
  "acronym": "AE",
  "colour": "#5468D4",
  "logo": null,
  "candidates_count": 0,
  "created_at": "2026-10-10T14:02:11Z",
  "updated_at": "2026-10-10T14:02:11Z"
}
```

`id` is the party's UUID. `logo` is `null` until slice 06d. `candidates_count` is `0` until slice 06c. No numeric id,
no `election_id`, no `institution_id` appears.

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md). Scenario 4 gives the same body and headers for every cause.
