# Slice 18 — Platform administration

Brief · 2026-10-12 · branch `slice/18-platform-administration` (from `staging` once slice 17 is merged) · prepared for the
next sessions. Jun's decisions of 2026-10-12 are in the table; the rest are proposals.

## Goal

1. The platform admin signs in and lands on `/platform` (screen A16): the institutions with their election counts
   (FR-ADM-01).
2. They **suspend** and **reactivate** an institution, with a reason (FR-ADM-01, FR-INST-06).
3. A suspended institution cannot sign in, its open elections stop, and nothing about votes, credentials or voter contact
   details is reachable from the platform screen (FR-ADM-02, FR-SEC-03).

## Requirements covered

FR-ADM-01, FR-ADM-02, FR-INST-06, FR-INST-05, FR-AUD-01 (suspensions are audited), NFR-SEC-03, NFR-SEC-04, NFR-SEC-08,
NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-12)

| Question | Decision |
|---|---|
| First platform admin | Created by an **operator command** on the server (`platform:create-admin {email}`, asks for the password); there is no sign-up for it. Same login page, then sent to `/platform` |
| Open elections of a suspended institution | **Paused**: votes refused and the voter pages say unavailable while suspended; nothing is closed; the end time does not move; they resume on reactivation |
| Reason and notice | A **reason is required**; the owner sees it when sign-in is refused, and is **emailed** at suspension and at reactivation |

## Proposals (not yet asked)

| Question | Proposal |
|---|---|
| Two-factor for the platform admin | Same optional TOTP as users; the command may require it later (slice 19) |
| Sessions of a suspended institution | Existing sessions are refused at once (the API already answers 403 `institution_suspended`); nothing to purge |
| Counts shown | Elections by status, users, voters (number only), created date, last sign-in date; never a name of a voter, a code, a vote, a candidate |
| Audit | Entries with `institution_id` set to the institution and `actor_name` of the admin: `institution.suspended` (`{reason}`), `institution.reactivated`; visible to that institution's owner? **No**: platform entries stay out of the owner's list (slice 17), they are in a platform log only |
| Rate limits | 60 requests an hour per admin on the two POST routes |

## Endpoints (to write: `docs/api/platform/`)

`GET /platform/institutions` (query `q`, `status` = `active`|`suspended`, `page`), `POST /platform/institutions/{id}/suspend`
`{reason}`, `POST /platform/institutions/{id}/reactivate`, `GET /platform/me` (or the existing `/auth/me` with a
`platform_admin` role). A suspended institution answers `institution_suspended` with the reason in
`error.reason` on sign-in only.

## What to build (runs)

- **18a, server:** migration (`suspended_at`, `suspension_reason` on institutions if not there), the command, the endpoints,
  the sign-in message with the reason, the emails (fr and en), audit writes (needs slice 17), tests for FR-ADM-02 (no route
  of the platform returns votes, credentials or voter data).
- **18b, web:** A16 `/platform` (cards per institution, search, filter, suspend dialog with the reason, reactivate
  dialog), the sign-in refusal screen showing the reason, replace the "not yet available" message at sign-in.

## Behaviour changed in existing contracts (written 2026-10-12)

- `VotePhase`: a suspended institution's `scheduled` or `open` election is `paused` (was `closed`); closed, published and
  archived ones stay `closed`. `GET /vote/{election}` and `POST /vote/{election}/session` (`error.phase`) carry it; the
  vote submit answers 409 `election_not_open`. The test of slice 12 VoteInfoTest scenario 5 was changed with it.
- `elections:advance` skips elections of a suspended institution (no opening, no closing). After reactivation its
  next run applies the usual rules.
- `POST /auth/login` and the two-factor challenge give `error.reason` with `institution_suspended`.
- `Tests\Support\Tenancy::PLATFORM_ROUTES`: a fifth list (routes for the platform admin only; `auth` + the platform
  middleware, no `institution.active` needed), counted by the coverage tests.

## Test ids (browser tests `web/e2e/slice18/platform.spec.ts`)

| Element | Test id |
|---|---|
| Page | `platform-page`, `platform-empty` |
| Cards (n from 1, newest first) | `platform-institution-{n}`, `platform-name-{n}`, `platform-status-{n}` (`data-status` `active` or `suspended`), `platform-counts-{n}`, `platform-suspend-{n}`, `platform-reactivate-{n}` |
| Filters | `platform-search` (live search by name), `platform-filter-status` (select: all, active, suspended) |
| Dialogs | `suspend-reason` (required; the confirm does nothing and the dialog stays while it is empty), `suspend-confirm`, `suspend-cancel`, `reactivate-confirm`, `platform-error` |
| Sign-in refusal | `login-suspended` (the notice), `login-suspended-reason` (the text of the reason only) |

Routes: `/platform` for the platform admin only (an owner or manager is redirected to `/admin`, a signed-out visitor to
`/login`); a platform admin who signs in lands on `/platform`, and any `/admin` page sends them there.
`make e2e` creates the browser tests' admin with `platform:create-admin e2e-admin@platform.test
--password-from-env=PLATFORM_ADMIN_PASSWORD` (new option of the command, the password read from that environment
variable instead of asked; documented in the command's file).

## Not in this slice

Platform statistics beyond counts, billing, the operator's vote-audit command (19). Impersonation is slice 18c below (Jun, 2026-10-12: it replaces "never").

## Slice 18c: the platform admin acts as a school's owner (Jun, 2026-10-12, no consent needed)

Replaces the "never impersonate" rule and changes FR-ADM-02 only as far as an owner already sees: the platform admin
inherits an owner's reach (voter lists, credentials, files), no more. Ballot secrecy is untouched (rule 3: no vote, no
`vote_audit`, no count before close for the admin either).

Endpoint files: [impersonate](../api/platform/POST-platform-institutions-{institution}-impersonate.md),
[end](../api/platform/DELETE-platform-impersonation.md). Jun's choices: no time limit (ends at
sign-out), nothing refused, no email to the owners; the only safeguard is that every action is audited under the admin's
name and visible in the owner's audit log. Tests: `api/tests/Acceptance/Slice18/ImpersonationTest.php`.
Web (18c-web): a "Se connecter en tant que" button on each active card of `/platform` (`platform-impersonate-{n}`), a
banner on every admin page while acting (`impersonation-banner`, with the institution, the time left and
`impersonation-end`), and the end returns to `/platform`.
