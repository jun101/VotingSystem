# POST /api/v1/auth/verify-email/resend

Sends a new verification email to the signed-in user. The previous link stops working.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-01, NFR-SEC-05 |
| Caller | Institution user, signed in (verified or not) |
| Rate limit | 3 requests per minute per user |

## Request

No path parameter, no query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Signed in, email not verified | 204 | | |
| 2 | Not signed in | 401 | `unauthenticated` | |
| 3 | Email already verified | 409 | `already_verified` | |
| 4 | CSRF token missing | 419 | `csrf_mismatch` | |
| 5 | More than 3 requests in a minute from one user | 429 | `too_many_attempts` | |
| 6 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body.

### 409 — scenario 3

```json
{ "error": { "code": "already_verified", "message": "Votre courriel est déjà vérifié." } }
```

### 401, 419, 429, 405

The shared shapes, with the codes of the table.

## Side effects

The user's existing token is replaced by a new one (24 hours) and the email is queued,
in the user's language.
