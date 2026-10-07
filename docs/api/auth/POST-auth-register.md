# POST /api/v1/auth/register

Registers an institution and its first user, the owner, in one step. The owner is signed
in at once; a verification email is sent.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-01, NFR-SEC-02, NFR-SEC-05 |
| Caller | Public |
| Rate limit | 10 requests per hour per IP address |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| institution_name | string | yes | 1 to 150 characters, after trimming |
| name | string | yes | 1 to 150 characters, after trimming |
| email | string | yes | valid address, at most 255 characters, compared in lower case; not used by another account |
| password | string | yes | 12 to 128 characters; not equal to `email` |
| language | string | no | `fr` or `en`. Default: the language of `Accept-Language`, else `fr` |

```json
{ "institution_name": "Collège Les Flamboyants", "name": "Marie Joseph", "email": "marie@flamboyants.example", "password": "un mot de passe long", "language": "fr" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid request | 201 | | |
| 2 | A required field is missing | 422 | `validation_failed` (`institution_name: required`) | |
| 3 | `email` is not an address | 422 | `validation_failed` (`email: invalid`) | |
| 4 | `email` already belongs to an account, in any letter case | 422 | `validation_failed` (`email: taken`) | |
| 5 | `password` shorter than 12 characters | 422 | `validation_failed` (`password: min`) | |
| 6 | `password` equal to `email` | 422 | `validation_failed` (`password: same_as_email`) | |
| 7 | `language` is neither `fr` nor `en` | 422 | `validation_failed` (`language: invalid`) | |
| 8 | Body is not valid JSON | 400 | `malformed_request` | |
| 9 | CSRF token missing | 419 | `csrf_mismatch` | |
| 10 | More than 10 requests in an hour from one address | 429 | `too_many_attempts` | |
| 11 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenario 1

```json
{
  "data": {
    "id": "6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa",
    "name": "Marie Joseph",
    "email": "marie@flamboyants.example",
    "role": "owner",
    "email_verified": false,
    "language": "fr",
    "institution": { "id": "b3a1c6a2-1f0e-4a52-8a4e-6a2f6f7d9c10", "name": "Collège Les Flamboyants", "type": "other" }
  }
}
```

The session cookie is set (see [README](../README.md) and the notes below).

### 422 — scenarios 2 to 7

```json
{ "error": { "code": "validation_failed", "message": "Certains champs sont à corriger.", "fields": { "email": ["taken"] } } }
```

### 400, 419, 429, 405

The shared shapes of [the conventions](../README.md) section 3, with the codes of the table.

## Side effects

- One transaction creates the institution (type `other`, timezone `America/Port-au-Prince`,
  language as requested) and the user (role `owner`, password hashed with Argon2id,
  `email_verified_at` null). A failure leaves neither.
- A verification token is created and the verification email is queued (language of the
  user). The link is `{APP_URL}/verify-email?token=<token>`; it holds no id.
- The user is signed in: the session id is regenerated.
- The email is stored in lower case.

## Notes

- Scenario 4 tells a visitor that an address has an account. This is accepted for now:
  schools type their own address and need to know; the rate limit slows enumeration.
- The institution is created with the minimum; the profile is completed in slice 04.
