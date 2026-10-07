# POST /api/v1/auth/verify-email

Verifies an email address with the token of the link sent by email. The person may open
the link in another browser than the one they registered with, so it needs no session.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-01, NFR-SEC-05 |
| Caller | Public |
| Rate limit | 10 requests per minute per IP address |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| token | string | yes | the 64-character token from the link |

```json
{ "token": "4f3c…(64 characters)" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid token | 204 | | |
| 2 | `token` missing | 422 | `validation_failed` (`token: required`) | |
| 3 | Token unknown, already used, or replaced by a newer one | 422 | `validation_failed` (`token: invalid`) | |
| 4 | Token known but older than 24 hours | 410 | `expired` | |
| 5 | CSRF token missing | 419 | `csrf_mismatch` | |
| 6 | More than 10 requests in a minute from one address | 429 | `too_many_attempts` | |
| 7 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body.

### 410 — scenario 4

```json
{ "error": { "code": "expired", "message": "Ce lien a expiré. Demandez-en un nouveau." } }
```

### 422, 419, 429, 405

The shared shapes, with the codes of the table.

## Side effects

- The user's `email_verified_at` is set to the server time, and the token is deleted, in
  one transaction.
- The token is looked up by the SHA-256 hash of the value received, with a constant-time
  comparison of the hashes.
- An expired token is not deleted by this call.

## Notes

No sign-in happens here. A person who is already signed in sees the change on their next
`GET /auth/me`.
