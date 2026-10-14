# PATCH /api/v1/voters/{voter}

Changes the fields of one voter.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-06, FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| voter | UUID | A voter of the caller's institution whose election is a draft, scheduled or open |

Body, JSON: any of `full_name`, `group`, `identifier`, `email`, `phone`, with the rules of
[POST](POST-elections-{election}-voters.md). A field that is absent keeps its value; `null` or blank clears `group`,
`identifier`, `email` and `phone`; `full_name` cannot be cleared. An empty object is allowed and changes nothing.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | One or more fields | 200 (the voter, `updated_at` moves) | | |
| 2 | `group` is the name of another group of the election, or a new name; the election is a draft or scheduled | 200 (the other group is used, or created) | | |
| 3 | `group` is `null` | 200 (`group: null`) | | |
| 4 | The same values as stored | 200 (nothing changes) | | |
| 5 | An empty object | 200 (nothing changes) | | |
| 6 | `full_name` blank or too long; `identifier` or `email` used by another voter of the election; `email`, `phone`, `group` invalid | 422 | `validation_failed` (as POST) | |
| 7 | A new group is needed and the election already has 100 groups | 409 | `group_limit_reached` | |
| 8 | The election is closed, published or archived; or it is open and `group` names a group that does not exist yet (a new group) | 409 | `election_voters_locked` | |
| 8a | The election is open, `group` is another existing group of the election (or `null`), and the voter **has not voted** | 200 (the group changes; the voter's ballots follow it) | | |
| 8b | The election is open, `group` would change the voter's group, and the voter **has voted** | 409 | `voter_has_voted` | |
| 9 | Not a UUID, unknown, or another institution's voter | 404 | `not_found` | |
| 10 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 11 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 12 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |
| 15 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 200 — scenarios 1 to 5

The voter resource of [GET /elections/{election}/voters](GET-elections-{election}-voters.md).

### 422, 409, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the body (422), then
the state (409). A voter's own identifier or email is not a duplicate of itself.

## Notes

In an open election a voter who has **not voted** may be moved to another existing group of the election, or to no group; the group decides their
ballots, and a voter in the middle of voting is told at confirmation that their ballots changed (`ballots_mismatch`, see
[POST /vote/{election}/submit](../vote/POST-vote-{election}-submit.md)). A group that does not exist yet is not created in an open election. A voter who **has voted** keeps their group
(409 `voter_has_voted`): their vote was counted under it, and the per-ballot turnout must not move. The other fields may change, and `group` may repeat the
current group's name. The check runs under the voter's row lock, the same lock a vote takes first, so a vote and a group change never cross.


The election cannot be changed through this endpoint: `election`, `id` and every `*_id` in the body are ignored.
