# POST /api/v1/auth/two-factor-challenge

The second step of signing in for a person who has two-factor authentication turned on: gives a
code from the authenticator application, or one recovery code, and receives the session.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-04, NFR-SEC-01, NFR-SEC-04, NFR-SEC-05 |
| Caller | Public, with a pending sign-in in the session ([login](POST-auth-login.md) scenario 10) |
| Rate limit | 10 requests per minute per IP address; and 5 wrong codes end the pending sign-in |

## Request

One of the two fields:

| Field | Type | Rules |
|---|---|---|
| code | string | 6 digits (spaces ignored): the current code, or that of the previous or the next 30-second period. A period already used (at sign-in or at confirmation) is refused |
| recovery_code | string | One of the eight recovery codes, in any letter case, with or without the dash. Used up by a success |

If both are sent, `recovery_code` is the one that is checked.

```json
{ "code": "492039" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A right `code` | 200 | | |
| 2 | A right `recovery_code` | 200 | | |
| 3 | Neither field sent | 422 | `validation_failed` (`code: required`) | |
| 4 | `code` wrong, not 6 digits, or an already used period | 422 | `validation_failed` (`code: invalid`) | |
| 5 | `recovery_code` wrong or already used | 422 | `validation_failed` (`recovery_code: invalid`) | |
| 6 | No pending sign-in, or it is older than 5 minutes, or it was ended by 5 wrong codes | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since the first step | 403 | `institution_suspended` | |
| 8 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 9 | Body is not valid JSON | 400 | `malformed_request` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

The same body as [GET /auth/me](GET-auth-me.md). The session id is regenerated, the pending
sign-in is removed, `last_login_at` is set. A recovery code that worked is deleted.

### 422 — scenarios 4 and 5

```json
{ "error": { "code": "validation_failed", "message": "Les données envoyées ne sont pas valides.", "fields": { "code": ["invalid"] } } }
```

Each of these counts as a wrong code. The fifth ends the pending sign-in: the next request, even
with a right code, answers 401 (scenario 6) and the person starts again with the password.

### 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Side effects

- On success, the period of a `code` is stored (no second use); `last_login_at` is set; a hash
  that needs a rehash is already rewritten at the first step.
- Nothing is logged but the request id and the outcome (`success`, `invalid`, `throttled`,
  `expired`). Never a code, a secret or an address.

## Notes

- The first step already checked the password and the institution. The challenge only ever
  grants a session for the user that step recorded in this browser's session; there is no way
  to name another user.
- A platform admin with two-factor goes through the same two steps.
- Signing in by any other route does not exist: password reset does not sign anyone in.
