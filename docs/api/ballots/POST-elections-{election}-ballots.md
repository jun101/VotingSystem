# POST /api/v1/elections/{election}/ballots

Adds a ballot (a position to fill) at the end of a draft election.

| | |
|---|---|
| Slice | 06a |
| Requirements | FR-BAL-01, FR-BAL-02 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| title | string | Required. 1 to 200 characters after trimming |
| description | string, null | Optional. At most 1000 characters after trimming; blank is stored as `null` |
| seats | integer | Optional. 1 to 20. Default 1 |
| allow_blank | boolean | Optional. Default `true` |

```json
{ "title": "Secrétaire", "seats": 1, "allow_blank": true }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Only `title` | 201 (seats 1, blank vote allowed, last position) | | |
| 2 | Every field | 201 | | |
| 3 | `title` missing, blank or longer than 200 | 422 | `validation_failed` (`title: required`, `title: max`) | |
| 4 | `description` longer than 1000 | 422 | `validation_failed` (`description: max`) | |
| 5 | `seats` not an integer, below 1 or above 20; `allow_blank` not a boolean | 422 | `validation_failed` (`seats: integer`, `seats: min`, `seats: max`, `allow_blank: boolean`) | |
| 6 | The election already has 50 ballots | 409 | `ballot_limit_reached` | |
| 7 | The election is not a draft | 409 | `election_not_editable` | |
| 8 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Body is not valid JSON | 400 | `malformed_request` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 and 2

The ballot resource of [GET /elections/{election}/ballots](GET-elections-{election}-ballots.md) (one item, not
wrapped in a list), with a new `id`, `position` one after the last one (1 for the first), `candidates_count` 0.
Header `Location` is `/api/v1/ballots/{id}`.

### 409 — scenarios 6 and 7

```json
{ "error": { "code": "ballot_limit_reached", "message": "Une élection ne peut pas avoir plus de 50 postes." } }
```

```json
{ "error": { "code": "election_not_editable", "message": "Cette élection ne peut plus être modifiée." } }
```

### 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), then the body (422),
then the state (409; scenario 7 before 6).

## Side effects

- The election's `ballots_count` (in [GET /elections](../elections/GET-elections.md) and
  [GET /elections/{election}](../elections/GET-elections-{election}.md)) goes up by one.
- The election's `updated_at` is not touched.

## Notes

A ballot's scope is `general` in this slice; slice 09 adds the group scope and the endpoint to choose the groups.
