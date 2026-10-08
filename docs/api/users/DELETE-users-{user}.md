# DELETE /api/v1/users/{user}

Removes a user from the institution. The user can no longer sign in, and their next request
is refused.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-03 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| user | UUID | A user of the caller's institution who is not already removed |

No query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Remove a manager | 204 | | |
| 2 | Remove another owner while at least one owner remains | 204 | | |
| 3 | Remove oneself while another owner exists | 204 (the session ends) | | |
| 4 | Remove the only owner (oneself, or the last one left) | 409 | `last_owner` | |
| 5 | `user` is not a UUID, does not exist, is already removed, or belongs to another institution | 404 | `not_found` | |
| 6 | `user` is a platform admin | 404 | `not_found` | |
| 7 | The user is a manager | 403 | `forbidden` | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 11 | Another method than DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 to 3

No body.

### 409 — scenario 4

```json
{ "error": { "code": "last_owner", "message": "Un établissement doit garder au moins un propriétaire." } }
```

### 404, 403, 401, 419, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
Scenarios 5 and 6 answer with the same body and headers.

## Side effects

- `deleted_at` is set. The email is rewritten to `removed-{uuid}@removed.invalid`, which
  frees the address for a new invitation or sign-up. The name is kept, for the audit log.
- The user's pending email-verification and password-reset tokens are deleted.
- Their next request answers 401 `unauthenticated` (a removed user has no session), and
  signing in fails like an unknown email.
- The invitations the user sent that were **not yet accepted** are deleted, so a removed user
  cannot bring someone back in through a link they sent earlier. Invitations already accepted
  stay as a record; invitations sent by other users are untouched.
- In scenario 3 the answering request also ends the caller's own session.

## Notes

The 404 is checked before the 409: a user of another institution is never described.
