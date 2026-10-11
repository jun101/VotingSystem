# POST /api/v1/vote/{election}/submit

Records the vote: every eligible ballot together, once, in one transaction.

| | |
|---|---|
| Slice | 12 |
| Requirements | FR-VOTE-04, FR-VOTE-05, FR-VOTE-07, FR-SEC-01, FR-SEC-02, FR-SEC-07, FR-SEC-08, FR-ELEC-05 |
| Caller | The voter session |
| Rate limit | 10 requests per minute per session |

## Request

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| ballots | list | Required. One item per eligible ballot, no more, no fewer |
| ballots[].ballot | UUID | A ballot of the election the voter is eligible for |
| ballots[].candidates | list of UUID | Candidates of **that** ballot, no repeat, at most `seats`; empty (a blank vote) only if the ballot `allow_blank` |

```json
{ "ballots": [ { "ballot": "7a1c0b5e-2d4f-4e11-8d3a-0b9c6e4f1a22", "candidates": ["c2b8e0a4-9d6f-4c3b-a1e7-5f0d3b6a8c91"] } ] }
```

## The transaction

As `docs/design/database.md` 4.1: lock the voter row, check the election is `open` and the server time is between
its start and end, check there is no participation, work out the eligible ballots and compare them with the body,
write one `participations` row, then for each ballot one `votes` row, its `vote_choices` rows and one `vote_audit` row
(payload as in the brief). Any error rolls everything back.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A complete valid submission | 201, `voted_at`; one participation, one vote per ballot, the choices, one audit record per vote | | |
| 2 | A blank vote on a ballot that allows it | 201 (a vote with no `vote_choices` row) | | |
| 3 | The same submission sent again in the same session (a lost answer) | 200, the same `voted_at`, nothing written | | |
| 4 | The same code used in a new session | 409 at the session | `already_voted` | |
| 5 | A ballot is missing, or one the voter is not eligible for is present | 422, nothing recorded | `ballots_mismatch` | |
| 6 | A candidate of another ballot or election, unknown, repeated, more than `seats`, or none on a ballot that does not allow blank | 422, nothing recorded | `choices_invalid` | |
| 7 | Body not valid: `ballots` missing, not a list, an item without `ballot` or `candidates`, ids not UUIDs | 422 | `validation_failed` | |
| 8 | The election is not open, or the server time is outside its period even if the status says `open` | 409 | `election_not_open` | |
| 9 | No session, expired, ended, or of another election | 401 | `voter_session_expired` | |
| 10 | Two requests of the same voter at once | Exactly one participation and one set of votes; the second sees `already_voted` or the same-session repeat | | |
| 11 | The audit key is missing or malformed, or any write fails | 500, **nothing recorded**, no participation | `server_error` | |
| 12 | More than 10 submissions in a minute for a session | 429 | `too_many_attempts` | |
| 13 | Another method; body not valid JSON | 405, 400 | `method_not_allowed`, `malformed_request` | |

Order of the checks: session (401), body (422), election state (409), eligibility and choices (422).

## Responses

### 201, 200

```json
{ "data": { "voted_at": "2026-10-12T12:07:41Z" } }
```

The session is kept for its remaining minutes in a voted state that answers the same confirmation again; the
choices are never returned. The cookie is cleared after a delay of 15 minutes at most or by DELETE session.

### 401, 409, 422, 429, 500, 405, 400

The shared error shape of [API conventions](../README.md).

## Notes

`votes` carries a random `uuid`, `election_id` and `ballot_id` only; `vote_choices` a vote uuid and `candidate_id`;
`participations` the voter and the exact `voted_at`; `vote_audit` its own uuid, the election and the sealed payload.
No timestamp or sequence number on a vote. Nothing of the body or the choices is logged.
