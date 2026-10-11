# Slice 12b — Voter session hardening

Brief · 2026-10-12 · small change on the voting flow, after slice 13 is merged · branch `slice/12b-voter-hardening`

## Jun's decisions (2026-10-12)

1. **The per-election limit no longer blocks valid codes.** 300 wrong codes per hour per election still answer 429 to
   wrong codes; a valid code opens a session. Why: an outsider could otherwise stop an election for an hour. The
   per-address limit (30 wrong codes per 10 minutes) and the public limiter are unchanged, and a locked-out address
   still gets 429.
2. **One live session per voter.** A new session ends the voter's earlier one.
3. **No `__Secure-` prefix** on the cookie: it is already `Secure`, `HttpOnly`, `SameSite=Strict` and path-scoped.

## Endpoint file

`docs/api/vote/POST-vote-{election}-session.md`: scenarios 9, 11 and 12, and the notes (changed first, rule 7).

## Work

- Server: the election-wide counter is checked only after the code is found to be wrong; the session store keeps the
  voter's current token and every session read compares with it. No new column, nothing in the database.
- Tests (to write before the code, in `api/tests/Acceptance/Slice12/` or a new `Slice12b` folder): scenario 11 with 300
  counted failures then a valid code (201) and a wrong code (429); scenario 12 with two browsers and one code (first
  browser answers 401 `voter_session_expired` on ballots and submit, second works); the existing scenarios 8 and 9 stay
  green; a replaced session of voter A does not touch voter B.
- Web: nothing new; the code entry already handles `voter_session_expired`. Check the message says to enter the code
  again.

## Not in this slice

The `__Secure-` prefix, per-voter limits, anything about the audit log.
