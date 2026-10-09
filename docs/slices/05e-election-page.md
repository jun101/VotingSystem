# Slice 05e — The election page and form

Written 2026-10-09 · rules: [frontend.md](../design/frontend.md) "Large screens, icons and the date picker" points 7 and 8 ·
mockup [material-election](../design/mockups/material-election.html). Same branch and pull request as 05b to 05d.

## Do

Redo the summary page (`ElectionSummary.tsx`, `/admin/elections/[election]`) and restyle the form (`ElectionForm.tsx`, `/new`
and `/edit`) as in the rules. Keep every existing test id and behaviour (`election-page`, `election-summary-title`,
`-status`, `-dates`, `-timezone`, `-cover`, `-error`, `election-edit`, `election-duplicate`, `election-delete`,
`election-next-steps`, the form's ids). Only real data: no ballot-secrecy row, no invented figures; positions, voters and
ballots are 0 (the ballot count is never shown before an election is closed).

New ids on the summary page: `election-hero` (the header band, gradient, 160 to 200 px tall), `election-hero-badge`,
`election-chip-dates`, `election-chip-duration`, `election-chip-language`; `election-zone` (the white zone); cards
`election-card-calendar`, `election-card-settings`, `election-card-cover`, `election-card-facts`, `election-card-steps`, each
with a header `election-card-header-KEY` (64 px, gradient, icon, title); `election-day-start` and `election-day-end` (the
day badges, with the day number in text), `election-duration-line`; settings rows `election-setting-language`,
`-order`, `-results`; `election-step-1` to `-4` with `data-state="soon"`; facts `election-fact-positions`, `-voters`,
`-ballots`.
On the form: the white zone `election-form-zone`, and a header on each section `election-section-header-info|calendar|settings|cover`.

Texts in the message files; icons from `Icon.tsx`; motion as in the mockup, stopped by "reduce motion".

## Acceptance tests

`web/e2e/theme/election.spec.ts`.
