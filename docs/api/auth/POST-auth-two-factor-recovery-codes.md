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

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Right password, two-factor is turned on | 200 | | |
| 2 | `password` missing | 422 | `validation_failed` (`password: required`) | |
| 3 | `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 3b | The 5th wrong password in 15 minutes for this user (across these routes and the owner reset) | 401 | `unauthenticated` (the session ends) | |
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
Shown only here; the server keeps only a keyed hash of each.

### 409, 422, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table
(`two_factor_not_enabled` as in [disable](POST-auth-two-factor-disable.md)).

## Notes

Wrong passwords count for the 5-in-15-minutes failures of the user: the fifth ends the session with a 401 (scenario 3b). Same rule as [setup](POST-auth-two-factor-setup.md).
