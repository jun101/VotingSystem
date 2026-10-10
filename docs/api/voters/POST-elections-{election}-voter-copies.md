# POST /api/v1/elections/{election}/voter-copies

Loads the voters of another election of the institution into this one, with their groups.

| | |
|---|---|
| Slice | 09 |
| Requirements | FR-VOT-05, FR-VOT-08 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | The **target**: an election of the caller's institution that is a **draft or scheduled** |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| source | UUID | Required. **Another** election of the same institution, in any status |
| groups | list of UUID, null | Optional. Groups **of the source**: only their voters are copied. `null` or absent: every voter of the source |
| include_ungrouped | boolean | Optional. Whether the voters with no group are copied too. Default `true` when `groups` is absent or `null`, `false` when `groups` is a list |
| rename | object | Optional. Source group UUID to the new name (1 to 100 characters after trimming), for groups that are copied. A group with no entry keeps its name |

```json
{ "source": "9f2c1d3e-6a40-4b8a-8c11-2d5e7f0a9b34", "groups": ["4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60"], "rename": { "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60": "5e année" } }
```

What is copied: the full name, the group (found by name in the target, case and surrounding spaces ignored, accents
counted, or created: the rules of [POST voters](POST-elections-{election}-voters.md)), the identifier, the email and the
phone. Never copied: credentials, codes, participation, dates. The copy is new voters; the source is untouched.

A voter whose identifier or email is already used in the target (the check of POST voters) is **skipped** and counted.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Only `source`: every voter, ungrouped included | 201 | | |
| 2 | `groups` with some groups of the source | 201 (only their voters; the ungrouped ones only with `include_ungrouped: true`) | | |
| 3 | `rename` for a copied group | 201 (the voters go to the group with the new name, found or created) | | |
| 4 | The new name is that of a group the target already has | 201 (the voters join that group; no second group) | | |
| 5 | Some voters repeat an identifier or an email of the target | 201 (they are skipped; `skipped` counts them) | | |
| 6 | Nothing matches (an empty source, or groups with no voter) | 201 (`copied` 0) | | |
| 7 | `source` missing or not a UUID; the target itself; unknown, or another institution's (one answer for the last two) | 422 | `validation_failed` (`source: required`, `source: uuid`, `source: same`, `source: invalid`) | |
| 8 | `groups` is not a list of UUIDs, holds more than 100 items (checked before any query), or holds a group that is not the source's (unknown, another election's or institution's) | 422 | `validation_failed` (`groups: invalid`) | |
| 9 | `rename` is not an object, names a group that is not copied, or gives a blank or too long name | 422 | `validation_failed` (`rename: invalid`) | |
| 10 | The target would pass 10 000 voters | 409 | `voter_limit_reached` | |
| 11 | The target would pass 100 groups | 409 | `group_limit_reached` | |
| 12 | The target is open, closed, published or archived | 409 | `election_voters_locked` | |
| 13 | Not a UUID, unknown, or another institution's target | 404 | `not_found` | |
| 14 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 15 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 16 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 17 | Too many requests | 429 | `too_many_attempts` | |
| 18 | Another method than the ones this path takes | 405 | `method_not_allowed` | |
| 19 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 201 — scenarios 1 to 6

```json
{ "data": { "copied": 280, "skipped": 12, "groups_created": 3 } }
```

`copied` counts the voters written, `skipped` the voters not copied because of an identifier or an email already in the
target, `groups_created` the groups that did not exist and were created.

### 409, 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the target (404), the body (422), the
state (409; scenario 12 before 10 and 11).

## Notes

One transaction: the target's election row is locked first, then the voters are written in chunks of 500 inside it; a
409 leaves the target as it was. The identifier and the email are compared as in
[POST voters](POST-elections-{election}-voters.md), also against voters copied earlier in the same request.
