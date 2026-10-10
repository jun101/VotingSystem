# Slice 06a — Ballots

Brief · 2026-10-10 · branch `slice/06-ballots-parties-candidates`

## Goal

An owner or a manager adds the positions of a draft election (ballots), changes them, deletes them and puts them in
order, on the ballots page (screen A06). Parties and candidates come in 06b and 06c; the page is already laid out
for them (mockup, rail and card bodies).

Task 06a of the cut of slice 06 (06.0 mockup done; 06b parties; 06c candidates; 06d photos and logos; 06e close).

## Requirements covered

FR-BAL-01, FR-BAL-02, FR-ELEC-06 (the ballots are copied by a duplicate), FR-SEC-06 (a non-draft election's ballots do
not change), FR-NAV-02 (reached from the election page), NFR-SEC-03, NFR-SEC-08, NFR-UX-02, NFR-UX-03.

## Read before coding

[database.md](../design/database.md) `ballots` · [architecture.md](../design/architecture.md) sections 4.4 and 8 ·
[frontend.md](../design/frontend.md) 1.4 (Large screens… items 9 to 11) · the mockup
[material-ballots.html](../design/mockups/material-ballots.html) · the five endpoint files in
[ballots/](../api/ballots/) and the changed
[duplicate](../api/elections/POST-elections-{election}-duplicate.md) file · `api/tests/Support/Tenancy.php`
(five new routes already registered) · the slice 05 code (`Election` model, policy, controllers, resources, the
elections page) which this slice follows.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `GET /elections/{election}/ballots` | [GET-elections-{election}-ballots.md](../api/ballots/GET-elections-{election}-ballots.md) |
| `POST /elections/{election}/ballots` | [POST-elections-{election}-ballots.md](../api/ballots/POST-elections-{election}-ballots.md) |
| `PUT /elections/{election}/ballots/order` | [PUT-elections-{election}-ballots-order.md](../api/ballots/PUT-elections-{election}-ballots-order.md) |
| `PATCH /ballots/{ballot}` | [PATCH-ballots-{ballot}.md](../api/ballots/PATCH-ballots-{ballot}.md) |
| `DELETE /ballots/{ballot}` | [DELETE-ballots-{ballot}.md](../api/ballots/DELETE-ballots-{ballot}.md) |
| `POST /elections/{election}/duplicate` (ballots part) | [duplicate](../api/elections/POST-elections-{election}-duplicate.md) |

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Table | One migration creates `ballots` with every column of database.md `ballots` plus `uuid`, `created_at`, `updated_at`: `institution_id` (cascade), `election_id` (cascade), `title` (200), `description` (text, null), `position` (smallint), `seats` (tinyint, default 1, check `seats >= 1`), `allow_blank` (bool, default true), `scope` (10, default `general`), `result_note` (text, null). Index `(election_id, position)`. The `app` database account gets its rights as for the other tables |
| Model | `Ballot`: `HasUuid`, `BelongsToInstitution` (so scope, route binding by UUID and 404 for another institution come for free), `scope` as a PHP enum with `general` only for now. `Election` gets `ballots()` (ordered by `position`, then `id`) |
| Policy | Extends `TenantPolicy`: owner and manager do everything; a ballot is changeable only if its election is a draft (else 409 `election_not_editable`) |
| Create | Position = last + 1 inside one transaction that locks the election row; the 50 ballot limit is checked in the same transaction (409 `ballot_limit_reached`) |
| Delete | One transaction: delete, then renumber the following positions so there is no gap |
| Reorder | One transaction that locks the election's ballots; the request must be exactly the set (422 `ballots: set_mismatch`, the same message for unknown and foreign UUIDs); then `position` = index + 1 |
| Resource | `BallotResource` as in the list file: `id` (the UUID), no `*_id`, no `election`. `candidates_count` is `0` (06c fills it) |
| Election counts | `ballots_count` in the election resource and the list becomes `withCount('ballots')`; no N+1 on the list |
| Duplicate | Copies every ballot (new UUIDs) in the same transaction as the election |
| Rate limits | Writes 120 an hour per user; reorder 240 an hour per user (a named limiter each) |
| Isolation | The five routes are in `Tenancy::TENANT_ROUTES`; the `ballots` table joins the isolation suite as the framework of slice 03 asks |

### 2. Web — the ballots page `/admin/elections/{election}/ballots`

Follow the mockup and frontend.md 1.4 items 9 to 11. This task builds the ballots part. The party card and the
"À vérifier" card of the rail are built with their data in 06b and 06c; in 06a the rail holds one card with the line
"Les partis et les candidats arrivent aux étapes suivantes" and the `Bientôt` tag, so the rail is not empty and nothing
is invented.

| Item | Requirement |
|---|---|
| Route | `/admin/elections/{election}/ballots`, client page behind the admin shell. Back link to the election page. Entered from the election page: step 1 of the next steps (`election-step-1`) becomes a link and loses its `Bientôt` tag; the "Postes et électeurs" fact tile's ballots figure is the real count |
| Header band | Status badge, title "Postes et candidats", election title, chips: count of postes (`ballots-count`, "0 poste"/"1 poste"/"n postes"), candidate order. "Ajouter un poste" is the coral main action with its icon beside the text (`ballots-add`) |
| Zone | One white zone (`ballots-zone`) with a title and the reorder hint; an `auto-fill` grid: floor 270 px under 1600 px, 360 px from 1600 px (3 columns at 1280 and 1920, 4 at 2560, 1 on a phone, 2 at 1024) |
| Card | `ballot-card-{n}` (n is the 1-based position). Coloured header (the tone cycles through the mockup's four gradients by position), grip (`ballot-grip-{n}`), title (`ballot-title-{n}`, wraps to two lines), up and down buttons (`ballot-up-{n}`, `ballot-down-{n}`; the first up and the last down are `disabled`). Body: tags seats (`ballot-seats-{n}`: "1 siège", "n sièges") and blank vote (`ballot-blank-{n}`: "Vote blanc" / "Sans vote blanc"); an empty-candidates block (icon and "Aucun candidat" with "Les candidats arrivent à l'étape suivante" in 06a); footer with edit (`ballot-edit-{n}`) and delete (`ballot-delete-{n}`) |
| Creation tile | `ballot-new-tile`, dashed, the last cell of the grid, also the only content of an empty election |
| Form | A dialog on a phone, an inline panel at the top of the zone on a desktop (`ballot-form`): title (`ballot-form-title`, required), description (`ballot-form-description`), seats (`ballot-form-seats`, number 1 to 20), blank vote (`ballot-form-blank`, checkbox, checked), `ballot-form-save`, `ballot-form-cancel`. A field error shows beside the field (`ballot-form-error-title`); the form stays open |
| Delete | Confirmation dialog `ballot-delete-dialog` that names the ballot, with `ballot-delete-cancel` and `ballot-delete-confirm` |
| Reorder | Drag the grip onto another card (pointer), or the arrows (keyboard, phone). The list reorders at once (optimistic) and one `PUT .../order` saves it; on failure the list returns to the saved order with an error notice. Use native pointer events or a small library already needed elsewhere; add no dependency over 10 KB without asking |
| Rail | `ballots-rail`: beside the zone from 1600 px (340 px), under it as a row below |
| Phone | Frontend.md item 11: nothing scrolls sideways, header band stacked, buttons full width |
| Text | French and English message files, no text in a component. Plurals follow the existing election card |
| Tokens | No raw colour in a component |
| Errors | A 409 `election_not_editable` shows the notice "Cette élection ne peut plus être modifiée" and reloads the list |
| Election card | The card shows "Aucun poste" / "1 poste" / "n postes" |

### 3. Tests

Acceptance tests, written before the code (the coder never edits them; if one looks wrong, stop and say why):

- API: `api/tests/Acceptance/Slice06/` — `ListBallotsTest`, `CreateBallotTest`, `UpdateBallotTest`,
  `DeleteBallotTest`, `ReorderBallotsTest`, `DuplicateBallotsTest`; helpers `plantBallot`, `ballotRow`, `ballotRows`
  in `api/tests/Support/Accounts.php`; five routes in `Tenancy::TENANT_ROUTES`.
- Browser: `web/e2e/slice06/ballots.spec.ts` and `web/e2e/support/ballots.ts`.

Tests written by the coder: unit tests of the web components (form validation, reorder move helper, plural text),
and the isolation-suite entry for the new table if the suite needs one.

## Done when

`make check` is green; the two acceptance folders pass with no edit; `docs/api/openapi.json` and
`web/src/lib/api/schema.d.ts` are regenerated by the generator, not by hand; the page was looked at at 1920 × 1080,
1280 × 720 and 360 × 780 (screenshots with Playwright).

## Not in this slice

Candidates, parties and photos (06b to 06d); group scope (slice 09); locking at scheduling (slice 09); a
checklist before scheduling (slice 09).
