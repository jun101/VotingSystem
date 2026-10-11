# Slice 12 — The voting flow

Brief · 2026-10-11 · branch `slice/12-voting-flow` (from `staging`)

## Goal

1. A voter opens the personal link or the election's voting address, enters the code, confirms who they are, fills the
   ballots they are eligible for, reviews, and confirms; the vote is recorded once and cannot change (FR-VOTE-01 to 08).
2. The vote, the participation and the encrypted audit record are written in one transaction with a row lock, a time
   check and a unique key (FR-SEC-01, 02, 07, 08).
3. Elections open and close by time through a minute command (the minimum slice 13 builds on), so a scheduled
   election can be voted in.
4. Reissuing a code is refused for a voter who has voted (FR-CRED-03).

## Requirements covered

FR-VOTE-01, 03, 04, 05, 06, 07, 08; FR-SEC-01, 02, 06, 07, 08; FR-CRED-03 (voted refusal); FR-ELEC-05 (the time check);
NFR-SEC-02, NFR-SEC-04, NFR-SEC-05, NFR-PERF-01, NFR-UX-02, NFR-UX-03. Not here: the countdown and closed screens'
admin side, close early and extend (13), turnout and who has not voted (14), results (15).

## Jun's decisions (2026-10-11)

- Voter pages use the **election's language with a FR/EN switch** on every voting screen.
- Wrong codes are counted on failures only: **30 per 10 minutes per IP address and election**, and **300 per hour per
  election** (strict).
- After a vote: confirmation with the date and time, a visible **10 second countdown** back to code entry, and a
  "next voter" button that returns at once; the session ends on submission.
- Review depth: correctness, security and id-leak, then a **second security pass** after the fixes.

## Rules (written in the endpoint files)

| Rule | Value |
|---|---|
| Public information | `GET /vote/{election}`: title, institution name, period, `phase` (`before`, `open`, `closed`), language, and `results` (the public page's address) once published. A draft election is 404 |
| Phase | `open` only when the status is `open` AND the server time is between `starts_at` and `ends_at`; `before` for `scheduled` or an `open` election whose start has not come; `closed` for `closed`, `published`, `archived` or time past the end |
| Code entry | `POST /vote/{election}/session` `{code}`; the code is normalised (upper case, hyphens and spaces ignored); unknown, another election's or malformed → 401 `invalid_code` (same answer, same cost); already voted → 409 `already_voted`; not open → 409 `election_not_open` with `phase` |
| Session | A random 256-bit token in a cookie `vote_session` (`HttpOnly`, `Secure` outside local, `SameSite=Strict`, `Path=/api/v1/vote`); the server keeps `{voter, election, expires_at, voted_at?}` in the cache for 15 minutes at most (`votes.session_minutes`), compared to the application clock; one session per code use; ends on submission |
| Identity and ballots | `GET /vote/{election}/ballots`: the voter's name and group and only the ballots they are eligible for (FR-BAL-05), each with its candidates (party, photo, slogan, biography), in the election's candidate order (a `shuffled` order is fixed for the session) |
| Submit | `POST /vote/{election}/submit` `{ballots: [{ballot, candidates: [uuid]}]}`, exactly the eligible ballots; the transaction of `docs/design/database.md` 4.1; 201 `{voted_at}`; a lost answer re-sent in the same session answers 200 with the same time and writes nothing |
| Choices | Each ballot: candidates of that ballot only, no repeat, at most `seats`, none only if `allow_blank` (a blank vote has no `vote_choices` row); anything else → 422 `choices_invalid` for the whole submission, nothing recorded |
| Eligibility | A missing or an extra (not eligible) ballot → 422 `ballots_mismatch`, the whole submission rejected (FR-SEC-07) |
| Secrecy | `votes` (uuid, election, ballot), `vote_choices` (vote uuid, candidate), `participations` (election, voter, voted_at), `vote_audit` (uuid, election, payload): no numeric id and no timestamp on the first three that could line up with participations; the audit payload is the sealed box of `docs/design/database.md` 4.2 |
| Audit payload | Bytes: `0x01`, record uuid (16), election uuid (16), vote uuid (16), voter uuid (16), ballot uuid (16), time as unsigned 64-bit big-endian seconds: 89 bytes, sealed to `VOTE_AUDIT_PUBLIC_KEY` (`config('votes.audit_public_key')`), 137 bytes stored; a missing or malformed key fails the transaction (nothing recorded) |
| Database rights | The `app` account cannot `UPDATE` or `DELETE` `participations`, `votes`, `vote_choices`, `vote_audit`; `GrantAppRights` applies it |
| Logs | Never a code, a vote, a candidate, a voter name or a request body of the voting routes; outcomes only |
| Limits | Failures only (an `invalid_code` answer): 30 per 10 minutes per IP and election, 300 per hour per election → 429 `too_many_attempts`; submit: 10 per minute per session |
| Opening and closing | `php artisan elections:advance`, every minute: `scheduled` whose `starts_at` has passed → `open` (`opened_at`), `open` whose `ends_at` has passed → `closed` (`closed_at`); idempotent; the vote transaction does not depend on it (FR-ELEC-05) |
| Reissue | `POST /voters/{voter}/credential` answers 409 `voter_has_voted` for a voter with a participation |

## Endpoints (new files in `docs/api/vote/`)

| Endpoint | Purpose |
|---|---|
| `GET /vote/{election}` | Public information and phase |
| `POST /vote/{election}/session` | Enter the code, open the voter session |
| `DELETE /vote/{election}/session` | End the session ("not me", "next voter") |
| `GET /vote/{election}/ballots` | Identity and eligible ballots |
| `POST /vote/{election}/submit` | Record the vote |

## What to build (runs, each ending green)

- **12a, server:** migrations (`participations`, `votes`, `vote_choices`, `vote_audit`), models, `GrantAppRights`,
  `config/votes.php`, the five endpoints, the transaction, the limiters, `elections:advance` and its schedule, the
  reissue refusal, tenant and secrecy tests.
- **12b, web:** the public pages M1 to M5 (`/vote/{election}`, `/identity`, `/ballot/{n}`, `/review`, `/done`) with the
  photo-first ballot of the approved mockup, the FR/EN switch, the countdown, the code read from the link fragment and
  removed from the address bar, messages in both languages.

## Test ids (browser tests `web/e2e/slice12/voting.spec.ts`)

| Element | Test id |
|---|---|
| M1 | `vote-page`, `vote-election-title`, `vote-code-input`, `vote-code-submit`, `vote-code-error`, `vote-countdown` (before the start), `vote-closed` (after the end), `vote-language-fr`, `vote-language-en` |
| M2 | `vote-identity`, `vote-identity-name`, `vote-identity-group`, `vote-identity-ballot-{n}`, `vote-identity-yes`, `vote-identity-no` |
| M3 | `vote-ballot`, `vote-ballot-title`, `vote-ballot-progress`, `vote-candidate-{n}` (button, `aria-pressed`), `vote-blank` (only when allowed), `vote-ballot-next`, `vote-ballot-back`, `vote-ballot-hint` |
| M4 | `vote-review`, `vote-review-ballot-{n}`, `vote-review-edit-{n}`, `vote-confirm` |
| M5 | `vote-done`, `vote-done-time`, `vote-done-countdown`, `vote-done-next` |

Text in the French and English message files; tokens only; phone first, 360 px; accessible; the code is never in a URL,
a storage or a log after being read; a session cookie only.

## Done when

`make lint`, unit tests and both acceptance folders pass with no edit; the vote is recorded once under repeated and
concurrent submission; no row of the secrecy tables carries a timestamp or numeric id that lines them up; the
generated contract and schema regenerated.

## Not in this slice

Close early and extend, countdown administration (13), turnout (14), results (15), the operator's audit reading tool (19).
