# POST /api/v1/auth/two-factor/disable

Turns the signed-in user's own two-factor authentication off.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-04 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | 10 requests per minute per user (shared with setup, confirm and recovery codes) |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| password | string | yes | The user's current password |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Right password, two-factor is turned on | 204 | | |
| 2 | `password` missing | 422 | `validation_failed` (`password: required`) | |
| 3 | `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 4 | Two-factor is not turned on (a setup that was never confirmed counts as not turned on) | 409 | `two_factor_not_enabled` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Body is not valid JSON | 400 | `malformed_request` | |
| 9 | Too many requests | 429 | `too_many_attempts` | |
| 10 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body. The secret, the recovery codes, the confirmation time and the stored period are cleared;
the next sign-in asks for the password only.

### 409 — scenario 4

```json
{ "error": { "code": "two_factor_not_enabled", "message": "La double authentification n'est pas activée." } }
```

### 422, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
