# DELETE /api/v1/institution/logo

Removes the institution's logo. The institution is then shown with its initials.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-02 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

No path parameter, no query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A logo exists | 204 | | |
| 2 | There is no logo | 204 | | |
| 3 | The user is a manager | 403 | `forbidden` | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 7 | Another method than PUT, DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body. `institutions.logo_file` is set to `null` and the three files are deleted.

### 403, 401, 419, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

Deleting is idempotent: a second call also answers 204.
