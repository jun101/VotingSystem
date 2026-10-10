# Slice 06b — Parties

Brief · 2026-10-10 · branch `slice/06-ballots-parties-candidates`

## Goal

An owner or a manager registers the parties (slates, lists) of a draft election **in a modal, without leaving the
ballots page**, edits them and deletes them. Parties are optional; candidates choose one in 06c. The logo comes in 06d.

## Requirements covered

FR-CAND-01, FR-ELEC-06 (parties are copied by a duplicate), FR-SEC-06, NFR-SEC-03, NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Read before coding

[database.md](../design/database.md) `parties` · [frontend.md](../design/frontend.md) 1.5 items 9 to 12 · the mockups
[material-ballots.html](../design/mockups/material-ballots.html) (the parties card) and
[material-party-modal.html](../design/mockups/material-party-modal.html) · the four endpoint files in
[parties/](../api/parties/) and the changed [duplicate](../api/elections/POST-elections-{election}-duplicate.md) file ·
the slice 06a code (`Ballot` model, policy, controller, requests, resource, the ballots page) which this slice follows.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `GET /elections/{election}/parties` | [GET-elections-{election}-parties.md](../api/parties/GET-elections-{election}-parties.md) |
| `POST /elections/{election}/parties` | [POST-elections-{election}-parties.md](../api/parties/POST-elections-{election}-parties.md) |
| `PATCH /parties/{party}` | [PATCH-parties-{party}.md](../api/parties/PATCH-parties-{party}.md) |
| `DELETE /parties/{party}` | [DELETE-parties-{party}.md](../api/parties/DELETE-parties-{party}.md) |
| `POST /elections/{election}/duplicate` (parties part) | [duplicate](../api/elections/POST-elections-{election}-duplicate.md) |

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Table | One migration creates `parties`: `uuid`, `institution_id` (cascade), `election_id` (cascade), `name` (100), `name_key` (100, trimmed and lower-cased, unique with `election_id`), `acronym` (15, null), `colour` (char 7), `logo_file` (uuid, null), `created_at`, `updated_at`. The `app` account gets its rights as for the other tables (run with the `migrate` service, not `artisan migrate` as `app`) |
| Model | `Party`: `HasUuid`, `BelongsToInstitution`. `Election::parties()` |
| Policy | As `BallotPolicy`: a party changes only while its election is a draft (409 `election_not_editable`) |
| Rules | Name 1 to 100 after trimming, unique in the election ignoring case (422 `name: unique`; the unique index is the backstop and its violation is also that 422); acronym 1 to 15 or null; colour `#RRGGBB` any case, stored upper case (rule name `hex_colour`) |
| Create | One transaction locking the election row; at most 30 parties (409 `party_limit_reached`) |
| Resource | `PartyResource` as in the list file: `id` is the UUID, `logo` is `null`, `candidates_count` is `0`; no `*_id`, no `name_key`, no `logo_file` |
| Duplicate | Copies every party (name, acronym, colour; new UUIDs; no logo) in the same transaction |
| Rate limits | Writes 120 an hour per user (a named limiter) |
| Isolation | The four routes are in `Tenancy::TENANT_ROUTES`; the table joins the isolation suite |

### 2. Web — the parties card and the party modal

On the ballots page. Follow the mockups and frontend.md item 12.

| Item | Requirement |
|---|---|
| Header band | The chip `parties-count` ("0 parti", "1 parti", "n partis") joins the chips; a glass button `parties-add` ("Ajouter un parti") opens the modal |
| Card | `parties-card` replaces the 06a placeholder in the rail: header with a count, one row per party (`party-row-{n}`, n from 1, in the order the API gives), each with the colour swatch showing the acronym or the first two letters of the name (`party-swatch-{n}`, background from the data), name (`party-name-{n}`), acronym (`party-acronym-{n}`), candidate count (`party-count-{n}`, "Aucun candidat" until 06c), edit (`party-edit-{n}`) and delete (`party-delete-{n}`); the button `party-new` ("Nouveau parti") under the rows; with no party, `parties-empty` (one sentence) |
| Modal | `party-modal` (a real dialog, frontend.md item 12): title, `party-form-name`, `party-form-acronym`, eight colour choices as radio inputs `party-form-colour-{HEX}` (5468D4, C2410C, 0F766E, 7C5BD0, A8349A, 8A5A00, 1E2A5A, 9A2A0A; the first is checked on a new party), `party-form-save` ("Ajouter" / "Enregistrer"), `party-form-save-another` (creation only), `party-form-cancel`, `party-modal-close`. Field errors beside the field (`party-form-error-name`); the modal stays open on an error |
| Editing | The same modal, filled with the party's values |
| Delete | Confirmation dialog `party-delete-dialog` naming the party, `party-delete-cancel`, `party-delete-confirm`; it says the candidates stay and become independent |
| Behaviour | Saving closes the modal, the card and chip update in place, the URL does not change; focus returns to the button that opened the modal; Escape closes; focus stays inside; the page behind does not scroll; on a phone it is a bottom sheet |
| Text | French and English message files; no text in a component; no raw colour in a component (a party's own colour is data, applied as a style value; the eight choices are constants of the form) |
| Errors | 409 `party_limit_reached` and `election_not_editable` show their notice in the modal or page |

### 3. Tests

Written before the code (the coder never edits them; if one looks wrong, stop and say why):

- API: `api/tests/Acceptance/Slice06/` — `ListPartiesTest`, `CreatePartyTest`, `UpdatePartyTest`, `DeletePartyTest`,
  `DuplicatePartiesTest`; helpers `plantParty`, `partyRow`, `partyRows` in `api/tests/Support/Accounts.php`; four routes
  in `Tenancy::TENANT_ROUTES`.
- Browser: `web/e2e/slice06/parties.spec.ts` and `web/e2e/support/parties.ts`.

Tests written by the coder: unit tests of the colour choice, the swatch text and the plural text.

## Done when

`make lint`, the unit tests and both acceptance folders pass with no edit; `docs/api/openapi.json` and
`web/src/lib/api/schema.d.ts` are regenerated by the generator; the page was looked at at 1920 × 1080, 1280 × 720 and
360 × 780, with the modal open.

## Not in this slice

The logo upload (06d); the party choice on a candidate and the candidate count (06c).
