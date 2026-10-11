# GET /api/v1/audit

The institution's audit log, newest first.

| | |
|---|---|
| Slice | 17 |
| Requirements | FR-AUD-01, FR-AUD-02, FR-AUD-03 |
| Caller | Institution **owner** (a manager answers 403 `forbidden`) |
| Rate limit | None (like the other list reads) |

## Request

Query:

| Parameter | Type | Rules |
|---|---|---|
| action | string | Optional. An action code (`election.closed`) or a family prefix ending with a dot (`election.`); another value gives an empty list |
| election | UUID | Optional. An election of the institution; an unknown one, another institution's or not a UUID gives an empty list |
| user | UUID or `system` | Optional. The user who acted, or `system` for the scheduler; an unknown one gives an empty list |
| from, to | date `YYYY-MM-DD` | Optional. Inclusive, in UTC; 422 `validation_failed` (`from: invalid`, `to: invalid`) for another shape |
| page | integer | Default 1 (`page: min` below) |
| per_page | integer | Default 50, 1 to 100 (`per_page: max`) |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | The owner, an institution with entries | 200, newest first, only this institution's entries | | |
| 2 | An institution with no entry | 200 (`data: []`, `meta.total: 0`) | | |
| 3 | Filters (`action` exact or prefix, `election`, `user`, `from`, `to`), alone or together | 200 (the matches; `meta.total` counts them) | | |
| 4 | A bad date, `per_page` out of range, `page` below 1 | 422 | `validation_failed` | |
| 5 | A manager | 403 | `forbidden` | |
| 6 | Not signed in, suspended institution, another method | 401, 403, 405 | the shared codes | |

## Responses

### 200

```json
{
  "data": [
    {
      "id": "6c1f0e8a-2b4d-4f7e-9a3b-5d8c1e7f0a42",
      "action": "election.times_changed",
      "actor": { "id": "b1a2…", "name": "Marie Joseph" },
      "election": { "id": "9f2c…", "title": "Conseil des élèves 2026" },
      "subject": { "type": "election", "id": "9f2c…" },
      "details": { "from": { "starts_at": "2026-10-12T12:00:00Z", "ends_at": "2026-10-16T19:00:00Z", "timezone": "America/Port-au-Prince" }, "to": { "starts_at": "2026-10-13T12:00:00Z", "ends_at": "2026-10-16T19:00:00Z", "timezone": "America/Port-au-Prince" } },
      "ip_address": "196.1.2.3",
      "created_at": "2026-10-11T15:02:11Z"
    }
  ],
  "meta": { "page": 1, "per_page": 50, "total": 112 }
}
```

`actor.id` is `null` and `actor.name` is `system` for the scheduler. `election` is `null` for an entry with no election,
and for an entry whose election was deleted (the title is then in `details.title`). `subject` is `null` when there is
none. `details` is an object, `{}` when empty. Entries of other institutions, and of the platform, never appear. No
numeric id, no code, no hash, no vote.
