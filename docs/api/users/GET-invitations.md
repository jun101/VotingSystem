# GET /api/v1/invitations

The invitations of the signed-in owner's institution that have not been accepted, live or
expired.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-03 |
| Caller | Institution user: owner |
| Rate limit | none |

## Request

Query parameters: `page` and `per_page` as in [API conventions](../README.md). Order: newest
first.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Owner signed in, with live and expired invitations | 200 | | |
| 2 | An accepted invitation exists | 200 (not listed) | | |
| 3 | Another institution has invitations too | 200 (only this institution's) | | |
| 4 | The user is a manager | 403 | `forbidden` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | `per_page` or `page` out of range | 422 | `validation_failed` (`per_page: between`) | |
| 8 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{
  "data": [
    {
      "id": "9d3b1f52-6a0a-47c6-8d1f-5b7d9f0a1c33",
      "email": "j.pierre@etoile.example",
      "role": "manager",
      "invited_by": "Marie-Claire Jean",
      "created_at": "2026-10-05T15:20:00Z",
      "expires_at": "2026-10-12T15:20:00Z",
      "expired": false
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 1 }
}
```

`expired` is true once `expires_at` has passed. The token is never returned.

### 403, 401, 422, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
