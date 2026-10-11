# Slice 13 — done (2026-10-12)

Branch `slice/13-opening-and-closing-tests`. Acceptance tests first (45 API, 7 browser), then code, then review.

## Built
- `POST /elections/{id}/close` and `/extend` (owner, manager; row lock; limiter `election-close`, 20 per hour per user, shared),
  the `election_started` guard on unschedule.
- A05: open card (countdown, end in the election's zone, close and extend), close dialog (title typed), extend dialog,
  closed note; the voter's identity step reads the ballots again so a close shows `vote-closed`.

## Verified
Slice 13 API 45 passed; full API suite passed before the review fixes; `make lint`, `make generate-check` clean;
web unit tests passed; e2e slice13 (7) and slice09 (12) passed on desktop.
Not run: the phone project of the browser tests; the full e2e suite.

## Review (correctness and security, 2026-10-12)
- Security: no blocking finding. Fixed: `confirmation` limited to 200 characters; the close file no longer says the
  response shows `closed_at`.
- Correctness: wrong title on a non-open election answers 409 and "end passed" means `ends_at` not in the future, both
  confirmed right. Fixed: the open card reads the election again once the end has passed; a 409 in a dialog refreshes
  the page; focus goes to the closed note after closing; an empty extend field says "required"; a 429 says how long to
  wait. Written into both endpoint files: order of checks 404, body shape 422, state 409, value 422.
- Two test bodies were corrected by the test author (voter Paul instead of Wideline, who is also eligible for ballot G).

## Decisions taken without Jun
None needing his word. The order of checks was written into the endpoint files.
