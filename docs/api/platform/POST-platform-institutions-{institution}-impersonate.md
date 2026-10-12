# POST /api/v1/platform/institutions/{institution}/impersonate

The platform admin enters an institution and acts as its owner ("connect as school admin").

| | |
|---|---|
| Slice | 18c |
| Requirements | FR-ADM-01, FR-AUD-01, NFR-SEC-04 |
| Caller | **Platform admin** (not already acting as an institution) |
| Rate limit | 60 requests per hour per admin, shared with suspend and reactivate (ending is not limited) |

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
  "impersonating": { "by": "Admin Plateforme" } } }
```

## What the session is while acting

- The user is **the institution's oldest active owner**, so every rule, policy and tenant scope of an owner applies
  unchanged (ballot secrecy, no vote count before close, no `vote_audit`: nothing is added or lifted).
- The real admin stays in the session; it ends on [DELETE /platform/impersonation](DELETE-platform-impersonation.md)
  and on sign-out (Jun: no time limit of its own; the session's normal lifetime applies).
- `GET /auth/me` gives `impersonating: {by}`; every other time it is absent.
- **Nothing is refused** (Jun, 2026-10-12): the admin has exactly the owner's power, account security and users
  included. Ballot secrecy is the only limit, and it is the owner's own.
- **The session is bound to the owner it acts as**: it counts as acting only while the signed-in user is that owner and
  the admin's password has not changed since it started; otherwise it is dropped (the admin's password change or reset
  ends it). A sign-in, registration or invitation acceptance in the same browser clears it.
- **Password confirmations while acting** (two-factor routes, user two-factor reset) ask for the **admin's own password**,
  and wrong attempts count on the admin's counter, never on the owner's.
- **Owner actions with no audit entry of their own** (removing a user, inviting, editing the institution or its logo,
  two-factor changes and resets) write one entry `platform.impersonation_action` while acting, with `details`
  `{ "what": "user.removed" | "invitation.created" | "institution.updated" | "institution.logo_changed" | "two_factor.changed", "impersonated_by": name }`.
- **Audit (visible to the owner):** `platform.impersonation_started` and `platform.impersonation_ended` entries
  (`institution_id` of the institution, `details` `{by}`), and every entry written while acting names the admin:
  `actor.id` `null`, `actor.name` `"<admin name> (plateforme)"`, `details.impersonated_by` the admin's name and `details.impersonated_by_id` the admin's uuid. The owner's
  audit list shows them like any other entry.
- **No email** is sent to the owners (Jun's decision); the audit entries are how they see it.
