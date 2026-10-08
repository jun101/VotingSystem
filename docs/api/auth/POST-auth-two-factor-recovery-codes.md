# POST /api/v1/auth/two-factor/recovery-codes

Replaces the signed-in user's recovery codes with eight new ones; the old ones stop working.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-04 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | 10 requests per minute per user (shared with setup, confirm and disable) |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| password | string | yes | The user's current password |
| code | string | one of the two | The current 6-digit code of the authenticator application (the code of an already used period is refused) |
| recovery_code | string | one of the two | One unused recovery code. If both are sent, `recovery_code` is the one that is checked |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Right password, two-factor is turned on | 200 | | |
| 2 | `password` missing | 422 | `validation_failed` (`password: required`) | |
| 3 | `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 3b | The 5th wrong password in 15 minutes for this user (counted across these routes, the owner reset and sign-in) | 401 | `unauthenticated` (the session ends) | |
| 3b+ | Any later attempt while the count is at 5 or more | 429 | `too_many_attempts` (`Retry-After`; the session is kept) | |
| 2a | Neither `code` nor `recovery_code` sent | 422 | `validation_failed` (`code: required`) | |
| 3c | The second factor is wrong, already used, longer than 32 characters or not a string | 422 | `validation_failed` (`code: invalid` or `recovery_code: invalid`) | |
| 3d | 5 wrong second factors in the last 15 minutes for this account (counted with the wrong codes at sign-in) | 429 | `too_many_attempts` (`Retry-After`) | |
| 4 | Two-factor is not turned on | 409 | `two_factor_not_enabled` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Body is not valid JSON | 400 | `malformed_request` | |
| 9 | Too many requests | 429 | `too_many_attempts` | |
| 10 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

The same body as [confirm](POST-auth-two-factor-confirm.md): `{ "data": { "recovery_codes": [ … eight … ] } }`.
Shown only here; the server keeps only a keyed hash of each. A `recovery_code` given as the second factor is used up, and the whole list is replaced anyway.

### 409, 422, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table
(`two_factor_not_enabled` as in [disable](POST-auth-two-factor-disable.md)).

## Notes

Wrong passwords count for the 5-in-15-minutes failures of the user: the fifth ends the session with a 401 (scenario 3b). Same rule as [setup](POST-auth-two-factor-setup.md).

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

## Second factor, and order of the checks

To turn two-factor off or to renew the recovery codes, the person gives the password **and** a current
second factor, so that a guessed password with a stolen session is not enough. Order of the checks: the body
(password required, then a code or recovery code required: 422), the password lockout (429), the password
(422, counted), the state (409 `two_factor_not_enabled`), the second-factor lockout (429), the second factor
(422, counted). A missing password is answered 422 before the lockout is looked at. A wrong second factor
counts as a wrong code at sign-in: 5 in 15 minutes for the account, shared with
[the challenge](POST-auth-two-factor-challenge.md). A password reset does not clear either count: they run out
by themselves (a person kept at 429 by someone who knows their password waits, or asks an owner).
Anyone who knows only an email address can feed the password count from sign-in and keep these routes at 429;
sign-in itself is never refused, and the person's session ends only at the fifth wrong password they type here.
