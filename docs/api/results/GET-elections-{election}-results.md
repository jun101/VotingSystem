# GET /api/v1/elections/{election}/results

The results of a closed election, per ballot.

| | |
|---|---|
| Slice | 15 |
| Requirements | FR-RES-02, FR-RES-03, FR-SEC-03, FR-SEC-04, FR-SEC-05 |
| Caller | Institution user: owner or manager |
| Rate limit | The shared API limit |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `closed`, `published` or `archived` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A closed election with votes | 200 | | |
| 2 | A ballot with one seat and a clear winner | 200 (`winners` holds the candidate, `tie` null) | | |
| 3 | A ballot with two seats | 200 (the two best are winners) | | |
| 4 | A tie for the last seat | 200 (`tie` lists the tied candidates and `seats_left`; `winners` only those above) | | |
| 5 | Blank votes | 200 (`blank` counted, in `cast`, in the denominator) | | |
| 6 | A group ballot | 200 (`groups` named; counts of its voters only) | | |
| 7 | The recorded votes differ from the voters who voted on the ballot | 200 (`check.ok` false) | | |
| 8 | An election with no vote | 200 (zeros, `winners` empty) | | |
| 9 | A draft, scheduled or open election | 409 | `results_not_available` | |
| 10 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 11 | Not signed in, suspended institution, another method | 401, 403, 405 | the shared codes | |

## Responses

### 200

```json
{
  "data": {
    "election": { "id": "9f2c1d3e-6a40-4b8a-8c11-2d5e7f0a9b34", "title": "Conseil des élèves 2026", "status": "closed" },
    "ballots": [
      {
        "id": "7a1c0b5e-2d4f-4e11-8d3a-0b9c6e4f1a22",
        "title": "Président(e)",
        "seats": 1,
        "scope": "general",
        "groups": [],
        "eligible": 300,
        "voted": 287,
        "cast": 287,
        "blank": 12,
        "candidates": [
          { "id": "c2b8e0a4-9d6f-4c3b-a1e7-5f0d3b6a8c91", "first_name": "Nadège", "last_name": "Pierre-Louis", "party": "Avenir Étudiant", "votes": 150, "percentage": 52.3 }
        ],
        "winners": ["c2b8e0a4-9d6f-4c3b-a1e7-5f0d3b6a8c91"],
        "tie": null,
        "check": { "recorded": 287, "expected": 287, "ok": true },
        "note": null
      }
    ]
  }
}
```

`candidates` are ordered by votes descending, then by the election's order. `tie` is `null` or `{ "candidates": [ids],
"seats_left": n }`. `percentage` is `votes` over `cast`, one decimal. A general ballot is never split by group.

## Notes

Read from `votes` and `vote_choices` only; `vote_audit` is never read (FR-SEC-09). The election's `results_display`
setting applies to the public page in slice 16, not here.
