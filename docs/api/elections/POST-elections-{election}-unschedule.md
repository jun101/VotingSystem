# POST /api/v1/elections/{election}/unschedule

Takes a Scheduled election back to Draft, so ballots and candidates can be edited again.

| | |
|---|---|
| Slice | 09 |
| Requirements | FR-ELEC-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **scheduled** election of the caller's institution. No body |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A scheduled election | 200 (status `draft`) | | |
| 2 | A draft, open, closed, published or archived election | 409 | `election_not_scheduled` | |
| 3 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 7 | Too many requests | 429 | `too_many_attempts` | |
| 8 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

The election resource of [GET /elections/{election}](GET-elections-{election}.md) with `status: "draft"`.

### 409, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md). Order: 404, then 409.

## Notes

Voters and groups are not touched. Group ballots keep their groups.
