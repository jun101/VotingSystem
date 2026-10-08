# GET /api/v1/users

The users of the signed-in owner's institution, including the owner themself. A removed
user is not listed.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-03 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

Query parameters: `page` and `per_page` as in [API conventions](../README.md) (25 by
default, 100 at most). Order: owners first, then managers, each by name (case and accents
ignored), then by email.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Owner signed in | 200 | | |
| 2 | Another institution has users too | 200 (only this institution's users) | | |
| 3 | A removed user exists | 200 (not listed, not counted) | | |
| 4 | The user is a manager | 403 | `forbidden` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | `per_page` above 100 or below 1, `page` below 1 | 422 | `validation_failed` (`per_page: between`) | |
| 8 | Another method than GET, HEAD | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{
  "data": [
    {
      "id": "6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa",
      "name": "Marie-Claire Jean",
      "email": "mc.jean@etoile.example",
      "role": "owner",
      "email_verified": true,
      "last_login_at": "2026-10-07T14:03:00Z",
      "is_you": true
    },
    {
      "id": "0b0e5d2c-3c43-4d51-96a9-0d7c4d9d8f11",
      "name": "Patrick Lafleur",
      "email": "p.lafleur@etoile.example",
      "role": "manager",
      "email_verified": true,
      "last_login_at": null,
      "is_you": false
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 2 }
}
```

`role` is `owner` or `manager`. `last_login_at` is `null` before the first sign-in.
`is_you` is true for the signed-in user. No numeric key, hash or secret appears.

### 403, 401, 422, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

The users table has no two-factor information on this screen yet: slice 04b adds
`two_factor_enabled` to the resource.
