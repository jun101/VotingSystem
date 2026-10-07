# GET /api/v1/auth/me

The signed-in user and their institution. The web application reads it on every page of
the admin area, to know who is signed in and whether the email is verified.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-01 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | none |

## Request

No path parameter, no query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Signed in | 200 | | |
| 2 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 3 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 4 | Another method than GET or HEAD | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

```json
{
  "data": {
    "id": "6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa",
    "name": "Marie Joseph",
    "email": "marie@flamboyants.example",
    "role": "owner",
    "email_verified": true,
    "language": "fr",
    "institution": { "id": "b3a1c6a2-1f0e-4a52-8a4e-6a2f6f7d9c10", "name": "Collège Les Flamboyants", "type": "other" }
  }
}
```

`role` is `owner`, `manager` or `platform_admin`. `institution` is `null` for a platform
admin. No numeric key, no password hash, no secret appears.

### 401 and 403

```json
{ "error": { "code": "unauthenticated", "message": "Veuillez vous connecter." } }
```

```json
{ "error": { "code": "institution_suspended", "message": "Cet établissement est suspendu." } }
```

## Notes

This is the response resource every later endpoint reuses for "the current user".
In scenario 3 the session is also ended.
