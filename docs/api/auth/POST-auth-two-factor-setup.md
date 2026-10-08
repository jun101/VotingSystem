# POST /api/v1/auth/two-factor/setup

Starts turning on two-factor authentication: issues a new secret for the signed-in user. It
is not active until [confirmed](POST-auth-two-factor-confirm.md).

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-04, NFR-SEC-01, NFR-SEC-05 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | 10 requests per minute per user (shared with confirm, disable and recovery codes) |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| password | string | yes | The user's current password |

```json
{ "password": "un mot de passe long" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Right password, two-factor not turned on | 200 | | |
| 2 | A setup was already started and not confirmed | 200 (a new secret replaces the old) | | |
| 3 | `password` missing | 422 | `validation_failed` (`password: required`) | |
| 4 | `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 4b | The 5th wrong password in 15 minutes for this user (counted across these routes, the owner reset and sign-in) | 401 | `unauthenticated` (the session ends) | |
| 4b+ | Any later attempt while the count is at 5 or more | 429 | `too_many_attempts` (`Retry-After`; the session is kept) | |
| 5 | Two-factor is already turned on | 409 | `two_factor_already_enabled` | |
| 6 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 8 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 9 | Body is not valid JSON | 400 | `malformed_request` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

```json
{
  "data": {
    "secret": "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP",
    "otpauth_url": "otpauth://totp/New%20Voting%20System:marie%40flamboyants.example?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=New%20Voting%20System&algorithm=SHA1&digits=6&period=30"
  }
}
```

The secret is 32 base32 characters (160 bits); the link is the standard one an authenticator
application reads from a QR code. The web page draws the QR code in the browser, so the
secret never goes to another service.

### 409 — scenario 5

```json
{ "error": { "code": "two_factor_already_enabled", "message": "La double authentification est déjà activée." } }
```

### 422, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table. A wrong
password counts like any other request for the rate limit.

## Side effects

The secret is stored encrypted; `two_factor_confirmed_at` stays `null`, so sign-in does not ask
for a code. Nothing is logged but the request id and the outcome.

## Notes

The password is asked again so that a stolen open session cannot change the second factor.

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
