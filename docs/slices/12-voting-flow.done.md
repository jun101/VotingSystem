# Slice 12 done (2026-10-12)

The voting flow: voter session by code, ballots, one transaction per vote, the sealed audit record, the scheduler that opens and closes elections, and the public pages M1 to M5. FR-VOTE-01 to FR-VOTE-07, FR-SEC-01 to FR-SEC-03, FR-CRED-03 (no new code for a voter who voted).

- API: `GET /vote/{election}`, `POST` and `DELETE /vote/{election}/session`, `GET /vote/{election}/ballots`, `POST /vote/{election}/submit`; reissue and failed-email runs refuse or skip a voter who has voted (`voter_has_voted`).
- Data: `participations`, `votes`, `vote_choices`, `vote_audit`; no numeric id or timestamp on the three secrecy tables, rows inserted in a random order, the `app` account can only insert; the audit record is a sealed box of 89 bytes for the election's public key.
- Session: random 256-bit token in a `vote_session` cookie (HttpOnly, SameSite=Strict, `Path=/api/v1/vote`), 15 minutes, kept in the cache by hash; JSON body required on every POST (the CSRF defence).
- Limits: wrong codes 30 per 10 minutes per address (IPv6 by /64) and 300 per hour per election, counted atomically, valid codes given back; public limiter on every vote route; submit 10 per minute per session.
- Scheduler: `elections:advance` every minute opens and closes elections under the row lock; the vote also checks the server clock.
- Pages: code entry with countdown and closed notice, identity, photo-first ballot, review, done with 10 s return; code read from the link fragment and removed from the address bar; FR/EN toggle kept for the voting session only; election language by default.
- Checks at the end: Slice12 acceptance 91, Slice10 116, Slice11 74, Slice03 61, unit 130, feature 200 (including real parallel submission), web unit 561, browser slice12 22 (desktop and phone), lint clean.
- Review: `12-voting-flow.review.md` (correctness, security twice, id leak).
- Test changes after the test commit, by the test author: the over-long code case in `VoteSessionTest` now uses 64 characters (a longer code is a 422 by design).
- Known and open: `slice03/shell.spec.ts` "no data value that is a number" fails on `data-nimg` that Next adds to images (predates the slice; the test needs to ignore that attribute).
- Not in this slice: close early and extend (13), turnout (14), results (15), the operator's audit reading tool (19).
