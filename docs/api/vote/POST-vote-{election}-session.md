# POST /api/v1/vote/{election}/session

Checks a code and opens a voter session: a cookie that lets the voter read their ballots and submit once.

| | |
|---|---|
| Slice | 12 |
| Requirements | FR-VOTE-01, FR-VOTE-06, FR-VOTE-08, FR-SEC-06, NFR-SEC-05 |
| Caller | Anyone with a code |
| Rate limit | 30 wrong codes per 10 minutes per IP address and election, 300 wrong codes per hour per election (a valid code is never refused by the second one); only failures are counted |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | The election the code belongs to |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| code | string | Required. Ten characters of the alphabet, with or without the hyphen and in any case; spaces ignored. The personal link carries it after `#`, so it reaches the server only here |

```json
{ "code": "K7MP4-QX9RT" }
```

The code is hashed with `CREDENTIAL_HASH_KEY` (upper case, no hyphen) and looked up with the election: a code of
another election never matches. The answer for an unknown code takes the same path and time as for a wrong one.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid code, the election open | 201, the cookie `vote_session` | | |
| 2 | The code in lower case, without the hyphen, with spaces | 201 (the same voter) | | |
| 3 | Unknown code, a code of another election or institution, a malformed one, or a code that was replaced by a reissue | 401 | `invalid_code` (one answer for all) | |
| 4 | The voter has already voted | 409 | `already_voted` | |
| 5 | Election not open now (before the start, after the end, not `open`) | 409, `phase` `before`, `closed` or `paused` (institution suspended) in `error.phase` | `election_not_open` | |
| 6 | `code` missing or not a string | 422 | `validation_failed` (`code: required`) | |
| 7 | The election is a draft, unknown, or not a UUID | 404 | `not_found` | |
| 8 | A second valid code on a browser that has a session | 201 (the old session is replaced) | | |
| 9 | More than 30 wrong codes in 10 minutes from one address for this election, or a **wrong** code once 300 wrong codes in an hour have been counted for it | 429, `Retry-After` | `too_many_attempts` | |
| 10 | Another method; body not valid JSON | 405, 400 | `method_not_allowed`, `malformed_request` | |
| 11 | A **valid** code (not yet voted) while 300 wrong codes in the hour are counted for the election, from an address that is not locked out | 201 | | |
| 12 | The same voter opens a second session (another browser, or after a code was typed again) | 201; the first session is ended: its next call answers 401 | `voter_session_expired` (on the first browser's next call) | |

`code` is at most 64 characters (422 `code: invalid` above). The per-address counter keys an IPv6 client on its /64 prefix. Counting is atomic (the increment decides, and a valid code gives it back). Every request of this route, valid or not, is also held by the public limiter (600 per minute per address and election, the election's uuid in lower case), as are GET info, GET ballots, DELETE session and POST submit. A valid code is **not** counted against the wrong-code limits. The per-election hourly limit slows only wrong codes: once it is reached a wrong code answers 429 and a valid code still opens a session (slice 12b, Jun 2026-10-12), so an outsider cannot stop an election by guessing; the guessing space (ten characters of a 32-symbol alphabet) and the per-address and public limiters keep guessing impractical. An address that is locked out by the per-address limit still gets 429 for a valid code until the window passes.

## Responses

### 201

```json
{ "data": { "expires_at": "2026-10-12T12:15:00Z" } }
```

`Set-Cookie: vote_session=<256-bit token>; HttpOnly; SameSite=Strict; Path=/api/v1/vote; Secure` (`Secure` outside
the local stack). The token is nowhere else: not in the body, a header or a log. The server keeps `{voter,
election, expires_at}` for 15 minutes at most; the expiry is compared with the application clock.

### 401, 409, 422, 404, 429, 405, 400

The shared error shape of [API conventions](../README.md). Order: election (404), body (422), limit (429), code (401),
state (409: `already_voted` before `election_not_open`).

## Notes

A voter has one live session: opening a session records the new token as the voter's current one in the cache
(under a key of the election and the voter, expiring with it) and the earlier session of that voter answers 401
`voter_session_expired` from then on (slice 12b). A leaked cookie therefore dies when the voter enters again.
The cookie keeps its name `vote_session`: the `__Secure-` prefix was considered and not adopted (the cookie is
already `Secure`, `HttpOnly`, `SameSite=Strict` and path-scoped).

Nothing is written to the database and no vote data exists yet. The voter's name is not returned here: the next
call shows it for confirmation (FR-VOTE-03).
