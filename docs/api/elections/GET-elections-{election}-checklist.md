# GET /api/v1/elections/{election}/checklist

What stops an election from being scheduled and what to look at first: the checklist of the summary page (screen A05).

| | |
|---|---|
| Slice | 09 |
| Requirements | FR-ELEC-04, FR-BAL-06 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A draft that is ready | 200 (`can_schedule` true, no blocker) | | |
| 2 | A draft with no ballot, a ballot with no candidate, or no voter | 200 (`can_schedule` false, one blocker each) | | |
| 3 | A draft with warnings only | 200 (`can_schedule` true, warnings listed) | | |
| 4 | An election that is not a draft | 200 (`can_schedule` false, the list is still computed) | | |
| 5 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 6 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 8 | Too many requests | 429 | `too_many_attempts` | |
| 9 | Another method than GET, HEAD | 405 | `method_not_allowed` | |

## Responses

### 200

```json
{ "data": {
  "status": "draft",
  "can_schedule": false,
  "counts": { "ballots": 3, "candidates": 7, "voters": 312, "groups": 8 },
  "blockers": [
    { "code": "ballot_without_candidates", "ballot": { "id": "0b0e5b9a-2f43-4c1d-9a55-8d1f6a7c2e10", "title": "Secrétaire" } }
  ],
  "warnings": [
    { "code": "ballot_single_candidate", "ballot": { "id": "5c2d8a13-7e04-4f19-b6a2-91d03c7e4a58", "title": "Président(e)" } },
    { "code": "group_ballot_few_voters", "ballot": { "id": "d3f7a0b2-1c58-4e6d-8a91-2b7c4e9f0a16", "title": "Délégué 6e A" }, "count": 3 },
    { "code": "voters_without_ballot", "count": 5 }
  ]
} }
```

| Code | Kind | Meaning |
|---|---|---|
| `no_ballots` | blocker | The election has no ballot |
| `ballot_without_candidates` | blocker | A ballot has no candidate (`ballot` names it) |
| `no_voters` | blocker | The election has no voter |
| `ballot_single_candidate` | warning | A ballot has exactly one candidate |
| `group_ballot_no_voters` | warning | A group ballot has no eligible voter |
| `group_ballot_few_voters` | warning | A group ballot has 1 to 4 eligible voters (`count`), FR-BAL-06 |
| `voters_without_ballot` | warning | Voters eligible for no ballot (`count`, FR-BAL-05): a voter with no group when every ballot is a group ballot, or in no covered group |

Items are in a fixed order: blockers by the order above then ballot position; warnings the same. `can_schedule` is true
when the status is `draft` and there is no blocker.

### 404, 401, 403, 429, 405

The shared error shape of [API conventions](../README.md).
