# GET /api/v1/platform/institutions

The institutions of the platform, with counts only (screen A16).

| | |
|---|---|
| Slice | 18 |
| Requirements | FR-ADM-01, FR-ADM-02, FR-SEC-03, NFR-SEC-08 |
| Caller | **Platform admin** (an institution user answers 403 `forbidden`) |
| Rate limit | None (an admin's reads are few; the two POST routes are limited) |

## Request

Query:

| Parameter | Type | Rules |
|---|---|---|
| q | string | Optional. Part of the institution's name, any case, at most 100 characters |
| status | string | Optional. `active` or `suspended`; 422 `validation_failed` (`status: invalid`) for another value |
| page | integer | Default 1 (`page: min` below 1) |
| per_page | integer | Default 20, 1 to 100 (`per_page: max`) |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | The platform admin, institutions exist | 200, newest created first | | |
| 2 | `q` and `status`, alone or together | 200 (the matches; `meta.total` counts them) | | |
| 3 | No match | 200 (`data: []`, `meta.total: 0`) | | |
| 4 | A bad `status`, `per_page` out of range, `page` below 1 | 422 | `validation_failed` | |
| 5 | An owner or a manager | 403 | `forbidden` | |
| 5a | A platform admin without two-factor authentication, where `platform.require_two_factor` is on (production) | 403 | `two_factor_required` (judged right after the role; `/auth/me` and the two-factor routes still work) | |
| 6 | Not signed in, another method | 401, 405 | the shared codes | |

## Responses

### 200

```json
{
  "data": [
    {
      "id": "b3a1c6a2-1f0e-4a52-8a4e-6a2f6f7d9c10",
      "name": "Collège Les Flamboyants",
      "type": "school",
      "status": "suspended",
      "suspended_at": "2026-10-12T14:00:00Z",
      "suspension_reason": "Paiement non reçu",
      "created_at": "2026-09-01T09:30:00Z",
      "last_sign_in_at": "2026-10-11T08:12:00Z",
      "counts": {
        "users": 3,
        "voters": 412,
        "elections": { "draft": 1, "scheduled": 0, "open": 1, "closed": 2, "published": 1, "archived": 0 }
      }
    }
  ],
  "meta": { "page": 1, "per_page": 20, "total": 37 }
}
```

`status` is `active` or `suspended`; `suspended_at` and `suspension_reason` are `null` for an active institution.
`last_sign_in_at` is the latest `last_login_at` of its users, or `null`. `voters` is the number of voters of all its
elections. These are **the only data about an institution this endpoint gives**: no name, email or address of a user
or of a voter, no code, no candidate, no title of an election, no vote and no vote count. No numeric id appears.
