# Slice 15 — done (2026-10-12)

Branch `slice/15-results`. Acceptance tests first (50 API, 6 browser), then code (15a server, 15b web), then review.

## Built
- `GET /elections/{id}/results` (counts from `votes` and `vote_choices` only, never `vote_audit`; winners, ties never broken,
  blank votes, bulletin check, `second_round` link, `election.closed_at`), `PUT /ballots/{id}/result-note`,
  `POST /elections/{id}/second-round` (one transaction; `ballots.source_ballot_id`, unique).
- A13 results page: card per ballot with animated bars, winners, tie notice, check, note editor, second-round dialog.

## Verified
Slice15 acceptance (50), full API suite passed on the 15a run (one pre-existing flaky email-design test: a random access
code that looks like a hex colour), `make lint`, web unit tests (600), browser e2e slice15 (6), slice13 and slice14.
Not verified by eye: 1280 and 1920 px layouts, phone, reduced motion.

## Review (correctness and security)
No blocking finding. Fixed: the note on a public page can no longer carry C1 controls, bidirectional or zero-width
characters; percentages on the page use the same "never 100 before all" rule as the server; the page shows the closing
time, not the scheduled end; the note limit counts the trimmed text; a failed re-read after a 409 tells the person.
Written down: a body without `note` clears the note.

## Known, by design
A group ballot of one voter shows that voter's choice (eligible, cast and votes of one). Slice 16's public page repeats
it: see the warning in the slice 16 brief.

## Decisions taken without Jun
Candidates tied on votes keep the election's position order; `candidate_order` is not applied to results.
