# DELETE /api/v1/groups/{group}

Deletes a group that nobody uses.

| | |
|---|---|
| Slice | 07 (slice 09 adds "no ballot covers the group") |
| Requirements | FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| group | UUID | A group of the caller's institution whose election is a draft or scheduled |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A group with no voter | 204 | | |
| 2 | The group has at least one voter | 409 | `group_in_use` | |
| 3 | The election is open, closed, published or archived | 409 | `election_voters_locked` | |
| 4 | Not a UUID, unknown, already deleted, or another institution's | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Too many requests | 429 | `too_many_attempts` | |
| 9 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body.

### 409 — scenarios 2 and 3

```json
{ "error": { "code": "group_in_use", "message": "Ce groupe a encore des électeurs. Déplacez-les ou fusionnez le groupe." } }
```

### 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md). Order: 404, then 409 (scenario 3 before 2).
