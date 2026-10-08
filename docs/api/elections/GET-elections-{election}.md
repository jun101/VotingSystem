# GET /api/v1/elections/{election}

One election of the institution.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-01 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election of the institution, in any status | 200 | | |
| 2 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 3 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 4 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 5 | Another method than GET, HEAD, PATCH, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

```json
{
  "data": {
    "id": "5d1f6a52-7c0e-4b7d-9f33-6c1d2f9e8a10",
    "title": "Conseil des élèves 2026",
    "description": "Élection annuelle des représentants des élèves.",
    "status": "draft",
    "starts_at": "2026-10-12T12:00:00Z",
    "ends_at": "2026-10-16T19:00:00Z",
    "timezone": "America/Port-au-Prince",
    "language": "fr",
    "candidate_order": "manual",
    "results_display": "full",
    "cover": {
      "sm": "/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-480.webp",
      "md": "/media/7c9e6679-7425-40de-944b-e07fc1f90ae7-960.webp"
    },
    "ballots_count": 0,
    "voters_count": 0,
    "created_at": "2026-10-08T15:20:00Z"
  }
}
```

- `status` is one of `draft`, `scheduled`, `open`, `closed`, `published`, `archived`.
- `starts_at` and `ends_at` are UTC; `timezone` says how to show them.
- `cover` is `null` without a cover, else two addresses under `/media` (480 and 960 pixels wide).
- `ballots_count` and `voters_count` are `0` until slices 06 and 07.
- No numeric key, no `parent_election_id`, no file name other than the cover's UUID appears.

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md), with the codes of the table. Scenario 2 gives the same body
and headers for every cause.
