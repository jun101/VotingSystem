# GET /api/v1/elections/{election}/ballots

The ballots of an election, in display order, for the ballots page (screen A06).

| | |
|---|---|
| Slice | 06a (06c adds the candidates of each ballot) |
| Requirements | FR-BAL-01, FR-BAL-02 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

Query: `page`, `per_page` as in [API conventions](../README.md). An election holds at most 50 ballots
([POST](POST-elections-{election}-ballots.md) scenario 6), so the screen asks for `per_page=100` and gets them all.

Order: `position` ascending, then creation order.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election with ballots | 200 | | |
| 2 | An election with no ballot | 200 (`data: []`, `meta.total: 0`) | | |
| 3 | The election is not a draft | 200 (reading is always allowed) | | |
| 4 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{
  "data": [
    {
      "id": "0b0e5b9a-2f43-4c1d-9a55-8d1f6a7c2e10",
      "title": "Président(e)",
      "description": null,
      "position": 1,
      "seats": 1,
      "allow_blank": true,
      "candidates_count": 0,
      "candidates": [],
      "created_at": "2026-10-10T14:02:11Z",
      "updated_at": "2026-10-10T14:02:11Z"
    }
  ],
  "meta": { "page": 1, "per_page": 100, "total": 1 }
}
```

`id` is the ballot's UUID. `position` is 1, 2, 3… with no gap. `candidates` (from slice 06c) holds the ballot's candidates in order, each as the resource of [POST /ballots/{ballot}/candidates](../candidates/POST-ballots-{ballot}-candidates.md); `candidates_count` is its length (`0` and `[]` before 06c).
No numeric id, no `election_id`, no `institution_id` appears.

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md). Scenario 4 gives the same body and headers for every cause.

## Notes

- Slice 09 adds `scope` and `groups` to every item.
- `result_note` of the schema is not output here: it is read by the results slices.
