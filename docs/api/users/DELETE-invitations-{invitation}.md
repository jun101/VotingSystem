# DELETE /api/v1/invitations/{invitation}

Cancels an invitation that has not been accepted. Its link stops working.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-03 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| invitation | UUID | An invitation of the caller's institution that has not been accepted (live or expired) |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A live invitation | 204 | | |
| 2 | An expired invitation | 204 | | |
| 3 | Not a UUID, unknown, already accepted, already cancelled, or another institution's | 404 | `not_found` | |
| 4 | The user is a manager | 403 | `forbidden` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Another method than DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body. The row is deleted.

### 404, 403, 401, 419, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
In scenario 3 every case gives the same body and headers.

## Notes

A person who opens the link of a cancelled invitation gets the same answer as for an
unknown token (404 `not_found`, see [accept](../auth/POST-auth-accept-invitation.md)).
