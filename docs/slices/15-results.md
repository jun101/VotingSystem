# Slice 15 — Results

Brief · 2026-10-11 · branch `slice/15-results` (from `slice/14-turnout-and-dashboard` until it is merged)

## Goal

After an election is closed an institution user sees, per ballot, the votes and percentages, the blank votes, the
winners, ties and the bulletin check; writes a public note; and can create a second round from a tied ballot
(FR-RES-02, FR-RES-03, FR-RES-09, FR-SEC-03, FR-SEC-04, FR-SEC-05). Publication (public page, sharing, PDF) is
slice 16.

## Requirements covered

FR-RES-02, FR-RES-03, FR-RES-09, FR-SEC-03, FR-SEC-04, FR-SEC-05, FR-SEC-06, FR-SEC-09 (results never read
`vote_audit`), NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-11)

- **Percentage** of a candidate = their votes over **all the votes cast on that ballot, blank included**; the blank
  votes have their own line.
- A tie for the last seat is **reported, never broken**; the second round copies the voters and groups and holds only
  the tied ballot and the tied candidates, as a draft linked to the first election.
- Review depth: correctness and security.

## Rules (written in the endpoint files)

| Rule | Value |
|---|---|
| When | Only for `closed`, `published` and `archived` elections: 409 `results_not_available` otherwise, for everyone (FR-SEC-03); owner and manager |
| Counting | From `votes` and `vote_choices` only; never `vote_audit`; per ballot: `cast` (votes), `blank` (votes with no choice), per candidate `votes` and `percentage` (one decimal, of `cast`) |
| Seats | A ballot with `seats` n: the n candidates with most votes win; if candidates tie for the last seat(s), `tie` lists them with the seats left, and `winners` holds only the ones above the tie |
| Multi-seat votes | A voter may tick up to `seats` candidates on a paper: percentages are of papers (`cast`), so they can sum above 100 |
| By group | A general ballot's result is never broken down by group; a group ballot's result is the result of its groups (its `groups` are named) |
| Turnout | Per ballot `eligible` (voters eligible for it, FR-BAL-05) and `voted` (of those, with a participation) |
| Bulletin check | `check`: `recorded` = `cast`, `expected` = `voted` of the ballot, `ok` when equal (FR-SEC-05) |
| Public note | `PUT /ballots/{ballot}/result-note` `{note}` (null clears; up to 1 000 characters; control characters removed), closed, published or archived; shown in the result and, in slice 16, on the public page |
| Second round | `POST /elections/{election}/second-round` `{ballot}`: the ballot has a tie; a new draft election titled "Second tour – {title}" (truncated), same institution, `parent_election_id` the election, same settings, that one ballot with the tied candidates (not the others), the same voters and groups (no codes, no votes); 201; 409 `ballot_not_tied`; one second round per ballot (409 `second_round_exists`) |
| Limits | Result reads: the shared limit; note 60 an hour; second round 20 an hour |

## Endpoints

New files in `docs/api/results/`: `GET-elections-{election}-results.md`, `PUT-ballots-{ballot}-result-note.md`,
`POST-elections-{election}-second-round.md`.

## What to build (runs)

- **15a, server:** the three endpoints, the counting action, tests including ties, blanks, multi-seat, group ballots,
  the check, no read of `vote_audit`.
- **15b, web:** A13 `/admin/elections/{election}/results` (a card per ballot with animated bars from tokens, winners,
  ties, blank votes, the check, the note editor, the second-round button and dialog), a link from the election page
  once closed; messages in both languages.

## Test ids (browser tests `web/e2e/slice15/results.spec.ts`)

| Element | Test id |
|---|---|
| Page | `results-page`, `results-unavailable` (election not closed) |
| Ballot card `{n}` from 1 | `result-ballot-{n}`, `result-candidate-{n}-{m}` (name, votes, percentage), `result-blank-{n}`, `result-winner-{n}-{m}`, `result-tie-{n}`, `result-check-{n}` (`data-ok`) |
| Note | `result-note-{n}` (textarea), `result-note-save-{n}` |
| Second round | `result-second-round-{n}` (only for a tie), `second-round-dialog`, `second-round-confirm`, `second-round-link` (goes to the new election) |

## Not in this slice

Publication, public page, sharing image, PDF report (16).
