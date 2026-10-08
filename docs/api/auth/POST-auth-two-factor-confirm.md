# POST /api/v1/auth/two-factor/confirm

Turns two-factor authentication on, with a first code from the authenticator application, and
returns the recovery codes.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-04, NFR-SEC-01, NFR-SEC-05 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | 10 requests per minute per user (shared with setup, disable and recovery codes) |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| code | string | yes | 6 digits (spaces ignored), the current code of the authenticator application for the secret issued by setup. The code of the previous and of the next 30-second period are also accepted |

```json
{ "code": "492039" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A right code after a setup | 200 | | |
| 2 | `code` missing | 422 | `validation_failed` (`code: required`) | |
| 3 | `code` wrong, not 6 digits, or already used | 422 | `validation_failed` (`code: invalid`) | |
| 4 | No setup was started | 409 | `two_factor_not_started` | |
| 5 | Two-factor is already turned on | 409 | `two_factor_already_enabled` | |
| 6 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 8 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 9 | Body is not valid JSON | 400 | `malformed_request` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

```json
{ "data": { "recovery_codes": ["k3m9x-4tq7a", "w8d2n-p5r6c", "…six more…"] } }
```

Eight codes, each ten lowercase letters and digits with a dash in the middle. **They are shown
only here and in [the renewal](POST-auth-two-factor-recovery-codes.md); the server keeps only a
keyed hash of each.** A code works once.

### 422 — scenario 3

```json
{ "error": { "code": "validation_failed", "message": "Les données envoyées ne sont pas valides.", "fields": { "code": ["invalid"] } } }
```

### 409, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Side effects

- `two_factor_confirmed_at` is set; from the next sign-in a code is asked.
- The period of the code that was accepted is stored, so the same code cannot be used again
  (to confirm, or at sign-in).
- Sessions already open, including this one, stay open.

## Notes

A wrong code does not end the setup: the person can try again, within the rate limit.
