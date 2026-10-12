# DELETE /api/v1/platform/impersonation

Ends the acting-as session and gives the platform admin back their own.

| | |
|---|---|
| Slice | 18c |
| Requirements | FR-ADM-01, FR-AUD-01 |
| Caller | A platform admin who is acting as an institution (the session is `impersonating`) |
| Rate limit | **None**: ending must always work (security review, 2026-10-12) |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Acting as an institution | 200 (the admin's own `GET /auth/me` body) | | |
| 2 | Not acting (an owner, a manager, an admin who is not acting) | 409 | `not_impersonating` | |
| 3 | Not signed in, CSRF, another method | 401, 419, 405 | the shared codes | |

Works while the institution is suspended since the start (nothing else is, so the admin can always get out). Writes
`platform.impersonation_ended`. After it, tenant routes answer the admin 403 again.
