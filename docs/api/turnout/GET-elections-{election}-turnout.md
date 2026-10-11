# GET /api/v1/elections/{election}/turnout

How many voters have voted, in total, by group and over time. Never what they voted.

| | |
|---|---|
| Slice | 14 |
| Requirements | FR-RES-01, FR-SEC-03, FR-SEC-04 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per minute per user, shared with the dashboard |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

No body. Query: `bucket` (optional, `hour` or `day`; default by the election's length).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An open election with some votes | 200 | | |
| 2 | An election with no voter or no vote | 200 (zeros, empty series) | | |
| 3 | Voters with no group | 200 (a line with `group` `null`, last) | | |
| 4 | A draft or scheduled election | 200 (zeros) | | |
| 5 | A closed, published or archived election | 200 (the same numbers) | | |
| 6 | `bucket` not `hour` or `day` | 422 | `validation_failed` (`bucket: invalid`) | |
| 7 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 8 | Not signed in, suspended institution, another method | 401, 403, 405 | the shared codes | |

## Responses

### 200

```json
{
  "data": {
    "eligible": 300,
    "voted": 187,
    "percentage": 62.3,
    "by_group": [
      { "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "3e année" }, "voters": 36, "voted": 30 },
      { "group": null, "voters": 4, "voted": 1 }
    ],
    "bucket": "hour",
    "over_time": [
      { "at": "2026-10-12T12:00:00Z", "voted": 12, "total": 12 },
      { "at": "2026-10-12T13:00:00Z", "voted": 20, "total": 32 }
    ]
  }
}
```

`over_time` is empty while nobody has voted. Otherwise its buckets start at the election's opening hour (or day, in the election's time zone: `at` is the UTC instant the bucket begins) and run to the current bucket, or to the
bucket of the end for an election that is over; empty buckets are listed with `voted` 0. `percentage` is `voted` over `eligible` with one decimal (0 when
nobody is eligible). Groups are ordered by name, accents and case ignored; the line with no group is last.

## Notes

The end of an election is exclusive: the last bucket is the one containing the second before it. A series holds at most 5 000 buckets; when that cuts it, the votes of the buckets left out are added to the last bucket, so its `total` is always `voted`. `percentage` is never 100.0 unless everybody has voted (a rounded 100.0 shows 99.9).

Computed from `participations` (`voted_at`) and the voters; it never reads `votes`, `vote_choices` or `vote_audit`,
and shows no per-ballot count.

The line with no group appears only when at least one voter has no group. Groups with no voter are listed with 0.
An open election's series runs to the current bucket, never past the end; a closed, published or archived one runs to
its end or its closing, whichever is earlier.
