# GET /api/v1/elections/{election}/voters

The voters of an election, one page at a time, for the voters page (screen A08).

| | |
|---|---|
| Slice | 07 |
| Requirements | FR-VOT-01, FR-VOT-06 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

Query:

| Parameter | Type | Rules |
|---|---|---|
| page | integer | Default 1 |
| per_page | integer | Default 24, 1 to 100 |
| q | string | Optional. Trimmed, 1 to 100 characters; a voter is kept when the text is found, ignoring case, in the full name, the identifier or the email. A blank `q` is ignored |
| group | UUID or `none` | Optional. A group's UUID keeps the voters of that group; `none` keeps the voters without a group. A UUID that names no group of this election gives an empty list, never an error |

The credential-status and "has voted" filters of FR-VOT-06 arrive with slices 10 and 12.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election with voters, no query | 200 (24 per page, by full name ignoring case, then creation order) | | |
| 2 | An election with no voter | 200 (`data: []`, `meta.total: 0`) | | |
| 3 | `q` matches a name, an identifier or an email | 200 (only the matches; `meta.total` counts them) | | |
| 4 | `group` is a group's UUID, or `none` | 200 (only that group's voters, or the voters without a group) | | |
| 5 | `group` is a UUID of no group of this election (unknown, another election's, another institution's) | 200 (`data: []`) | | |
| 6 | `group` is neither a UUID nor `none`; `per_page` outside 1 to 100; `page` below 1 | 422 | `validation_failed` (`group: invalid`, `per_page: max`, `page: min`) | |
| 7 | The election is not a draft | 200 (reading is always allowed) | | |
| 8 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 5 and 7

```json
{ "data": [ <voter>, … ], "meta": { "page": 1, "per_page": 24, "total": 312 } }
```

Each voter:

```json
{
  "id": "9b1e6d2a-3c4f-4a0b-8e55-1f6a7c2d9e10",
  "full_name": "Rose-Marie Désir",
  "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" },
  "identifier": "E-2041",
  "email": "rm.desir@example.ht",
  "phone": "+509 3712 4455",
  "created_at": "2026-10-10T14:02:11Z",
  "updated_at": "2026-10-10T14:02:11Z"
}
```

`id` is the voter's UUID. `group` is `null` for a voter without a group, else the group's UUID and name. `identifier`,
`email` and `phone` are `null` when empty. No numeric id, no `election_id`, no `institution_id`, no code and no
participation appears (codes come in slice 10, participation in slice 12).

### 422, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md).
