# GET /api/v1/institution

The profile of the signed-in user's institution.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-02 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

No path parameter, no query parameter, no body. The institution is always the signed-in
user's own; there is no way to name another.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Owner signed in | 200 | | |
| 2 | Manager signed in | 200 | | |
| 3 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 4 | Signed in as a platform admin (no institution) | 403 | `forbidden` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | Another method than GET, HEAD, PATCH | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

```json
{
  "data": {
    "id": "b3a1c6a2-1f0e-4a52-8a4e-6a2f6f7d9c10",
    "name": "Collège Étoile du Matin",
    "type": "school",
    "description": "École secondaire de Port-au-Prince.",
    "address": "12 rue des Palmistes",
    "city": "Port-au-Prince",
    "phone": "+509 2222 0000",
    "contact_email": "direction@etoile.example",
    "timezone": "America/Port-au-Prince",
    "language": "fr",
    "logo": {
      "sm": "/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-64.webp",
      "md": "/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-160.webp",
      "lg": "/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-480.webp"
    }
  }
}
```

- `id` is the institution's public identifier (the UUID used in its public address).
- A field never filled is `null`; `logo` is `null` when there is none.
- `type` is `school`, `university`, `association` or `other`; `language` is `fr` or `en`.
- No numeric key, no `suspended_at`, no file name other than the logo's UUID appears.

### 401, 403, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

- A manager may read the profile (the shell shows the institution's name and logo); only an
  owner may change it ([PATCH /institution](PATCH-institution.md)).
- `GET /auth/me` keeps its short `institution` object (`id`, `name`, `type`).
