# POST /api/v1/ballots/{ballot}/candidates

Adds a candidate at the end of a ballot of a draft election.

| | |
|---|---|
| Slice | 06c (the photo comes in 06d) |
| Requirements | FR-CAND-02, FR-CAND-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| ballot | UUID | A ballot of the caller's institution whose election is a **draft** |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| first_name | string | Required on creation. 1 to 80 characters after trimming |
| last_name | string | Required on creation. 1 to 80 characters after trimming |
| sex | string | Required on creation. `male` or `female` (chooses the default avatar when there is no photo) |
| party | UUID, null | Optional. A party of **the same election**; `null` for an independent candidate. An unknown UUID, another election's or another institution's gives the same 422 |
| slogan | string, null | Optional. At most 80 characters after trimming; blank is stored as `null` |
| biography | string, null | Optional. At most 1000 characters after trimming; blank is stored as `null` |

```json
{ "first_name": "Nadège", "last_name": "Pierre-Louis", "sex": "female", "party": "4a1f8c52-…", "slogan": "Une école qui nous écoute" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Names and sex only | 201 (independent, last position) |  | |
| 2 | Every field, with a party of the election | 201 |  | |
| 3 | `first_name` or `last_name` missing, blank or longer than 80 | 422 | `validation_failed` (`first_name: required`, `last_name: max`, …) | |
| 4 | `sex` missing or not `male` or `female` | 422 | `validation_failed` (`sex: required`, `sex: in`) | |
| 5 | `party` not a UUID | 422 | `validation_failed` (`party: uuid`) | |
| 6 | `party` unknown, of another election or of another institution | 422 | `validation_failed` (`party: invalid`), the same body for each | |
| 7 | `slogan` over 80 or `biography` over 1000 | 422 | `validation_failed` (`slogan: max`, `biography: max`) | |
| 8 | The ballot already has 50 candidates | 409 | `candidate_limit_reached` | |
| 9 | The election is not a draft | 409 | `election_not_editable` | |
| 10 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 11 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 12 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 13 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 14 | Body is not valid JSON | 400 | `malformed_request` | |
| 15 | Too many requests | 429 | `too_many_attempts` | |
| 16 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 and 2

The candidate resource, one item:

```json
{
  "id": "2d7f9b1e-5a3c-4e08-9c61-7b0a4f8e1d22",
  "ballot": "0b0e5b9a-2f43-4c1d-9a55-8d1f6a7c2e10",
  "party": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60",
  "first_name": "Nadège",
  "last_name": "Pierre-Louis",
  "sex": "female",
  "slogan": "Une école qui nous écoute",
  "biography": null,
  "photo": null,
  "position": 1,
  "created_at": "2026-10-10T14:02:11Z",
  "updated_at": "2026-10-10T14:02:11Z"
}
```

`id`, `ballot` and `party` are UUIDs; `party` is `null` for an independent candidate. `photo` is `null`, or from slice 06d `{ "sm": "/media/…-160.webp", "md": "/media/…-480.webp" }` (see [PUT photo](PUT-candidates-{candidate}-photo.md)). `position` is 1, 2, 3… inside the ballot with no gap. No numeric id, no `ballot_id`, no
`election_id`, no `institution_id` appears.

Header `Location` is `/api/v1/candidates/{id}`.

### 409, 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), then the body (422),
then the state (409; `election_not_editable` before `candidate_limit_reached`).

## Side effects

- The ballot's `candidates_count`, and the party's, go up by one.
- Position is last + 1, computed in one transaction that locks the ballot.
