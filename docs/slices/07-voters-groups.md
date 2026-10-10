# Slice 07 — Groups and voters

Brief · 2026-10-10 · branch `slice/07-voters-groups` (from `staging`)

## Goal

An owner or a manager sees, adds, edits and deletes the voters of an election and manages their groups, on one page
(screen A08), with modals, as on the ballots page. Reading is always allowed; writing follows the election's status.

## Requirements covered

FR-VOT-01, FR-VOT-02, FR-VOT-06 (list, search, group filter, edit, delete; the credential and "has voted" filters wait
for slices 10 and 12), FR-VOT-08, FR-SEC-06, NFR-SEC-03, NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-10)

- Voter cards, **two per row** (one on a phone), 24 per page, as the mockup; search and group chips above.
- Groups card in the right rail (rename, merge, delete, new), as the Parties card.
- Add and edit a voter in a modal on the same page (no record page).
- The mockups: [material-voters.html](../design/mockups/material-voters.html) and
  [material-voter-modal.html](../design/mockups/material-voter-modal.html), approved.

## Rules decided in the contract

| Rule | Value |
|---|---|
| Who writes | Owner and manager |
| Voters: add and edit | Election draft, scheduled or open; closed, published, archived: 409 `election_voters_locked` |
| Voters: delete | Draft or scheduled only (open waits for slice 12, which knows who has voted) |
| Groups: add, rename, merge, delete | Draft or scheduled only |
| Group of a voter | Sent as the group's **name** (`group`): found by name (case and spaces ignored, accents counted) or created; blank is no group |
| Limits | 10 000 voters, 100 groups per election (409 `voter_limit_reached`, `group_limit_reached`) |
| Uniqueness | Identifier and email unique in the election (422 `unique`); the same full name may repeat |
| Group deletion | 409 `group_in_use` while a voter is in it (slice 09 adds "no ballot covers it") |
| Merge | `POST /groups/{group}/merge` `{into}`: voters move, group deleted, one transaction |

## Read before coding

[database.md](../design/database.md) `voter_groups`, `voters` · [frontend.md](../design/frontend.md) items 9 to 12 and
the new item 13 (to be written with this slice: the voters page) · the nine endpoint files in
[voters/](../api/voters/) and [groups/](../api/groups/) · the slice 06 code (`Party` model, policy, controller, request,
resource, `PartiesCard`, `PartyModal`, the ballots page), which this slice follows.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `GET /elections/{election}/voters` | [GET-elections-{election}-voters.md](../api/voters/GET-elections-{election}-voters.md) |
| `POST /elections/{election}/voters` | [POST-elections-{election}-voters.md](../api/voters/POST-elections-{election}-voters.md) |
| `PATCH /voters/{voter}` | [PATCH-voters-{voter}.md](../api/voters/PATCH-voters-{voter}.md) |
| `DELETE /voters/{voter}` | [DELETE-voters-{voter}.md](../api/voters/DELETE-voters-{voter}.md) |
| `GET /elections/{election}/groups` | [GET-elections-{election}-groups.md](../api/groups/GET-elections-{election}-groups.md) |
| `POST /elections/{election}/groups` | [POST-elections-{election}-groups.md](../api/groups/POST-elections-{election}-groups.md) |
| `PATCH /groups/{group}` | [PATCH-groups-{group}.md](../api/groups/PATCH-groups-{group}.md) |
| `DELETE /groups/{group}` | [DELETE-groups-{group}.md](../api/groups/DELETE-groups-{group}.md) |
| `POST /groups/{group}/merge` | [POST-groups-{group}-merge.md](../api/groups/POST-groups-{group}-merge.md) |

## What to build

### 07a. Server

| Item | Requirement |
|---|---|
| Migrations | `voter_groups` and `voters` as in database.md (`uuid`, `institution_id` cascade, `election_id` cascade, the columns; `voters.voter_group_id` restrict). `voter_groups.name_key` is `utf8mb4_bin` (accents count, lower-cased in PHP), unique `(election_id, name_key)`; `voters` unique `(election_id, identifier)` and `(election_id, email)`, index `(election_id, voter_group_id, full_name)`. Run with the `migrate` service |
| Models | `VoterGroup`, `Voter`: `HasUuid`, `BelongsToInstitution`; `Election::voterGroups()`, `Election::voters()` |
| Policies | Owner or manager; a second helper on `Election` for the voter rules (`assertVotersEditable`, `assertVotersDeletable`) answering 409 `election_voters_locked` (new message key in fr and en) |
| Requests | Rules and codes as in the endpoint files (trim, blank to null, email lower-cased, phone characters `0-9 + - ( ) .` and spaces, `identifier` unique ignoring case) |
| Controllers | `Voters/VoterController`, `Groups/GroupController`, `Groups/GroupMergeController`; transactions lock the election row first (as parties); a group created from a voter's `group` name is in the same transaction; counts with `withCount` |
| Resources | `VoterResource` (`group` as `{id, name}` or null) and `GroupResource` (`voters_count`); list meta of groups carries `voters_total` and `ungrouped` |
| List | Search with `LIKE` on name, identifier, email (escape `%` and `_`), `group` uuid or `none`, order by `full_name` ignoring case then `id` |
| Rate limits | Named limiters `voters-write` (240 an hour per user) and `groups-write` (120 an hour per user); `throttle.quiet` where tests compare headers |
| Isolation | The nine routes are in `Tenancy::TENANT_ROUTES` (already added) |
| Election resource | `voters_count` of the election card becomes the real count |

### 07b. Web: the voters page

Route `/admin/elections/{election}/voters`; follow the mockups, frontend.md items 10 to 12 and the voter modal as the
party modal (shared `Modal`). The election page step "Électeurs" (`election-step-2`) becomes ready and links to the page
(the theme spec already expects it).

| Item | Requirement |
|---|---|
| Hero | `voters-page`; chips `voters-count` ("n électeurs"), `groups-count`, `ungrouped-count` (only when above 0); cta `voter-add` ("Ajouter un électeur"); the import button is not shown before slice 08 |
| Search and filters | `voters-search` (debounced, no reload); chips `voters-filter-all`, `voters-filter-{n}` (n from 1 in the API's group order), `voters-filter-none`, each with its count; the selected one is `aria-pressed` |
| List | `voters-list`, cards `voter-card-{n}` (n from 1 on the page): `voter-name-{n}`, `voter-group-{n}` ("Sans groupe" when none), `voter-identifier-{n}`, `voter-edit-{n}`, `voter-delete-{n}`. **Two cards per row** from 768 px, three from 2300 px, one on a phone, all the same size |
| Empty and no match | `voters-empty` (election with no voter, one sentence and the add button), `voters-no-match` (search or filter with no result) |
| Pager | `voters-pager`, `voters-range` ("1 à 24 sur 312 électeurs"), `voters-prev`, `voters-next`, page numbers `voters-page-{p}`; 24 per page; the page and filters are kept in the URL query (`?page=&q=&group=`) so a refresh keeps them |
| Voter modal | `voter-modal` (dialog, item 12): `voter-form-name`, `voter-form-group` (text input with the existing groups as suggestions), `voter-form-identifier`, `voter-form-email`, `voter-form-phone`, `voter-form-save`, `voter-form-save-another` (creation only; keeps the group, clears the rest, focus back to the name), `voter-form-cancel`, `voter-modal-close`; errors `voter-form-error-{field}` (`full_name`, `group`, `identifier`, `email`, `phone`); stays open on error; focus first on the name, back to the opener |
| Delete voter | `voter-delete-dialog` naming the voter, `voter-delete-cancel`, `voter-delete-confirm` |
| Groups card | `groups-card` in the rail: rows `group-row-{n}` with `group-name-{n}`, `group-count-{n}`, `group-rename-{n}`, `group-merge-{n}`, `group-delete-{n}` (disabled while the group has voters); `group-new`; the explanation note; the "Codes de vote" card stays a locked placeholder |
| Group modals | `group-modal` (`group-form-name`, `group-form-save`, `group-form-cancel`, `group-form-error-name`), `group-merge-dialog` (`group-merge-into` select of the other groups, `group-merge-cancel`, `group-merge-confirm`), `group-delete-dialog` (`group-delete-cancel`, `group-delete-confirm`) |
| Status | Closed, published, archived: no add, edit, delete or group buttons and a notice `voters-locked-notice`; scheduled: everything; open: add and edit voters, no delete, no group management (the buttons are not rendered). A 409 `election_voters_locked` from any action shows the notice and reloads |
| Text and colour | French and English message files only; tokens only, no raw colour |
| Phone | One card per row, groups card below, modals as bottom sheets, no sideways scroll |

### Tests (written before the code, the coder never edits them)

- API: `api/tests/Acceptance/Slice07/` — `ListVotersTest`, `CreateVoterTest`, `UpdateVoterTest`, `DeleteVoterTest`,
  `ListGroupsTest`, `CreateGroupTest`, `UpdateGroupTest`, `DeleteGroupTest`, `MergeGroupsTest`; helpers `plantGroup`,
  `groupRow(s)`, `plantVoter`, `voterRow(s)` in `api/tests/Support/Accounts.php`; nine routes in `Tenancy::TENANT_ROUTES`;
  the `Slice07` hook in `api/tests/Pest.php`.
- Browser: `web/e2e/slice07/voters.spec.ts`, `web/e2e/support/voters.ts`; the theme election spec now expects step 2
  ready.

Tests written by the coder: unit tests of the page's pure helpers (status rules, URL query, range text, group
suggestions) and a component test that closed, published and archived elections show no write buttons.

## Done when

`make lint`, the unit tests and both acceptance folders pass with no edit; `docs/api/openapi.json` and
`web/src/lib/api/schema.d.ts` regenerated by the generator; the page looked at at 1920 × 1080, 1280 × 720 and 360 × 780
with the modal open.

## Not in this slice

The import (slice 08, mockup `material-voter-import.html`), loading voters from a past election and group scope on
ballots (slice 09), codes (slice 10), "has voted" (slice 12), duplicating voters with an election.
