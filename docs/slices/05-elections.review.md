# Slice 05 — Elections: review

2026-10-08 · one round, two reviewers (correctness; security with the id-leak checklist).

## Security: no blocking finding, four fixed

| # | Finding | Fix |
|---|---|---|
| 1 | A cover upload racing the delete of its election left two files on the public media disk | The locked re-read finds no row: 404 inside the transaction, the new files are deleted |
| 2 | Removing a cover took no lock and wrote from a stale model | Read, draft check and clear in one transaction with a row lock; files deleted after commit |
| 3 | A valid date with an offset could pass the DATETIME range in UTC: 500 | A UTC year outside 1000-9999 answers 422 `date` |
| 4 | Two deletes at once both answered 204; an edit racing a delete answered 200 | A missing locked row answers 404; the edit locks and re-reads |

Checked clean: isolation of the eight routes, mass assignment, SQL bindings, cover re-encode and limits, no numeric id
anywhere, logs, Octane state, limiters.

## Correctness: one blocking (closed), four should-fix, five notes — all fixed

- Blocking: two slice 03 tests were edited by the coder's commit. Approved by Jun (a stand-in route that slice 05 does
  not list; `data-count` allowed in the "no number in a data value" check because a count is not an id).
- Dates sent with fractions of a second passed `after_start` but were stored as whole seconds, so the CHECK gave 500:
  fractions are cut before comparing.
- A repeated local hour (clock goes back) read as the second occurrence east of UTC (Paris): now always the first.
- The edit form resent both dates rebuilt from minutes, moving an unchanged instant by an hour: dates are sent only
  when changed.
- Edit racing edit could break the CHECK (500): re-read and re-check under the lock (422).
- A zone the browser does not know was converted as UTC: the select offers only known zones, an existing one shows an
  error line and no dates are sent.
- The list stopped at 100 with a notice (Jun: a "Show more" button).

Tests added: 16 race tests (`api/tests/Feature/ElectionRacesTest.php`), unit tests for the date fields, zones, Paris and
Auckland overlaps, and the Show more button.
