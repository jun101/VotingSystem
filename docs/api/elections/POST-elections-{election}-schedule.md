# POST /api/v1/elections/{election}/schedule

Confirms the setup: a draft becomes Scheduled. Ballots, candidates and parties are locked; voters and groups stay
editable (FR-ELEC-03).

| | |
|---|---|
| Slice | 09 |
| Requirements | FR-ELEC-03, FR-ELEC-04 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

Body, JSON (optional):

| Field | Type | Rules |
|---|---|---|
| accept_warnings | boolean | Default `false`. Must be `true` when the [checklist](GET-elections-{election}-checklist.md) has warnings |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | No blocker, no warning | 200 (status `scheduled`) | | |
| 2 | Warnings and `accept_warnings: true` | 200 | | |
| 3 | Warnings and `accept_warnings` absent or `false` | 409 | `checklist_warnings` (the warnings in `error.items`) | |
| 4 | At least one blocker | 409 | `checklist_blocked` (the blockers in `error.items`) | |
| 5 | The end of the election is not in the future | 409 | `election_period_over` | |
| 6 | The election is not a draft | 409 | `election_not_editable` | |
| 7 | `accept_warnings` is not a boolean | 422 | `validation_failed` (`accept_warnings: boolean`) | |
| 8 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than the ones this path takes | 405 | `method_not_allowed` | |
| 14 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 200 — scenarios 1 and 2

The election resource of [GET /elections/{election}](GET-elections-{election}.md) with `status: "scheduled"`.

### 409 — scenarios 3 to 6

```json
{ "error": { "code": "checklist_blocked", "message": "L'élection n'est pas prête.",
   "items": [ { "code": "no_voters" } ] } }
```

`items` has the shape of `blockers` or `warnings` of the checklist. Order of the checks: the record (404), the body (422),
then the state (409): `election_not_editable`, `election_period_over`, `checklist_blocked`, `checklist_warnings`.

### 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md).

## Notes

The checklist is computed again under the election's row lock, so a change made meanwhile counts. The opening at the start
time is the work of slice 13. Locked while scheduled: ballots, candidates, parties and the election's own fields answer
409 `election_not_editable`; call [unschedule](POST-elections-{election}-unschedule.md) to edit them.
