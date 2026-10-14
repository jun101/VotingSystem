# POST /api/v1/elections/{election}/parties

Adds a party to a draft election.

| | |
|---|---|
| Slice | 06b (the logo comes in 06d) |
| Requirements | FR-CAND-01 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| name | string | Required on creation. 1 to 100 characters after trimming. Unique in the election, ignoring case and surrounding spaces; accents count (`Unité` and `Unite` are two names) |
| acronym | string, null | Optional. 1 to 15 characters after trimming; blank is stored as `null` |
| colour | string | Required on creation. `#` and six hexadecimal digits, any case; stored and output in upper case |

```json
{ "name": "Avenir Étudiant", "acronym": "AE", "colour": "#5468D4" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `name` and `colour` | 201 |  | |
| 2 | Every field | 201 |  | |
| 3 | `name` missing, blank or longer than 100 | 422 | `validation_failed` (`name: required`, `name: max`) | |
| 4 | `name` already used in this election (case and spaces ignored, accents not) | 422 | `validation_failed` (`name: unique`) | |
| 5 | `acronym` longer than 15 | 422 | `validation_failed` (`acronym: max`) | |
| 6 | `colour` missing or not `#RRGGBB` | 422 | `validation_failed` (`colour: required`, `colour: hex_colour`) | |
| 7 | The election already has 30 parties | 409 | `party_limit_reached` | |
| 8 | The election is not a draft | 409 | `election_not_editable` | |
| 9 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 10 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 11 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 12 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 13 | Body is not valid JSON | 400 | `malformed_request` | |
| 14 | Too many requests | 429 | `too_many_attempts` | |
| 15 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 and 2

The party resource of [GET /elections/{election}/parties](GET-elections-{election}-parties.md), one item, not
wrapped in a list. Header `Location` is `/api/v1/parties/{id}`.

### 409 — scenarios 7 and 8

```json
{ "error": { "code": "party_limit_reached", "message": "Une élection ne peut pas avoir plus de 30 partis." } }
```

`election_not_editable` as in [PATCH /elections/{election}](../elections/PATCH-elections-{election}.md).

### 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), then the body (422),
then the state (409; scenario 8 before 7).

## Notes

The unique check and the unique index (`election_id`, `name_key`) both exist: two simultaneous requests with the same
name end in one 201 and one 422, never a 500.
