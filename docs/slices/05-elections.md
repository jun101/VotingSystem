# Slice 05 — Elections

Brief · 2026-10-08 · branch `slice/05-elections`

## Goal

An owner or a manager creates a draft election (title, dates, time zone, language, options, an optional cover),
edits it, duplicates it, deletes it, and sees every election of the institution as cards with filters by status and
year (screens A03 and A04). A short summary page per election is the place the actions live; the full overview with
its checklist (A05) comes in slice 09.

It is the first slice with a real tenant model: `Election` uses the institution scope and its routes join the
isolation suite (`api/tests/Support/Tenancy.php`).

## Design rule (Jun, 2026-10-08)

**Leave as little free space as possible.** Concretely, for every screen of this slice:

- the cards fill the width of the content area: an auto-fill grid, **3 columns at 1280 px**, 2 at about 900 px, 1 on a
  phone, with a small gap (16 px) and no outer margin beyond the shell's;
- the status tiles and the year filter are **one compact row** above the grid, not a hero band;
- a card is dense: status pill, title, dates, the two figures (ballots, voters), the actions on one line;
- the form is **two columns on a desktop**: the fields on the left, a side panel on the right that carries something
  useful (the schedule in the election's time zone with the duration, the cover, what the voter will see), never white
  space; the side panel goes under the fields on a phone;
- nothing is centred in a narrow column; empty states are one line with the action beside it.

## Requirements covered

FR-ELEC-01, FR-ELEC-02, FR-ELEC-03 (Draft only: a draft is created, edited, deleted), FR-ELEC-06 (the settings are
copied; slices 06 and 07 extend the copy to ballots, candidates and voters), FR-ELEC-07 (the list with filters by
status and year), FR-NAV-04 (cards, no table), FR-CAND-04 (the cover uses the same re-encoder as the logo), NFR-SEC-03
(the new tenant routes join the isolation suite), NFR-UX-01, NFR-UX-03.

## Read before coding

[architecture.md](../design/architecture.md) sections 4.4 and 8 · [database.md](../design/database.md) section 2.2
(`elections`) and section 5 · [frontend.md](../design/frontend.md) · [API conventions](../api/README.md) · the eight
endpoint files below · the mockups [Elections.dc.html](../design/mockups/Elections.dc.html) (A03),
[ElectionNew.dc.html](../design/mockups/ElectionNew.dc.html) (A04) and
[Overview.dc.html](../design/mockups/Overview.dc.html) (A05, for the summary page) · the slice 04 review file and
`api/tests/Support/Tenancy.php`, which this slice extends.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `GET /elections`, `POST /elections` | [elections/](../api/elections/) |
| `GET /elections/{election}`, `PATCH /elections/{election}`, `DELETE /elections/{election}` | [elections/](../api/elections/) |
| `POST /elections/{election}/duplicate` | [elections/](../api/elections/POST-elections-{election}-duplicate.md) |
| `PUT /elections/{election}/cover`, `DELETE /elections/{election}/cover` | [elections/](../api/elections/) |

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Table | One migration creates `elections` with **every column of database.md section 2.2** (the later slices fill the unused ones): `uuid`, `institution_id` (cascade), `parent_election_id` (nullable, set null on delete), `title`, `description`, `starts_at`, `ends_at` (UTC, check `ends_at > starts_at`), `timezone`, `language`, `status`, `cover_file`, `candidate_order`, `results_display`, `opened_at`, `closed_at`, `published_at`, `archived_at`, `created_at`, `updated_at`; the three indexes of the file. Statuses, candidate orders and result displays are PHP enums |
| Model | `Election`: `HasUuid`, `BelongsToInstitution` (so the scope, the route binding by uuid and the 404 for another institution come for free), the enums as casts, UTC dates. A policy extending `TenantPolicy` lets owner and manager do everything; a platform admin has no institution and gets nothing |
| Draft rule | Edit, delete and cover changes answer 409 `election_not_editable` when the status is not `draft`. One place decides it (a method on the model or the policy), so slices 09 and 13 change it in one place |
| Defaults | `timezone` and `language` default from the institution; `candidate_order` `manual`; `results_display` `full`; `status` `draft` (never taken from the request) |
| List | Filters, order, counts and years exactly as `GET-elections.md`. The year is read in the election's time zone; the implementation is free (a derived column kept on save is allowed; a database time zone function is not, the time zone tables may be missing). The counts and the years cost one extra query each |
| Duplicate | Copies the settings listed in `POST-elections-{election}-duplicate.md`, title `Copie de …` / `Copy of …` cut to 200 characters; never the cover, the status dates or the first-round link |
| Cover | The slice 04 `ImageReEncoder` with other sizes: **480 and 960 pixels wide**, ratio kept, never enlarged (the service takes its sizes as a parameter; the logo keeps 64, 160 and 480). Files `{uuid}-{size}.webp` on the `media` disk; the previous cover's files are deleted after the database update (under a row lock, as the logo); deleting an election deletes its cover files. A cover shown on a card is the 480 version |
| Resources | One `ElectionResource` as `GET-elections-{election}.md` shows; `ballots_count` and `voters_count` are `0` for now; no `id` or `*_id` of the database, no `parent_election_id` |
| Rate limits | `elections-write` 60 per hour per user (create, delete, duplicate), 120 per hour for the edit, `election-cover` 20 per hour per user; multiplied by `AUTH_RATE_LIMIT_FACTOR` like the others; the role checks (none here: owner and manager both pass) come before the limiters |
| Isolation suite | `api/tests/Support/Tenancy.php` lists the eight routes in `TENANT_ROUTES`; the coverage tests keep failing on a route without a test or without `auth` and `institution.active` |
| Logs | Nothing logged but the request id and the outcome; never a title, a description or a file name |
| Generated files | `docs/api/openapi.json` and `schema.d.ts` regenerated, never by hand |

### 2. Web

| Item | Requirement |
|---|---|
| Routes | `/admin/elections` (A03), `/admin/elections/new` (A04), `/admin/elections/{election}` (the summary page), `/admin/elections/{election}/edit` (the same form as A04, filled). They replace the "pas encore disponible" page of slice 03 for these paths; `/admin/audit` keeps it |
| A03 list | One compact row: the **status tiles** (`Toutes` always, then one tile per status that has at least one election, each with its count; a tile is a filter, the current one is marked), the **year chips** (from `meta.years`), and a "Nouvelle élection" button at the end. Below, the **card grid** (the design rule above), the first cell of the grid is the "Nouvelle élection" tile (dashed, one line of text) so a new institution sees an invitation instead of an empty page. Filters live in the URL (`?status=…&year=…`) so a reload and the back button keep them; changing a filter does not reload the page |
| Card | Status pill (token colours: draft neutral, scheduled/open accent, closed/published teal, archived muted), title (two lines at most), dates in the election's time zone (`12 au 16 oct. 2026`), `N postes · M électeurs` (0 for now: shown as "Aucun poste · aucun électeur"), the cover as a left or top thumbnail when there is one, and the actions on one line: **Ouvrir** (to the summary page; for a draft the label is **Continuer**), **Dupliquer**, **Supprimer** (a draft only). Delete asks a confirmation naming the election (the `ConfirmDialog` of slice 04) |
| A04 and edit form | Two columns from `lg`: the fields on the left (title, description, start and end as `datetime-local` read **in the chosen time zone**, time zone select, language, candidate order as two radios, results display as two radios, cover picker), the side panel on the right (sticky): the schedule in words ("Lundi 12 octobre 2026 à 08 h 00 → vendredi 16 octobre à 15 h 00, 4 jours et 7 heures de vote, heure d'Haïti", recomputed as the person types), the cover preview, and a four-line reminder of the next steps (ballots, voters, scheduling, codes) as in the mockup. Defaults from the institution. Errors under each field from the message files; focus on the first error after a failed save. The save button and Annuler are at the top right of the page header **and** at the bottom of the form on a phone. After a create, the person lands on the summary page |
| Times | The browser converts between the typed local date-time and UTC with the election's time zone (a small, maintained helper; no heavy library). A start in the past is allowed for a draft. Daylight-saving gaps and overlaps never crash the form: the nearest valid time is used and the schedule line shows it |
| Summary page | The header (status pill, title, dates in words, time zone, language, candidate order, results display), the cover, the actions **Modifier**, **Dupliquer**, **Supprimer** and, for a non-draft, none of the edit ones; a "Ce qui vient ensuite" strip with the next steps shown as not available yet (they open in slices 06 to 09). It is a **dense** two-column page on a desktop (facts left, cover and next steps right) |
| Side menu | "Nouvelle élection" opens A04; the "Élections" entry opens A03 and is current on every `/admin/elections…` page. The "Élection choisie" card and the "Cette élection" section stay as in slice 03 (they get a real election in slice 09) |
| Messages | Every text in the French and English files, none in a component. The six status names in both languages |
| Motion and tokens | Reveal kit of slice 01b on the cards (off under reduced motion); tokens only |
| Accessibility | Labels, `aria-describedby` for errors, the tiles are buttons with `aria-pressed`, the grid is a list, dialogs trap focus and give it back; usable from 320 px by keyboard alone; touch targets of at least 44 px |

### 2b. Names the browser tests rely on

| Where | `data-testid` values |
|---|---|
| A03 | `elections-page`, `elections-tiles`, `tile-all`, `tile-draft`, `tile-scheduled`, `tile-open`, `tile-closed`, `tile-published`, `tile-archived` (each has `data-count` and `data-active` = `true`/`false`), `year-chips`, `year-<year>`, `election-new-button`, `elections-grid`, `election-new-tile`, `elections-empty` |
| Card | `election-card-<n>` (n from 1 in list order), `election-status-<n>`, `election-title-<n>`, `election-dates-<n>`, `election-cover-<n>` (absent without a cover), `election-open-<n>`, `election-duplicate-<n>`, `election-delete-<n>` (draft only) |
| Delete | `election-delete-dialog`, `election-delete-confirm`, `election-delete-cancel`, `election-delete-error` |
| Form | `election-form`, `election-title`, `election-description`, `election-starts`, `election-ends`, `election-timezone`, `election-language`, `election-order-manual`, `election-order-shuffled`, `election-results-full`, `election-results-winners`, `election-save`, `election-cancel`, `election-form-error`, and one error per field `election-<field>-error` (`title`, `description`, `starts`, `ends`, `timezone`) |
| Side panel | `election-schedule` (the schedule in words), `election-duration` (the duration text), `election-cover-input`, `election-cover-preview`, `election-cover-remove`, `election-cover-error`, `election-next-steps` |
| Summary | `election-page`, `election-summary-status`, `election-summary-title`, `election-summary-dates`, `election-summary-timezone`, `election-edit`, `election-duplicate`, `election-delete`, `election-summary-cover` (absent without a cover) |

Words checked by tests, French first: status pills `Brouillon`, `Planifiée`, `En cours`, `Clôturée`, `Publiée`,
`Archivée` / `Draft`, `Scheduled`, `Open`, `Closed`, `Published`, `Archived`; the tile `Toutes` / `All`; the button
`Nouvelle élection` / `New election`; the top bar title `Élections` / `Elections` on A03, `Nouvelle élection` on A04.

## Rules checked by tests

1. Every scenario of the eight endpoint files, one test per scenario.
2. **Isolation:** another institution's election answers 404 with the same body and headers as an unknown one, on
   every route that binds one; the list, the counts and the years never show another institution's; a body that names
   another institution creates nothing there; a duplicate of another institution's election is a 404.
3. **Draft rule:** edit, delete and cover answer 409 `election_not_editable` for every status but `draft` (the tests
   plant elections of each status directly); a duplicate works for every status.
4. **Dates:** stored in UTC; `ends_at` after `starts_at` is checked on the merged values at edit; a start in the past is
   accepted; the year filter follows the election's time zone (an election starting on 1 January 00:30 in Port-au-Prince
   that is still 31 December in UTC counts in the new year, and the reverse).
5. **Counts:** `meta.counts` ignores the filters, `all` excludes archived, the years are newest first and ignore the filters.
6. **Cover:** the same checks as the logo (content, size, pixels, animation, EXIF, metadata, original never kept, the
   previous cover's files deleted, nothing else on disk), at 480 and 960 pixels wide; deleting an election deletes its
   cover files; a duplicate does not copy the cover.
7. **No id leaves the server:** the id-leak test passes on the new routes; no numeric key, no `institution_id`, no
   `parent_election_id` in any response; file names are UUIDs only.
8. The pages pass the accessibility check at 320 and 1280 px in both languages; **the grid has 3 columns at 1280 px and 1
   on a phone, its cards fill the content width (no gap wider than 24 px between a row's last card and the edge), and
   the form's side panel sits beside the fields from `lg`**.

## Acceptance tests

Written before the code, committed on the branch before the code, **not edited during coding**. If one seems wrong,
stop and say why.

| Where | Checks |
|---|---|
| `api/tests/Acceptance/Slice05/` | One file per endpoint (`ListElectionsTest`, `CreateElectionTest`, `ShowElectionTest`, `UpdateElectionTest`, `DeleteElectionTest`, `DuplicateElectionTest`, `ElectionCoverTest`), `ElectionStorageTest` (the cover on disk) and `ElectionYearTest` (the year in the time zone) |
| `api/tests/Support/` | `Accounts.php` gains a planter for elections (any status, any dates); `Tenancy.php` and `Pest.php` get the new routes and the new folder |
| `web/e2e/slice05/` | `elections.spec.ts` (tiles, year chips, filters in the URL, cards, delete, duplicate, empty state), `form.spec.ts` (create, edit, validation, time zone, schedule text, cover, summary page), `quality.spec.ts` (accessibility, **density**, touch size, reduced motion). Helpers in `web/e2e/support/elections.ts` |
| `web/e2e/slice03/` | The tests that expected the "pas encore disponible" page on `/admin/elections` and `/admin/elections/new` are updated with the new tests (the coder does not touch them) |

All earlier tests keep passing.

## Out of scope

- Ballots, parties, candidates, voters, groups (slices 06 to 08); the checklist, scheduling and the other statuses
  (slice 09 and after); the real overview page A05 (slice 09); "reprendre" of ballots, candidates and voters on A04
  (slices 06 and 07); the dashboard cards (slice 14); publishing and the sharing image (slice 16); the audit log of
  these actions (slice 17).
- A date and time picker component of our own: the browser's `datetime-local` is used.
- Search by title.

## Done when

1. `make check` passes entirely, with every earlier test.
2. A person does the whole path in a browser, in French and in English, at desktop and phone width: create a draft with
   a cover, see it in the grid, edit it, duplicate it, delete the copy; the filters keep their place after a reload.
3. The isolation suite fails when a route, a model or a table is added without a test.
4. `docs/api/openapi.json` and `schema.d.ts` are up to date.
5. CI passes; no reviewer finding is left open. Review: `correctness-reviewer` and `security-reviewer` (with the
   id-leak checklist); the date and time zone handling, the year filter and the cover are the places to read hardest.

## Decisions taken in this brief

Confirmed by Jun on 2026-10-08.

- **Cover:** built now, with the slice 04 re-encoder (480 and 960 pixels wide).
- **Duplicate:** copies the settings now; slices 06 and 07 extend it to ballots, candidates and voters; the "reprendre"
  choices of A04 appear then.
- **Card click:** a short summary page; slice 09 turns it into the overview with the checklist.
- **List layout:** count tiles and year chips on one compact row, then a 3-column card grid with a "Nouvelle élection"
  tile as its first cell.
- **Design rule:** as little free space as possible (the section above).

Proposed here, for Jun to veto at the checkpoint: owners and managers can do everything on elections; the default
list hides archived elections and `Toutes` does not count them (the mockup's `Toutes · 5` includes the archived one;
the specification, FR-ELEC-03, hides them by default); a tile appears only for a status that has elections; a
duplicate keeps the dates of the source; a start in the past is allowed for a draft.
