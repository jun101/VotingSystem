# POST /api/v1/groups/{group}/merge

Moves every voter of a group into another group of the same election, then deletes the first group.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| group | UUID | The group to merge away: of the caller's institution, its election a draft or scheduled |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| into | UUID | Required. The group that receives the voters: another group of the **same election** |

```json
{ "into": "7f0c2a1e-5b3d-4e8a-9c40-6d2b1a8f3e55" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Another group of the election | 200 (the receiving group, with its new `voters_count`; the merged group no longer exists) | | |
| 2 | A group with no voter | 200 (the group is simply removed) | | |
| 3 | `into` missing or not a UUID | 422 | `validation_failed` (`into: required`, `into: uuid`) | |
| 4 | `into` is the group itself | 422 | `validation_failed` (`into: same`) | |
| 5 | `into` is unknown, another election's or another institution's (the same answer for each) | 422 | `validation_failed` (`into: invalid`) | |
| 6 | The election is open, closed, published or archived | 409 | `election_voters_locked` | |
| 7 | Not a UUID, unknown, or another institution's group | 404 | `not_found` | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 11 | Too many requests | 429 | `too_many_attempts` | |
| 12 | Another method than POST | 405 | `method_not_allowed` | |
| 13 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 200 — scenarios 1 and 2

The group resource of [GET /elections/{election}/groups](GET-elections-{election}-groups.md): the receiving group.

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order: 404, 422, 409.

## Notes

One transaction: the election row is locked, then both groups in a fixed order; the voters are moved, the group is
deleted. A failure leaves both groups as they were.
