# POST /api/v1/auth/accept-invitation

The invited person opens the link of the email, chooses a name and a password, and gets an
account in the institution that invited them, signed in.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-03, NFR-SEC-02, NFR-SEC-04, NFR-SEC-05 |
| Caller | Public |
| Rate limit | 10 requests per hour per IP address |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| token | string | yes | The token of the link |
| name | string | yes | 1 to 150 characters after trimming |
| password | string | yes | 12 to 128 characters; not equal to the invited email address (same rules as [register](POST-auth-register.md)) |

```json
{ "token": "…64 characters…", "name": "Jean Pierre", "password": "un mot de passe long" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid token, name and password | 200 | | |
| 2 | `token`, `name` or `password` missing | 422 | `validation_failed` (`name: required`) | |
| 3 | `name` empty or longer than 150 | 422 | `validation_failed` (`name: required`, `name: max`) | |
| 4 | `password` shorter than 12 or longer than 128 | 422 | `validation_failed` (`password: min`, `password: max`) | |
| 5 | `password` equals the invited email address | 422 | `validation_failed` (`password: same_as_email`) | |
| 6 | Unknown token, cancelled invitation, or already accepted | 404 | `not_found` | |
| 7 | The invitation has expired | 410 | `expired` | |
| 8 | The address has since become a user (accepted elsewhere, or registered) | 409 | `email_taken` | |
| 9 | The inviting institution is suspended | 403 | `institution_suspended` | |
| 10 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 11 | Body is not valid JSON | 400 | `malformed_request` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

The same body as [GET /auth/me](GET-auth-me.md): the new user, `email_verified` true, with
the invited `role` and the inviting institution. The session id is regenerated.

### 410 — scenario 7

```json
{ "error": { "code": "expired", "message": "Cette invitation a expiré. Demandez-en une nouvelle." } }
```

### 409 — scenario 8

```json
{ "error": { "code": "email_taken", "message": "Cette adresse est déjà utilisée par un compte." } }
```

### 404, 403, 422, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
Scenario 6 gives the same body and headers for every cause.

## Side effects

- A user is created: `email_verified_at` set (the link proved the address), `language` the
  institution's default, `role` the invited one, Argon2id password. `last_login_at` is set.
- If the browser already holds a session (a user of this or another institution), that session
  ends and is replaced by the new user's. Nothing of the former user is kept or changed.
- The invitation is marked accepted and can no longer be used; the token is single-use.
- Order of the checks: the token (404), its expiry (410), the body's rules (422), the
  institution's state (403), then the address still being free (409). A bad body never
  reveals whether a token is valid, because an unknown token answers 404 before any 422.

## Notes

- The lookup of the token happens before anyone is signed in; it removes the tenant scope
  on purpose, with a comment, like the other pre-sign-in flows.
- A removed user's address is free again (see [DELETE /users/{user}](../users/DELETE-users-{user}.md)).
