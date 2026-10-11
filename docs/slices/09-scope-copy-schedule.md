# Slice 09 — Group scope, copy voters, checklist and scheduling

Brief · 2026-10-10 · branch `slice/09-group-scope-and-copy` (from `staging`)

## Goal

1. A ballot can be restricted to some voter groups (FR-BAL-03, 05, 06). A group a ballot uses cannot be deleted.
2. The voters of another election of the institution can be loaded into this one, with their groups, and groups can be
   renamed during the copy (FR-VOT-05).
3. The summary page (A05) gets the checklist and the Draft to Scheduled step, and back (FR-ELEC-03, FR-ELEC-04).

## Requirements covered

FR-BAL-03, FR-BAL-05, FR-BAL-06, FR-VOT-05, FR-VOT-08 (a group used by a ballot), FR-ELEC-03 (Scheduled), FR-ELEC-04,
NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-10)

- The groups of a group ballot are chosen **in the ballot modal** (a scope choice and group chips with their voter
  counts); the ballot card shows the groups.
- Merging a group a ballot uses: **the ballot follows the merge** (it now covers the target group, no duplicate); the
  merge dialog names the affected ballots.
- Loading voters: **any other election of the institution** is a source (the draft with no voter has nothing to give);
  a voter already in this election by identifier or email is **skipped and counted**, as the import does.
- Scheduling: **owner and manager**. Blockers stop it; warnings need a confirmation tick.

## Rules (to be written in the endpoint files)

| Rule | Value |
|---|---|
| Ballot scope | `general` (default) or `group`; a group ballot has at least one group, all of this election (422 `groups`) |
| Who writes | Owner and manager, as slice 06 |
| When | Draft only (a scheduled election locks ballots and candidates; voters and groups stay editable as slice 07 says) |
| Eligibility (FR-BAL-05) | A voter is eligible for every general ballot and for the group ballots that include their group; a voter with no group, for general ballots only |
| Warning (FR-BAL-06) | A group ballot covering fewer than 5 voters: `few_voters` warning with the count; never a block |
| Group delete | 409 `group_in_use` also while a ballot uses the group (slice 07 left this for here); the group resource gets `ballots_count` |
| Group merge | Ballots using the merged group are moved to the target in the same transaction |
| Copy | Source: another election of the institution; all voters, or chosen groups (and the ungrouped voters when asked); `rename` maps a source group to a new name (found or created by name as slice 07); skipped duplicates counted; credentials and participation never copied; draft or scheduled target; 10 000 voters and 100 groups limits |
| Checklist | Blockers: no ballot; a ballot with no candidate; no voter. Warnings: a ballot with one candidate; a group ballot with no eligible voter; a group ballot with fewer than 5 voters; voters eligible for no ballot (count) |
| Schedule | Draft to Scheduled when there is no blocker; warnings need `accept_warnings: true`; back to Draft with unschedule while Scheduled (not yet Open) |

## Endpoints

Changed files (`docs/api/ballots/`, `docs/api/groups/`): `POST /elections/{election}/ballots` and
`PATCH /ballots/{ballot}` take `scope` and `groups` (group UUIDs); `GET /elections/{election}/ballots` (and the single
ballot) return `scope`, `groups` (`{id, name}`), `eligible_voters` and `warnings`; `GET /elections/{election}/groups`
returns `ballots_count`; `DELETE /groups/{group}` and `POST /groups/{group}/merge` follow the rules above.

New files (`docs/api/elections/`, `docs/api/voters/`):

| Endpoint | Purpose |
|---|---|
| `POST /elections/{election}/voter-copies` | Load voters from another election: `{source, groups, include_ungrouped, rename}` → `{copied, skipped, groups_created}` |
| `GET /elections/{election}/checklist` | Blockers, warnings and counts for the summary page |
| `POST /elections/{election}/schedule` | Draft to Scheduled (`accept_warnings`) |
| `POST /elections/{election}/unschedule` | Scheduled back to Draft |

## What to build

Cut in three runs, each ending green (as slices 06 and 08):

### 09a. Group scope

Server: migration for `ballot_voter_group` (`ballot_id` cascade, `voter_group_id` restrict, primary key both, no id,
no uuid, no timestamps; docs/design/database.md); `Ballot::groups()`; the ballot resource gets `scope`, `groups`,
`eligible_voters`, `warnings`; POST and PATCH take `scope` and `groups` (sync inside the transaction that holds the
election lock; groups looked up in the ballot's own election); the groups resource gets `ballots_count`; group delete
refuses a group a ballot covers; merge moves the ballots (no duplicate) in its transaction, also while scheduled.

Web (ballots page A06 and voters page A08). Test ids:

| Element | Test id |
|---|---|
| Ballot modal: scope radios | `ballot-form-scope-general`, `ballot-form-scope-group` |
| Group chips (only for the group scope; n from 1 in the groups' order; `aria-pressed`; name and voter count) | `ballot-form-groups`, `ballot-form-group-{n}` |
| Error | `ballot-form-error-groups` |
| Ballot card | `ballot-scope-{n}` (number of eligible voters in words), `ballot-groups-{n}` (the group names), `ballot-voters-warning-{n}` (few or no voters) |
| Groups card (voters page) | `group-ballots-{n}` (number of ballots covering the group), `group-delete-{n}` disabled while a ballot covers it |
| Merge dialog | `group-merge-ballots` (names the ballots that will follow) |

### 09b. Copy voters

Server: `POST /elections/{election}/voter-copies` as its endpoint file, one transaction that locks the target election
row, chunks of 500, identifier and email checked as the import does (also against rows copied in the same request);
limiter `voter-copies` 20 an hour per user.

Web (voters page hero, only in a draft or scheduled election). Test ids:

| Element | Test id |
|---|---|
| Hero button | `voters-copy` ("Charger depuis une élection") |
| Dialog | `copy-dialog`, `copy-source` (select of the other elections of the institution), `copy-groups-all`, `copy-groups-some` (radios), `copy-group-{n}` (checkbox per source group, with its voter count; n from 1), `copy-ungrouped` (checkbox), `copy-rename-{n}` (text input shown for a checked group, empty keeps the name), `copy-submit` ("Charger N électeurs"), `copy-cancel`, `copy-error` |
| Result | `copy-result`, `copy-result-copied`, `copy-result-skipped`, `copy-result-groups`, `copy-close` (the page then shows the new voters and groups) |

### 09c. Checklist and scheduling

Server: `GET /elections/{election}/checklist`, `POST .../schedule`, `POST .../unschedule` as their endpoint files; the
checklist is computed again under the election row lock when scheduling; the new error codes
(`checklist_blocked`, `checklist_warnings`, `election_period_over`, `election_not_scheduled`) in the message files;
`error.items` on the first two.

Web (summary page A05 and ballots page). Test ids:

| Element | Test id |
|---|---|
| Checklist card | `election-checklist`, `checklist-blockers`, `checklist-blocker-{n}`, `checklist-warnings`, `checklist-warning-{n}`, `checklist-ok` (nothing to report) |
| Buttons | `election-schedule-button` (disabled while there is a blocker, hidden when not a draft), `election-unschedule-button` (only when scheduled) |
| Dialogs | `schedule-dialog`, `schedule-accept-warnings` (a checkbox, shown only with warnings; `schedule-confirm` is disabled until ticked), `schedule-confirm`, `schedule-cancel`; `unschedule-dialog`, `unschedule-confirm`, `unschedule-cancel` |
| Status | `election-summary-status` gets `data-status` (`draft`, `scheduled`, …) |
| Ballots page | `ballots-locked-notice` while the election is not a draft; no `ballots-add`, no edit, delete or reorder controls |

Text in the French and English message files only; tokens only; phone: one column and modals as bottom sheets.

### Tests (written before the code, the coder never edits them)

- API: `api/tests/Acceptance/Slice09/` — `BallotScopeTest`, `GroupBallotRulesTest`, `VoterCopyTest`, `ChecklistTest`,
  `ScheduleTest`, `UnscheduleTest`; helpers `plantBallot` (option `groups`), `ballotGroups` in
  `api/tests/Support/Accounts.php`; four routes in `Tenancy::TENANT_ROUTES`; the `Slice09` hook in `api/tests/Pest.php`.
- Browser: `web/e2e/slice09/scope-copy-schedule.spec.ts`.

Tests written by the coder: unit tests of the pure helpers (eligible-voter count and warnings, rename map, checklist
item to message key, schedule dialog state) and a component test that a non draft election shows no ballot write
controls.

## Done when

`make lint`, the unit tests and both acceptance folders pass with no edit; `docs/api/openapi.json` and
`web/src/lib/api/schema.d.ts` regenerated by the generator; the pages looked at at 1920 × 1080, 1280 × 720 and
360 × 780 (ballot modal with groups, copy dialog, checklist).

## Not in this slice

Codes (10), emails (11), the voting flow (12), opening and closing (13), the vote count (14).
