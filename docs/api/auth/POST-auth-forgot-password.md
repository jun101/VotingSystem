# POST /api/v1/auth/forgot-password

Sends a password reset link to an address, if it belongs to an account. The answer is the
same either way, so the endpoint does not tell whether an account exists.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-04, NFR-SEC-05 |
| Caller | Public |
| Rate limit | 5 requests per hour per IP address, and 3 per hour per email address |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| email | string | yes | valid address, at most 255 characters |

```json
{ "email": "marie@flamboyants.example" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Address of an existing user | 204 | | |
| 2 | Address of nobody | 204 | | |
| 3 | `email` missing or not an address | 422 | `validation_failed` | |
| 4 | CSRF token missing | 419 | `csrf_mismatch` | |
| 5 | Rate limit exceeded | 429 | `too_many_attempts` | |
| 6 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 204 — scenarios 1 and 2

No body, same headers, similar time.

### 422, 419, 429, 405

The shared shapes, with the codes of the table. The rate limit also answers 429 for an
address that exists and one that does not, in the same way.

## Side effects

Scenario 1 only: the user's reset token is replaced by a new one (60 minutes) and the email
is queued, in the user's language. The link is `{APP_URL}/reset-password?token=<token>`:
the token only, no email address, no id. Scenario 2 sends and writes nothing. A removed
user (`deleted_at` set) is treated as nobody.
