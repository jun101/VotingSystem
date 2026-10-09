# Slice 05d — Dashboard redesign

Written 2026-10-09 · rules: [frontend.md](../design/frontend.md) "Large screens, icons and the date picker" point 5 ·
mockups [material-dashboard-empty](../design/mockups/material-dashboard-empty.html),
[material-dashboard](../design/mockups/material-dashboard.html). Same branch and pull request as 05b and 05c.

## Do

Redo `/admin` (`components/admin/Dashboard.tsx` and what it needs) as in the mockups, on the real data that exists
(slice 05): the page fetches on the server (`lib/api/server.ts`), the components receive data.

- **Welcome band** `dashboard-welcome` (keeps its test id and text), `dashboard-institution`, quick actions
  `dashboard-quick-new-election` (to `/admin/elections/new`, coral), `dashboard-quick-invite` (to `/admin/institution`),
  `dashboard-quick-institution` (to `/admin/institution`).
- **White zone** `dashboard-zone` around the cards; title and today's date.
- **Cards** (keep the test ids `dashboard-card-open-election|todo|figures|activity|latest`, add
  `dashboard-card-getting-started` and `dashboard-card-institution`), each with a header
  `dashboard-card-header-KEY` (68 px, gradient, icon, title):
  - open election: the open election (title, end, participation bar, positions/voters/ballots tiles) or the empty state
    with `dashboard-create-election` (link to `/admin/elections/new`, kept);
  - to do: `dashboard-todo-N` rows for up to 3 drafts (link to the draft's edit page) and an election starting within 7
    days; empty text otherwise;
  - key figures: `dashboard-figure-elections` (count of non-archived elections), `dashboard-figure-voters`,
    `dashboard-figure-ballots` (0 for now; never a vote count before an election is closed);
  - activity: stays empty ("Aucune activité pour le moment");
  - latest elections: `dashboard-latest-N` for the 3 newest (title, date, status chip with text);
  - getting started (while not all done): ring `dashboard-progress` ("0 %", "33 %", "67 %"), steps `dashboard-step-email`,
    `dashboard-step-institution` (logo present), `dashboard-step-election` with `data-done="true"` when done; once all
    three are done, `dashboard-card-institution` replaces it (user count, two-factor state).
- Grid `auto-fill` from 300 px below 1600 px, from 380 px at 1600 px and up (as the elections list); cards at least
  300 px tall; one column on a phone; floating icon circle on empty cards; ring, bars and card entrance animations as in
  the mockups, all stopped by "reduce motion".
- Texts in the fr and en message files; icons from `Icon.tsx` (add what is missing: rocket, pulse, mail).
- Existing tests keep passing (`slice03/shell.spec.ts` reads `dashboard`, `dashboard-welcome`, `dashboard-institution`,
  the five card ids, `dashboard-create-election`, and forbids tables).

## Acceptance tests

`web/e2e/theme/dashboard.spec.ts`.
