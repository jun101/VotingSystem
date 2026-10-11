# POST /api/v1/vote/{election}/session

Checks a code and opens a voter session: a cookie that lets the voter read their ballots and submit once.

| | |
|---|---|
| Slice | 12 |
| Requirements | FR-VOTE-01, FR-VOTE-06, FR-VOTE-08, FR-SEC-06, NFR-SEC-05 |
| Caller | Anyone with a code |
| Rate limit | 30 wrong codes per 10 minutes per IP address and election, 300 per hour per election; only failures are counted |

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
| 5 | Election not open now (before the start, after the end, not `open`) | 409, `phase` `before` or `closed` in `error.phase` | `election_not_open` | |
| 6 | `code` missing or not a string | 422 | `validation_failed` (`code: required`) | |
| 7 | The election is a draft, unknown, or not a UUID | 404 | `not_found` | |
| 8 | A second valid code on a browser that has a session | 201 (the old session is replaced) | | |
| 9 | More than 30 wrong codes in 10 minutes from one address for this election, or 300 in an hour for it | 429, `Retry-After` | `too_many_attempts` | |
| 10 | Another method; body not valid JSON | 405, 400 | `method_not_allowed`, `malformed_request` | |

`code` is at most 64 characters (422 `code: invalid` above). The per-address counter keys an IPv6 client on its /64 prefix. Counting is atomic (the increment decides, and a valid code gives it back). Every request of this route, valid or not, is also held by the public limiter (600 per minute per address and election, the election's uuid in lower case), as are GET info, GET ballots, DELETE session and POST submit. A valid code is **not** counted against the wrong-code limits; a locked-out address still gets 429 for a valid code until the
window passes.

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

Nothing is written to the database and no vote data exists yet. The voter's name is not returned here: the next
call shows it for confirmation (FR-VOTE-03).
