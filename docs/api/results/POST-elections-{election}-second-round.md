# POST /api/v1/elections/{election}/second-round

Creates a draft election for a second round from a ballot that ended in a tie.

| | |
|---|---|
| Slice | 15 |
| Requirements | FR-RES-09, FR-RES-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `closed`, `published` or `archived` |

Body, JSON: `{ "ballot": "<uuid>" }`, a ballot of that election whose result has a tie.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A tied ballot | 201 (a draft election: that ballot with only the tied candidates, the same voters and groups, no code, no vote; `parent` the first election) | | |
| 2 | The second round has the same settings, language and dates as a duplicate | 201 | | |
| 3 | A ballot with no tie | 409 | `ballot_not_tied` | |
| 4 | A second round already exists for that ballot | 409 | `second_round_exists` | |
| 5 | The ballot belongs to another election | 422 | `validation_failed` (`ballot: invalid`) | |
| 6 | The election is a draft, scheduled or open | 409 | `results_not_available` | |
| 7 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 8 | Not signed in, suspended institution, CSRF, too many requests, another method, bad JSON | 401, 403, 419, 429, 405, 400 | the shared codes | |

## Responses

### 201

The new election as in [GET election](../elections/GET-elections-{election}.md) plus a `parent` field holding the first
election's UUID (this response only): `status` `draft`, title "Second tour – {title}" (with an en dash, cut to 200
characters; "Second round – {title}" for an election whose language is `en`), `ballots_count` 1, `voters_count` as the
first. The dates are the first election's, as in a duplicate: the person edits them before scheduling.

## Notes

One transaction: the new election, the ballot (title, description, `seats` the seats still to fill, `allow_blank`), the
tied candidates (name, party, slogan, biography; photos and logos are not copied, as in a duplicate), the groups and
voters (identifier, email, phone, group; no credentials, no participations). The voters need new codes (slice 10).
The first election is not changed. A ballot's `seats_left` is the number of seats the tie left open.
