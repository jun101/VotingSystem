# Slice 14 — Turnout, who has not voted, and the dashboard

Brief · 2026-10-11 · branch `slice/14-turnout-and-dashboard` (from `slice/13-opening-and-closing` until it is merged)

## Goal

1. During an election an institution user follows **turnout**: in total, by group and over time; no vote count is
   exposed (FR-RES-01, FR-SEC-03, FR-SEC-04).
2. They see **who has voted and who has not**, never what (FR-VOT-07), filter the voters list by it (FR-VOT-06), and
   export the voters who have not voted to remind them.
3. The dashboard (A02) shows the institution's elections with their live turnout and a "to do" list (FR-NAV-01).

## Requirements covered

FR-RES-01, FR-SEC-03, FR-SEC-04, FR-VOT-06 (has voted filter), FR-VOT-07, FR-NAV-01, NFR-SEC-06 (formula guard on
exports), NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-11)

- Charts are **hand-made SVG with tokens**, no chart library.
- The non-voters list has filters and an **Excel/CSV export** of names and groups (no codes), `FormulaGuard`, owner
  and manager only. The page refreshes by **polling every 15 seconds**.
- Review depth: correctness and security.

## Rules (written in the endpoint files)

| Rule | Value |
|---|---|
| Turnout | `GET /elections/{election}/turnout`: `eligible` (voters), `voted`, `percentage` (one decimal), `by_group` (name, voters, voted), `over_time` (buckets: hour for an election of up to 3 days, day above, in the election's time zone, with the cumulative count) |
| Not a vote count | The numbers come from `participations` only (`voted_at`); nothing reads `votes`, `vote_choices` or `vote_audit` before the election is closed, and never in this slice |
| By group | By group is allowed (FR-SEC-04 forbids results by group, not turnout); a group of fewer than 1 voter is listed with 0 |
| Voted flag | The voters list and the voter object get `has_voted` and `voted_at`; filter `voted` = `yes` or `no`; the voters page shows them |
| Export | `GET /elections/{election}/voters/export?format=xlsx|csv&voted=no|yes|all&group=`: names, groups, identifier, email, phone (the columns of the voters list), no code, `FormulaGuard`, themed header, at most 10 000 rows, owner and manager, 20 an hour; 404 for another institution |
| Dashboard | `GET /dashboard`: counts by status, the open elections with turnout and time left, the scheduled ones with their start, and `todo` items (`voters_not_voted`, `draft_blockers`, `codes_missing`) with the election they concern |
| Who | Owner and manager (a viewer role does not exist yet); tenant isolation as everywhere |
| Refresh | Pages poll every 15 seconds while the election is open |

## Endpoints

New: `docs/api/turnout/GET-elections-{election}-turnout.md`, `GET-elections-{election}-voters-export.md`,
`GET-dashboard.md`. Changed: `GET /elections/{election}/voters` and the voter object (`has_voted`, `voted_at`, filter
`voted`).

## What to build (runs)

- **14a, server:** the four changes above, tenant tests.
- **14b, web:** A12 `/admin/elections/{election}/turnout` (SVG over-time line and per-group bars, the non-voters list
  with filters and export), the voters page flags and filter, the dashboard (A02) with real data, a link from the
  election page; messages in both languages.

## Test ids (browser tests `web/e2e/slice14/turnout.spec.ts`)

| Element | Test id |
|---|---|
| Page | `turnout-page`, `turnout-percentage`, `turnout-voted`, `turnout-eligible` |
| Charts | `turnout-chart-time` (an `svg` with `role="img"` and a text alternative), `turnout-group-{n}` (bar of group n: name, `voted / voters`) |
| Non-voters | `nonvoters-list`, `nonvoter-name-{n}`, `nonvoters-search`, `nonvoters-group` (select), `nonvoters-export-xlsx`, `nonvoters-export-csv` |
| Voters page | `voters-filter-voted` (select: all, yes, no), per card `voter-voted-{n}` |
| Dashboard | `dashboard-election-{n}` (card of an open election), `dashboard-turnout-{n}`, `dashboard-todo-{n}` |

## Not in this slice

Results (15), publication (16), reminder emails (not decided: a later slice if Jun asks).
