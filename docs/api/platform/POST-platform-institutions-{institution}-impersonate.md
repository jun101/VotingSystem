# POST /api/v1/platform/institutions/{institution}/impersonate

The platform admin enters an institution and acts as its owner ("connect as school admin").

| | |
|---|---|
| Slice | 18c |
| Requirements | FR-ADM-01, FR-AUD-01, NFR-SEC-04 |
| Caller | **Platform admin** (not already acting as an institution) |
| Rate limit | 60 requests per hour per admin, shared with suspend, reactivate and end |

Jun's decision, 2026-10-12: **no consent from the school** is needed. This replaces "impersonation: never" of the slice 18
brief; the safeguards below are what makes it acceptable.

## Request

| Path parameter | Type | Rules |
|---|---|---|
| institution | UUID | An institution that is not suspended |

No body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An active institution | 200 | | |
| 2 | The institution is suspended | 403 | `institution_suspended` (reactivate it first) | |
| 3 | Unknown institution, or not a UUID | 404 | `not_found` | |
| 4 | An owner or a manager | 403 | `forbidden` | |
| 5 | The admin is already acting as an institution | 409 | `already_impersonating` | |
| 6 | Not signed in, CSRF, too many requests, another method | 401, 419, 429, 405 | the shared codes | |

Order of the checks: 401, 403, 404, 429, 409 (already impersonating), 403 (suspended).

## Responses

### 200

The same body as [GET /auth/me](../auth/GET-auth-me.md) for the session as it is now: `role` `owner`, the institution,
and `impersonating`.

```json
{ "data": { "id": "…", "name": "Marie Joseph", "role": "owner", "institution": { "id": "…", "name": "Collège Les Flamboyants" },
  "impersonating": { "by": "Admin Plateforme", "until": "2026-10-12T16:30:00Z" } } }
```

## What the session is while acting

- The user is **the institution's oldest active owner**, so every rule, policy and tenant scope of an owner applies
  unchanged (ballot secrecy, no vote count before close, no `vote_audit`: nothing is added or lifted).
- The real admin stays in the session; **it ends by itself after 60 minutes**, on [DELETE /platform/impersonation](DELETE-platform-impersonation.md),
  and on sign-out. It never extends.
- `GET /auth/me` gives `impersonating: {by, until}`; every other time it is absent.
- **Refused while acting, 403 `impersonation_forbidden`:** everything that changes who can get into the account or the
  institution: `PATCH /auth/me` (password, email, language is allowed), the two-factor routes, `DELETE /users/{user}`,
  `POST /invitations`, `DELETE /invitations/{invitation}`, `POST /users/{user}/two-factor/reset`, `PATCH /institution`.
  The institution's elections, voters, credentials, results and publication are fully allowed.
- **Audit (visible to the owner):** `platform.impersonation_started` and `platform.impersonation_ended` entries
  (`institution_id` of the institution, `details` `{by}`), and every entry written while acting names the admin:
  `actor.id` `null`, `actor.name` `"<admin name> (plateforme)"`, `details.impersonated_by` the admin's name. The owner's
  audit list shows them like any other entry.
- Every owner of the institution is emailed when it starts (institution's language; the admin's name and the time).
