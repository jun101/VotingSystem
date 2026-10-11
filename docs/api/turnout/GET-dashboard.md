# GET /api/v1/dashboard

What the institution's home page shows: its elections by state, the open ones with their live turnout, and what needs
doing.

| | |
|---|---|
| Slice | 14 |
| Requirements | FR-NAV-01, FR-RES-01 |
| Caller | Institution user: owner or manager |
| Rate limit | The shared API limit |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An institution with elections in several statuses | 200 | | |
| 2 | An institution with no election | 200 (zeros, empty lists) | | |
| 3 | Elections of another institution | Never counted or listed | | |
| 4 | Not signed in, suspended institution, another method | 401, 403, 405 | the shared codes | |

## Responses

### 200

```json
{
  "data": {
    "counts": { "draft": 1, "scheduled": 0, "open": 1, "closed": 2, "published": 1, "archived": 0 },
    "voters": 300,
    "open": [
      { "id": "9f2c1d3e-6a40-4b8a-8c11-2d5e7f0a9b34", "title": "Conseil des élèves 2026", "ends_at": "2026-10-16T19:00:00Z",
        "eligible": 300, "voted": 187, "percentage": 62.3 }
    ],
    "upcoming": [ { "id": "…", "title": "…", "starts_at": "2026-10-20T12:00:00Z" } ],
    "todo": [
      { "kind": "voters_not_voted", "election": { "id": "…", "title": "…" }, "count": 113 },
      { "kind": "draft_blockers", "election": { "id": "…", "title": "…" }, "count": 2 },
      { "kind": "codes_missing", "election": { "id": "…", "title": "…" }, "count": 20 }
    ]
  }
}
```

`voters` is the number of voters of all the institution's elections. `counts` always holds the six statuses. `open` lists open elections (the time check applies: an election past its end is not listed as open), newest first, at
most 10. `todo`: `voters_not_voted` for each open election with voters who have not voted; `draft_blockers` for each
draft with checklist blockers (the count of blockers); `codes_missing` for each scheduled or open election with voters
without a code. No vote count of any ballot.

`upcoming` lists at most 10 scheduled elections, soonest first. `todo` is built from the open elections listed, the first
10 scheduled ones and the first 10 drafts.
