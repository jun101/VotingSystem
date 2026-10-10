# Slice 09 — Group scope, copy voters, checklist and scheduling

Brief (draft) · 2026-10-10 · branch `slice/09-group-scope-and-copy` (from `staging`)

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

- **09a** Group scope: tables (`ballot_voter_group`), ballot endpoints and modal, group delete and merge rules, the
  groups card shows `ballots_count`.
- **09b** Copy voters: the endpoint and a dialog on the voters page ("Charger depuis une élection").
- **09c** Checklist and scheduling: the two endpoints, the A05 checklist card with blockers and warnings, the schedule
  and back buttons, ballots and candidates locked once scheduled.

Test ids, endpoint files and acceptance tests are written next (steps 1 and 2 of the loop), after this brief is read.

## Not in this slice

Codes (10), emails (11), the voting flow (12), opening and closing (13), the vote count (14).
