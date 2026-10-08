# POST /api/v1/users/{user}/two-factor/reset

An owner turns off another user's two-factor authentication, for a person who has lost both
their device and their recovery codes. The person then signs in with their password and can set
it up again.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-03, FR-INST-04 |
| Caller | Institution user: owner |
| Rate limit | 10 requests per minute per user (shared with the four own-settings routes) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| user | UUID | A user of the caller's institution, not removed, and not the caller |

Body, JSON:

| Field | Type | Required | Rules |
|---|---|---|---|
| password | string | yes | The **caller's** own current password |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Turn off a manager's two-factor | 204 | | |
| 2 | Turn off another owner's two-factor | 204 | | |
| 3 | `user` is the caller | 409 | `cannot_reset_self` | |
| 3a | `password` missing | 422 | `validation_failed` (`password: required`) | |
| 3b | `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 3c | The 5th wrong password in 15 minutes for the caller | 401 | `unauthenticated` (the session ends) | |
| 4 | The user has no two-factor turned on | 409 | `two_factor_not_enabled` | |
| 5 | `user` is not a UUID, does not exist, is removed, belongs to another institution, or is a platform admin | 404 | `not_found` | |
| 6 | The user is a manager | 403 | `forbidden` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 10 | Another method than POST | 405 | `method_not_allowed` | |
| 11 | Body is not valid JSON | 400 | `malformed_request` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |

## Responses

### 204 — scenarios 1 and 2

No body. The user's secret, recovery codes, confirmation time and stored period are cleared.
Their open sessions stay open; their next sign-in asks for the password only.

### 409 — scenarios 3 and 4

```json
{ "error": { "code": "cannot_reset_self", "message": "Utilisez votre page « Mon compte » pour désactiver votre propre double authentification." } }
```

(`two_factor_not_enabled` as in [disable](../auth/POST-auth-two-factor-disable.md); `422` as in setup.)

### 422, 404, 403, 401, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
Scenario 5 gives the same body and headers for every cause. Order of the checks: 404, 403, the password (422), then the 409 for oneself, then the 409 for "not enabled".

## Notes

- The owner's password is asked, like for their own factor, so that a stolen owner session cannot strip
  the second factor of every other user. A wrong password counts for the same 5-in-15-minutes failures
  as the own-settings routes ([setup](../auth/POST-auth-two-factor-setup.md)).
- An owner cannot turn off their own through this endpoint: they use
  [disable](../auth/POST-auth-two-factor-disable.md).
- **The last owner locked out has no owner to ask.** The platform operator runs
  `php artisan auth:reset-two-factor {email}` on the server (see the brief of slice 04b). That is
  the only path outside the web application.
