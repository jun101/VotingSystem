# Slice 13 — Opening, closing early and extending

Brief · 2026-10-11 · branch `slice/13-opening-and-closing` (from `slice/12-voting-flow` until slice 12 is merged)

## Goal

1. An open election can be **closed early** by the institution, with a typed confirmation (FR-ELEC-03 Closed, FR-ELEC-05).
2. An open or scheduled election can have its end **extended** (FR-ELEC-05).
3. The summary page (A05) shows the live state of an open election with its countdown, and the two actions.
4. A scheduled election whose start has passed can no longer be unscheduled (the open item of slice 09).
5. The voter pages tell a person who is mid-session when the election closes, in the voter's language.

The minute command `elections:advance` and the time check of the vote transaction exist since slice 12
(FR-ELEC-05, NFR-OPS-03); this slice adds what a person does with them and tests the lateness cases.

## Requirements covered

FR-ELEC-03 (Open, Closed), FR-ELEC-05, FR-VOTE-02, NFR-OPS-03, FR-CRED-05 (files are purged when the election closes),
NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-11)

- **Owner and manager** may close early or extend; closing early asks the person to **type the election's title**; a
  closed election cannot be reopened.
- Review depth for this slice: correctness and security.

## Rules (written in the endpoint files)

| Rule | Value |
|---|---|
| Close early | `POST /elections/{election}/close` `{confirmation}`: the election is `open`; `confirmation` must equal the title (after trimming); status `closed`, `closed_at` now; 409 `election_not_open` when it is not `open`; 422 `confirmation: mismatch` |
| Extend | `POST /elections/{election}/extend` `{ends_at}`: the election is `scheduled` or `open`; the new end is after the current end (shortening goes through close early), within 30 days of the current end; the election keeps its status; 422 `ends_at: not_after` / `too_far`; 409 `election_not_extendable` for other statuses |
| Who and limits | Owner and manager; 20 requests an hour per user (`election-close`) |
| After the end | A vote after `ends_at` is refused 409 `election_not_open` even if the status is still `open` (the scheduler is late); a session in progress at the close answers `election_not_open` on its next call |
| Unschedule | `POST /elections/{election}/unschedule` answers 409 `election_started` when `starts_at` has passed (even if the status is still `scheduled`) |
| Closing effects | Files of slips and codes of the election are deleted by the daily `files:purge` (already built); nothing else is deleted; counts become readable only from slice 15 |
| Logs | Outcome lines only (the audit log is slice 17) |

## Endpoints

New files `docs/api/elections/POST-elections-{election}-close.md` and `POST-elections-{election}-extend.md`; changed:
`POST /elections/{election}/unschedule` (`election_started`).

## What to build (one run)

Server: the two endpoints, their requests, the limiter, the unschedule guard, error codes and messages. Web: on A05 for
an `open` election a card with the end, the countdown and the buttons `election-close-button` and `election-extend-button`
with their dialogs; the status chip; on the voter pages a clear closed message when a call answers `election_not_open`.

## Test ids (browser tests `web/e2e/slice13/closing.spec.ts`)

| Element | Test id |
|---|---|
| Open card (A05) | `election-open-card`, `election-open-countdown`, `election-open-ends` |
| Close | `election-close-button`, `close-dialog`, `close-confirmation` (input), `close-confirm` (disabled until the title is typed), `close-cancel` |
| Extend | `election-extend-button`, `extend-dialog`, `extend-date` (the date picker of the form), `extend-confirm`, `extend-error` |
| Status | `election-summary-status` gets `data-status` `closed` after closing; `election-closed-note` text |
| Voter closed message | `vote-closed` (as slice 12) shown when a session call answers `election_not_open` |

## Not in this slice

Reopening (never), results (15), publication (16).
