# Slice 17 — Audit log

Brief · 2026-10-12 · branch `slice/17-audit-log` · prepared by the assistant for the next session: **the rows marked
"default" are the assistant's proposals, to confirm with Jun before coding.**

## Goal

1. Every action listed in FR-AUD-01 is **recorded** with who, what, when and from which address (FR-AUD-02); entries can
   be neither edited nor deleted by the application (the database refuses it).
2. The **owner** reads the log of the institution, filters it and exports it (FR-AUD-03). Screen A15
   (`/admin/audit`, today a "not available yet" page).

## Requirements covered

FR-AUD-01, FR-AUD-02, FR-AUD-03, FR-SEC-06 (nothing secret in an entry), NFR-SEC-03, NFR-SEC-06 (formula guard on the
export), NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Rules (written in the endpoint files)

| Rule | Value |
|---|---|
| Table | `audit_entries` of docs/design/database.md 2.5 (+ `uuid`); the `app` database user has INSERT and SELECT only (docs/design/database.md section 6) |
| Who writes | One `AuditLog::record(...)` call at the end of the successful action, inside its transaction where there is one; a refused or failed request writes nothing |
| Actor | The signed-in user (uuid and name copied at write time); the scheduler writes with no user and `actor_name` `system` |
| Address | The client address as the API sees it (trusted proxy rules), full |
| Never in `details` | A code, a hash, a password, a token, a vote, a candidate choice, a file's content, an email body |
| Who reads | Owner only (manager 403); the institution's own entries only; entries of the platform (institution null) are never listed |
| Filters | `action` (a code, or a prefix ending with a dot), `election`, `user` (uuid or `system`), `from`, `to`; newest first; 50 per page (1 to 100) |
| Export | `GET /audit/export?format=csv|xlsx` with the same filters, at most 10 000 rows, FormulaGuard, 20 an hour (default) |
| Retention | Kept as long as the institution exists (default; no purge job) |

## Actions recorded (code: subject, details)

| Code | Written when | Subject | Details |
|---|---|---|---|
| `auth.sign_in` | A sign-in succeeds (after two-factor, if any) | user | `{}` |
| `election.created`, `election.duplicated` | Created or duplicated | election | `{title}` |
| `election.updated` | Edited (not the times) | election | `{fields: [names]}` |
| `election.times_changed` | Start, end or time zone edited | election | `{from: {starts_at, ends_at, timezone}, to: {…}}` |
| `election.scheduled`, `election.unscheduled` | Status changes by a user | election | `{}` |
| `election.opened`, `election.closed` | By the scheduler, or `closed` early by a user | election | `{by: "scheduler"|"user"}` |
| `election.extended` | End moved | election | `{from, to}` (ends_at) |
| `election.published`, `election.unpublished`, `election.archived`, `election.unarchived` | Slice 16 actions | election | `{display}` for published |
| `election.deleted` | A draft deleted | election (uuid kept) | `{title}` |
| `second_round.created` | Slice 15 | election (new) | `{from: <uuid>, ballot: <uuid>}` |
| `voters.imported` | An import is confirmed | election | `{count, skipped}` |
| `voter.created`, `voter.updated`, `voter.deleted` | A voter is added, edited, deleted | voter | `{name}` (+ `fields` for updated) |
| `credentials.generated` | A run is queued | election | `{scope, count}` |
| `credentials.reissued` | A code is reissued | voter | `{name}` |
| `credentials.downloaded` | A slips PDF or a codes file is downloaded | generated file | `{kind}` |
| `credentials.emailed` | An email run is queued | election | `{count}` |

## Endpoints

`docs/api/audit/GET-audit.md` and `GET-audit-export.md` (listed in `docs/api/README.md`).

## What to build (runs)

- **17a, server:** the migration (table, grants, immutability), `AuditLog` writer, the calls in the existing actions
  (controllers or actions listed above), the two endpoints.
- **17b, web:** A15 `/admin/audit`: cards (icon by family, action in words, actor, election chip, time, address), filters
  (action family, election, user, dates), export buttons, empty state, polling not needed; non-owners see the "not for
  you" page like other owner-only pages; messages in both languages; the action codes translated.

## Test ids (browser tests `web/e2e/slice17/audit.spec.ts`)

| Element | Test id |
|---|---|
| Page | `audit-page`, `audit-empty` |
| Entries | `audit-entry-{n}` (n from 1, newest first), `audit-action-{n}` (`data-action` the code), `audit-actor-{n}`, `audit-election-{n}`, `audit-time-{n}`, `audit-ip-{n}` |
| Filters | `audit-filter-action` (select of families), `audit-filter-election` (select), `audit-filter-user` (select), `audit-filter-from`, `audit-filter-to` |
| Export | `audit-export-csv`, `audit-export-xlsx` |
| Paging | `audit-more` (button "show more") |

## Not in this slice

The platform admin's view (18), the operator's command that reads the vote audit (19), alerts or emails on actions.
