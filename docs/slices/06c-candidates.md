# Slice 06c — Candidates

Brief · 2026-10-10 · branch `slice/06-ballots-parties-candidates`

## Goal

An owner or a manager adds the candidates of each ballot of a draft election **in a modal, without leaving the ballots
page**, edits them, moves them to another ballot, deletes them and puts them in order. A candidate may belong to a
party; without a photo it shows an avatar chosen by sex. The photo comes in 06d.

## Requirements covered

FR-CAND-02, FR-CAND-03, FR-BAL-04 (manual order; the shuffle per voter is slice 12), FR-ELEC-06 (option to copy the
candidates), FR-ELEC-04 (the one-candidate warning), FR-SEC-06, NFR-SEC-03, NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Read before coding

[database.md](../design/database.md) `candidates` · [frontend.md](../design/frontend.md) 1.5 items 9 to 12 · the
mockups [material-ballots.html](../design/mockups/material-ballots.html) (candidate rows, warning, rail),
[material-candidate-modal.html](../design/mockups/material-candidate-modal.html) and
[material-candidate.html](../design/mockups/material-candidate.html) (the form and the phone preview) · the four
endpoint files in [candidates/](../api/candidates/), the changed
[ballots list](../api/ballots/GET-elections-{election}-ballots.md), [parties](../api/parties/),
[duplicate](../api/elections/POST-elections-{election}-duplicate.md) files · the code of slices 06a and 06b (models,
policies, controllers, requests, resources, `Modal.tsx`, `BallotsPage`, `PartiesCard`, `PartyModal`).

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `POST /ballots/{ballot}/candidates` | [POST-ballots-{ballot}-candidates.md](../api/candidates/POST-ballots-{ballot}-candidates.md) |
| `PATCH /candidates/{candidate}` | [PATCH-candidates-{candidate}.md](../api/candidates/PATCH-candidates-{candidate}.md) |
| `DELETE /candidates/{candidate}` | [DELETE-candidates-{candidate}.md](../api/candidates/DELETE-candidates-{candidate}.md) |
| `PUT /ballots/{ballot}/candidates/order` | [PUT-ballots-{ballot}-candidates-order.md](../api/candidates/PUT-ballots-{ballot}-candidates-order.md) |
| `GET /elections/{election}/ballots` (now with `candidates`) | [ballots](../api/ballots/GET-elections-{election}-ballots.md) |
| `POST /elections/{election}/duplicate` (`copy_candidates`) | [duplicate](../api/elections/POST-elections-{election}-duplicate.md) |

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Table | One migration creates `candidates`: `uuid`, `institution_id` (cascade), `election_id` (cascade), `ballot_id` (cascade), `party_id` (null, set null on delete), `first_name` (80), `last_name` (80), `sex` (6, `male` or `female`), `slogan` (160, null; the rule allows 80), `biography` (1000, null), `photo_file` (uuid, null), `position` (smallint), `created_at`, `updated_at`; index `(ballot_id, position)`. Rights as the other tables (run with the `migrate` service) |
| Model | `Candidate`: `HasUuid`, `BelongsToInstitution`, `sex` as a PHP enum. `Ballot::candidates()` ordered by position then id; `Party::candidates()` |
| Rules | As the endpoint files: names 1 to 80; sex in the set; `party` and `ballot` UUIDs resolved inside the **same election** (unknown, other election, other institution: the same 422, `invalid`); slogan 80, biography 1000, blanks stored as null; at most 50 candidates per ballot (409 `candidate_limit_reached`) |
| Transactions | Create, move, delete and reorder lock the ballot (a move locks both ballots, lower id first); positions have no gap |
| Resources | `CandidateResource` as the file; `BallotResource` gets `candidates` (eager loaded, no N+1) and a real `candidates_count`; `PartyResource.candidates_count` becomes real (a count query for the list) |
| Duplicate | `copy_candidates` (boolean, default false; 422 `boolean` otherwise) copies every candidate into the matching new ballot, in order, party mapped to the copied party, no photo |
| Cascades | Ballot deleted: its candidates go; party deleted: `party_id` set to null, candidates stay; election deleted: all go |
| Rate limits | Writes 120 an hour per user; reorder 240 an hour per user |
| Isolation | The four routes are in `Tenancy::TENANT_ROUTES`; the table joins the isolation suite |

### 2. Web

| Item | Requirement |
|---|---|
| Rows | In each ballot card, `candidate-row-{b}-{c}` (b: the card's position, c: the row's, from 1): grip `candidate-grip-{b}-{c}`, avatar `candidate-avatar-{b}-{c}` with `data-sex` (initials badge when there will be a photo; a silhouette by sex for now: violet female, blue male), name `candidate-name-{b}-{c}` ("First Last"), party `candidate-party-{b}-{c}` (colour dot and name, or "Indépendant"), `candidate-up-`, `candidate-down-` (first up and last down disabled), `candidate-edit-`, `candidate-delete-`. The card keeps its empty state when it has none |
| Card | `candidate-add-{b}` ("Ajouter un candidat") opens the modal with this ballot chosen; `ballot-candidates-{b}` tag with the count; `ballot-warning-{b}` notice "Un seul candidat…" when exactly one (the empty ballot is covered by the checks card) |
| Header | `candidates-count` chip |
| Rail | `ballots-checks` card ("À vérifier") listing each ballot with no candidate or one candidate, each with a link that opens the add modal for it; the parties' `party-count-{n}` shows the real count |
| Modal | `candidate-modal`, built on `Modal.tsx` with `wide`: left, two cards **Identité** (names `candidate-form-first-name`, `candidate-form-last-name`, sex as two large radios `candidate-form-sex-female` / `candidate-form-sex-male`, the avatar preview) and **Candidature** (ballot `candidate-form-ballot` select, party `candidate-form-party` select with "Aucun (indépendant)", slogan `candidate-form-slogan` with `candidate-form-slogan-count`, biography `candidate-form-biography`); right, `candidate-preview` (the voter's card, `candidate-preview-avatar` with `data-sex`, updates as typed) and `candidate-others` (the other candidates of the chosen ballot); buttons `candidate-form-save`, `candidate-form-save-another` (creation only; keeps ballot and party, clears the rest, focuses the first name), `candidate-form-cancel`, `candidate-modal-close`; errors `candidate-form-error-first-name`, `candidate-form-error-last-name`, and the others beside their field |
| Edit | The same modal filled with the candidate's values; changing the ballot moves it |
| Delete | `candidate-delete-dialog` naming the candidate, `candidate-delete-cancel`, `candidate-delete-confirm` |
| Reorder | Grip drag inside one ballot and the arrows; optimistic, one `PUT .../candidates/order`, rollback with a notice |
| After a save | The page state updates in place from the API answer (the ballots list carries the candidates); party counts follow; no navigation |
| Text, tokens | French and English files, no text in components, no raw colour (the party colour is data) |
| Screens | Frontend.md 1.5 items 10 to 12: the modal fits 1920 × 1080 without scrolling, one column under 1300 px, bottom sheet on a phone; nothing scrolls sideways |

### 3. Tests

Written before the code (the coder never edits them; if one looks wrong, stop and say why):

- API, `api/tests/Acceptance/Slice06/`: `CreateCandidateTest`, `UpdateCandidateTest`, `DeleteCandidateTest`,
  `ReorderCandidatesTest`, `BallotCandidatesTest` (list, cascades, duplicate option); `ListBallotsTest` now expects the
  `candidates` key; helpers `plantCandidate`, `candidateRow`, `candidateRows`; four routes in `Tenancy`.
- Browser: `web/e2e/slice06/candidates.spec.ts` and `web/e2e/support/candidates.ts`.

Helper function names in test files must be unique across the folder (Pest globals).

## Done when

`make lint`, the unit tests, the whole API suite (run alone) and `e2e/slice06`, `e2e/slice05`, `e2e/theme` pass with no
edit of an acceptance test; generated files regenerated; the page and the modal looked at at 1920 × 1080, 1280 × 720
and 360 × 780.

## Not in this slice

The photo (06d); group-scoped ballots (slice 09); the shuffle per voter (slice 12); a candidates import.
