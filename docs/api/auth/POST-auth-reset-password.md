# POST /api/v1/auth/reset-password

Sets a new password with the token of the reset link.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-04, NFR-SEC-02, NFR-SEC-05 |
| Caller | Public |
| Rate limit | 10 requests per hour per IP address |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| token | string | yes | the 64-character token from the link |
| password | string | yes | 12 to 128 characters; not equal to the user's email |

```json
{ "token": "9a1d…(64 characters)", "password": "un nouveau mot de passe long" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid token and password | 204 | | |
| 2 | A field is missing | 422 | `validation_failed` (`token: required`) | |
| 3 | `password` shorter than 12 characters | 422 | `validation_failed` (`password: min`) | |
| 4 | `password` equal to the user's email | 422 | `validation_failed` (`password: same_as_email`) | |
| 5 | Token unknown, already used, or replaced by a newer one | 422 | `validation_failed` (`token: invalid`) | |
| 6 | Token known but older than 60 minutes | 410 | `expired` | |
| 7 | CSRF token missing | 419 | `csrf_mismatch` | |
| 8 | More than 10 requests in an hour from one address | 429 | `too_many_attempts` | |
| 9 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body.

### 410 — scenario 6

```json
{ "error": { "code": "expired", "message": "Ce lien a expiré. Demandez-en un nouveau." } }
```

### 422, 419, 429, 405

The shared shapes, with the codes of the table. Validation of the password is checked
before the token is consumed, so a rejected password leaves the link usable.

## Side effects

In one transaction: the password is replaced (Argon2id), the token is deleted, and every
other session of the user stops working (the session carries a hash of the password and is
ended when it no longer matches). The user is **not** signed in; the web
application sends them to the sign-in page. A person who proves ownership of the address
this way also has their email marked as verified if it was not.
