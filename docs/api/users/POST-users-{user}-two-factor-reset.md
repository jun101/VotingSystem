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
| code | string | one of the two, only if the owner has two-factor on | The owner's current code |
| recovery_code | string | one of the two, only if the owner has two-factor on | One of the owner's unused recovery codes |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Turn off a manager's two-factor | 204 | | |
| 2 | Turn off another owner's two-factor | 204 | | |
| 3 | `user` is the caller | 409 | `cannot_reset_self` | |
| 3a | `password` missing | 422 | `validation_failed` (`password: required`) | |
| 3b | `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 3c | The 5th wrong password in 15 minutes for this user (counted across these routes, the owner reset and sign-in) | 401 | `unauthenticated` (the session ends) | |
| 3c+ | Any later attempt while the count is at 5 or more | 429 | `too_many_attempts` (`Retry-After`; the session is kept) | |
| 3d | The owner has two-factor on and sends neither `code` nor `recovery_code` | 422 | `validation_failed` (`code: required`) | |
| 3e | The owner's second factor is wrong, already used or malformed | 422 | `validation_failed` (`code: invalid` or `recovery_code: invalid`) | |
| 3f | 5 wrong second factors in the last 15 minutes for the owner's account | 429 | `too_many_attempts` (`Retry-After`) | |
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
Scenario 5 gives the same body and headers for every cause. Order of the checks: 404, 403, the body (password required; the owner's code required if the owner has two-factor on, **unless `user` is the caller**), the password lockout (429), the password (422, counted), the 409 for oneself (so a reset aimed at oneself never asks for a second factor: it is refused anyway), the owner's second-factor lockout (429), the owner's second factor (422, counted), then the 409 for "not enabled".

## Notes

- The owner's password is asked, like for their own factor, so that a stolen owner session cannot strip
  the second factor of every other user. Wrong passwords count as described in the section below.
- An owner cannot turn off their own through this endpoint: they use
  [disable](../auth/POST-auth-two-factor-disable.md).
- **The last owner locked out has no owner to ask.** The platform operator runs
  `php artisan auth:reset-two-factor {email}` on the server (see the brief of slice 04b). That is
  the only path outside the web application.

## Password failures

Wrong passwords on this endpoint, on the other settings routes of slice 04b, on the owner's reset and at
[POST /auth/login](../auth/POST-auth-login.md) (from any address) feed **one counter per account**: 5 in 15
minutes, a fixed window that starts at the first failure.

- The fifth wrong password on one of the settings routes ends the session: 401 `unauthenticated`.
- While the count is 5 or more, these routes answer **429** `too_many_attempts` with `Retry-After`, whatever
  the password, and the session is kept.
- Sign-in itself is never refused because of this counter, and a successful sign-in does not clear it (a
  person who guessed the password gets no fresh start). A right password on these routes clears it while it
  is below 5.
