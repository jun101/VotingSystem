# PATCH /api/v1/auth/me

Changes the signed-in user's own settings. In slice 03 the only setting is the language of
the admin area (the FR/EN switch of the side menu). Slice 04 adds the name and the other
profile fields to this same endpoint.

| | |
|---|---|
| Slice | 03 |
| Requirements | NFR-UX-01 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | none |

## Request

Path parameters: none. Body, JSON:

| Field | Type | Rule |
|---|---|---|
| `language` | string | Required. `fr` or `en` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid language | 200 | | |
| 2 | Same language as now | 200 | | |
| 3 | `language` missing | 422 | `validation_failed` (`fields.language`: `required`) | |
| 4 | `language` is not `fr` or `en` | 422 | `validation_failed` (`fields.language`: `in`) | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Body is not valid JSON | 400 | `malformed_request` | |
| 9 | Another method than GET, HEAD or PATCH | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

The same body as [GET /auth/me](GET-auth-me.md), with the new `language`.

```json
{
  "data": {
    "id": "6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa",
    "name": "Marie Joseph",
    "email": "marie@flamboyants.example",
    "role": "owner",
    "email_verified": true,
    "language": "en",
    "institution": { "id": "b3a1c6a2-1f0e-4a52-8a4e-6a2f6f7d9c10", "name": "Collège Les Flamboyants", "type": "other" }
  }
}
```

### 422 — scenarios 3 and 4

```json
{ "error": { "code": "validation_failed", "message": "Les données envoyées ne sont pas valides.", "fields": { "language": ["in"] } } }
```

### 401, 403, 419, 400, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

- Only the signed-in user's own row changes; there is no way to name another user.
- A field other than `language` is ignored (unknown fields are never an error), so a
  request cannot change `role`, `email` or the institution.
- The change also applies to the emails sent to this user from then on.
- Nothing is logged but the request id and the outcome.
