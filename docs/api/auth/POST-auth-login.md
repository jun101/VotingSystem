# POST /api/v1/auth/login

Signs a user in with email and password.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-04 (sign-in part), FR-INST-06, NFR-SEC-02, NFR-SEC-04, NFR-SEC-05 |
| Caller | Public |
| Rate limit | 10 requests per minute per IP address, and 5 failed attempts per minute for one email address from one IP address |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| email | string | yes | compared in lower case |
| password | string | yes | |

```json
{ "email": "marie@flamboyants.example", "password": "un mot de passe long" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Right email and password, email verified or not | 200 | | |
| 2 | `email` or `password` missing | 422 | `validation_failed` (`email: required`) | |
| 3 | Unknown email | 401 | `invalid_credentials` | |
| 4 | Wrong password | 401 | `invalid_credentials` | |
| 5 | The user was removed (`deleted_at` set) | 401 | `invalid_credentials` | |
| 6 | The user's institution is suspended | 403 | `institution_suspended` | |
| 7 | CSRF token missing | 419 | `csrf_mismatch` | |
| 8 | Too many requests or failed attempts | 429 | `too_many_attempts` | |
| 9 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

The same body as [GET /auth/me](GET-auth-me.md). The session id is regenerated.

### 401 — scenarios 3 to 5

```json
{ "error": { "code": "invalid_credentials", "message": "Courriel ou mot de passe incorrect." } }
```

The three scenarios give the same answer, in about the same time (a password hash is
checked against a dummy value when the user does not exist).

### 403 — scenario 6

```json
{ "error": { "code": "institution_suspended", "message": "Cet établissement est suspendu." } }
```

Given only when the password is right.

### 422, 419, 429, 405

The shared shapes, with the codes of the table.

## Side effects

`last_login_at` is set. A hash that needs a rehash (cost parameters changed) is rewritten.
A failed attempt is counted by the limiter and writes nothing in the database.

## Notes

A platform admin signs in here too; their `institution` is `null`.
Two-factor authentication is added in slice 04.

When the hashing cost parameters are raised, the first sign-in of a user rewrites their hash;
the user's other sessions then end (they carry a hash of the old password hash), and the person
signs in again there. Accepted.
