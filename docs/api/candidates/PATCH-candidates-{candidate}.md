# PATCH /api/v1/candidates/{candidate}

Changes a candidate of a draft election, and can move it to another ballot of the same election. Every field is
optional; only the fields present are changed.

| | |
|---|---|
| Slice | 06c |
| Requirements | FR-CAND-02, FR-CAND-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| candidate | UUID | A candidate of the caller's institution whose election is a **draft** |

Body, JSON: the fields of [POST /ballots/{ballot}/candidates](POST-ballots-{ballot}-candidates.md), none required,
and:

| Field | Type | Rules |
|---|---|---|
| ballot | UUID | Another ballot **of the same election**: the candidate goes to the end of that ballot and the positions of the one it leaves close up. The same ballot is accepted and changes nothing. Unknown, another election's or another institution's gives the same 422 |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid body with some fields | 200 |  | |
| 2 | Empty body, or no known field | 200 (nothing changes) |  | |
| 3 | `party` set to `null`, `slogan` or `biography` blank | 200 (stored as `null`) |  | |
| 4 | `party` changed to another party of the election | 200 |  | |
| 5 | `ballot` is another ballot of the election | 200 (last position there; the old ballot's positions close up) |  | |
| 6 | A name blank or too long, `sex` out of the set, `slogan` or `biography` too long | 422 | `validation_failed` | |
| 7 | `party` or `ballot` unknown, of another election or of another institution | 422 | `validation_failed` (`party: invalid`, `ballot: invalid`) | |
| 8 | The target ballot already has 50 candidates | 409 | `candidate_limit_reached` | |
| 9 | The election is not a draft | 409 | `election_not_editable` | |
| 10 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 11 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 12 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 13 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 14 | Body is not valid JSON | 400 | `malformed_request` | |
| 15 | Too many requests | 429 | `too_many_attempts` | |
| 16 | Another method than PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 5

The candidate resource of [POST /ballots/{ballot}/candidates](POST-ballots-{ballot}-candidates.md), one item.

### 409, 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the body (422), the
state (409).

## Notes

`id`, `position`, `photo` in the body are ignored (position changes through
[PUT order](PUT-ballots-{ballot}-candidates-order.md), the photo through its own endpoints in slice 06d).
