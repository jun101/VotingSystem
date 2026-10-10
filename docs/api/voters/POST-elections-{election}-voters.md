# POST /api/v1/elections/{election}/voters

Adds one voter to an election.

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-01, FR-VOT-02, FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution whose status is **draft, scheduled or open** |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| full_name | string | Required. 1 to 150 characters after trimming |
| group | string, null | Optional. The group's **name**, 1 to 100 characters after trimming. A group of this election with that name (case and surrounding spaces ignored, accents counted) is used; if there is none, it is created. Blank or `null`: no group |
| identifier | string, null | Optional. 1 to 50 characters after trimming; blank is `null`. Unique in the election, ignoring case |
| email | string, null | Optional. A valid address, at most 255 characters; blank is `null`. Stored lower-cased. Unique in the election |
| phone | string, null | Optional. At most 30 characters, digits and `+ - ( ) .` and spaces only; blank is `null` |

```json
{ "full_name": "Rose-Marie Désir", "group": "4e année", "identifier": "E-2041", "email": "rm.desir@example.ht", "phone": "+509 3712 4455" }
```

Two voters may share a full name: the identifier and the email are what tell them apart.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `full_name` only | 201 (no group) | | |
| 2 | Every field, the group already exists | 201 (the existing group is used) | | |
| 3 | Every field, the group does not exist | 201 (the group is created in the election: a draft or scheduled election only) | | |
| 4 | `full_name` missing, blank or longer than 150 | 422 | `validation_failed` (`full_name: required`, `full_name: max`) | |
| 5 | `identifier` already used in this election (case ignored) | 422 | `validation_failed` (`identifier: unique`) | |
| 6 | `email` not valid (strict: a plain `name@domain.tld` address, no quoted part, no IP address, no dotless domain), or already used in this election | 422 | `validation_failed` (`email: email`, `email: unique`) | |
| 7 | `phone` longer than 30 or with other characters | 422 | `validation_failed` (`phone: max`, `phone: invalid`) | |
| 8 | `group` longer than 100 | 422 | `validation_failed` (`group: max`) | |
| 9 | The election already has 10 000 voters | 409 | `voter_limit_reached` | |
| 10 | A new group is needed and the election already has 100 groups | 409 | `group_limit_reached` | |
| 11 | The election is closed, published or archived; or it is open and the group does not exist | 409 | `election_voters_locked` | |
| 12 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 13 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 14 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 15 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 16 | Too many requests | 429 | `too_many_attempts` | |
| 17 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |
| 18 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 201 — scenarios 1 to 3

The voter resource of [GET /elections/{election}/voters](GET-elections-{election}-voters.md), one item, not wrapped in
a list. Header `Location` is `/api/v1/voters/{id}`.

### 409 — scenarios 9 to 11

```json
{ "error": { "code": "voter_limit_reached", "message": "Une élection ne peut pas avoir plus de 10 000 électeurs." } }
```

`election_voters_locked`: the election is closed, published or archived, so its voters and groups are read-only; an open election takes voters in the groups it already has, and no new group.

### 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), then the body (422),
then the state (409; scenario 11 before 9 and 10).

## Notes

The identifier and email checks and the unique indexes `(election_id, identifier)` and `(election_id, email)` both exist:
two simultaneous requests with the same value end in one 201 and one 422, never a 500. A new group and the voter are
written in one transaction that locks the election row.
