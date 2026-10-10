# GET /api/v1/elections

The elections of the signed-in user's institution, as a page of cards, with the counts the screen needs for its
filter tiles. Archived elections are left out unless asked for.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-07, FR-NAV-04 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

Query parameters (all optional):

| Parameter | Rules |
|---|---|
| status | `draft`, `scheduled`, `open`, `closed`, `published` or `archived`. Absent: every status **except** `archived` |
| year | Four digits: the year of the election's start, read in the election's own time zone |
| page, per_page | As in [API conventions](../README.md): 25 by default, 100 at most |

Order: `open`, then `scheduled`, `draft`, `closed`, `published`, `archived`; inside a status, the latest start first,
then the latest created.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | No filter | 200 | | |
| 2 | `status` filter | 200 | | |
| 3 | `year` filter | 200 | | |
| 4 | `status` and `year` together | 200 | | |
| 5 | The institution has no election | 200 (`data` empty, counts zero) | | |
| 6 | Another institution has elections too | 200 (only this institution's, in the list, the counts and the years) | | |
| 7 | `status` not one of the six, `year` not four digits, `page` or `per_page` out of range | 422 | `validation_failed` (`status: in`, `year: format`, `per_page: between`) | |
| 8 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 9 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 10 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 6

```json
{
  "data": [ { "id": "…", "title": "Conseil des élèves 2026", "status": "draft", "…": "…" } ],
  "meta": {
    "page": 1, "per_page": 25, "total": 1,
    "counts": { "all": 4, "draft": 1, "scheduled": 0, "open": 1, "closed": 0, "published": 2, "archived": 1 },
    "years": [2026, 2025]
  }
}
```

- Each item is the election resource of [GET /elections/{election}](GET-elections-{election}.md).
- `meta.total` counts the elections that match the filters. `meta.counts` ignores the filters: one count per status,
  and `all`, which counts every status **except** `archived` (the default list). `meta.years` lists the years of
  all the institution's elections, newest first, whatever the filters, so the screen can draw its year filter.
- No numeric key and no other institution's data appear anywhere.

### 422, 401, 403, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

The list is paginated by the server; the counts and the years cost one extra query each, not one per card.
`ballots_count` of each item is the number of its ballots (slice 06a); `voters_count` is `0` until slice 07.
