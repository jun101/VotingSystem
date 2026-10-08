# DELETE /api/v1/users/{user}/two-factor

An owner turns off another user's two-factor authentication, for a person who has lost both
their device and their recovery codes. The person then signs in with their password and can set
it up again.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-03, FR-INST-04 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| user | UUID | A user of the caller's institution, not removed, and not the caller |

No query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Turn off a manager's two-factor | 204 | | |
| 2 | Turn off another owner's two-factor | 204 | | |
| 3 | `user` is the caller | 409 | `cannot_reset_self` | |
| 4 | The user has no two-factor turned on | 409 | `two_factor_not_enabled` | |
| 5 | `user` is not a UUID, does not exist, is removed, belongs to another institution, or is a platform admin | 404 | `not_found` | |
| 6 | The user is a manager | 403 | `forbidden` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 10 | Another method than DELETE | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body. The user's secret, recovery codes, confirmation time and stored period are cleared.
Their open sessions stay open; their next sign-in asks for the password only.

### 409 — scenarios 3 and 4

```json
{ "error": { "code": "cannot_reset_self", "message": "Utilisez votre page « Mon compte » pour désactiver votre propre double authentification." } }
```

(`two_factor_not_enabled` as in [disable](../auth/POST-auth-two-factor-disable.md).)

### 404, 403, 401, 419, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
Scenario 5 gives the same body and headers for every cause; the 404 comes before the 403 and the 409.

## Notes

- An owner cannot turn off their own through this endpoint (it would skip the password): they use
  [disable](../auth/POST-auth-two-factor-disable.md).
- **The last owner locked out has no owner to ask.** The platform operator runs
  `php artisan auth:reset-two-factor {email}` on the server (see the brief of slice 04b). That is
  the only path outside the web application.
