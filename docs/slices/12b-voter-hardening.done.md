# Slice 12b — done (2026-10-12)

Branch `slice/12b-voter-hardening`, stacked on `slice/13-opening-and-closing-tests`.

## Built
- A valid code opens a session even when 300 wrong codes are counted for the election; wrong codes still answer 429.
  On that path only the election counter is given back: the wrong code stays counted on the address, so the
  30-per-10-minutes limit still applies (found by the security review, fixed, test added).
- One live session per voter: the cache holds the hash of the voter's current token; every read compares it.
- Acceptance test of slice 12 scenario 10 changed to expect 401 (not 409) from the earlier sessions of a voter,
  as a consequence of the decision; endpoint files updated.

## Verified
Slice12b (10 tests), Slice12, the Feature suites of voting; full API suite passed before the review fix (2195 passed
with the one test change above); `make lint` clean. Not run: the browser tests of the voting flow after this change.

## Review
Security: one blocking finding (address counter given back on a saturated election), fixed. One note (RateLimiter
decrement can start a new window at -1 if a window expires between increment and give-back): pre-existing, left.
