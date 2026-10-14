# GET /api/v1/public/institutions/{institution}

The public profile of an institution and its published elections. No sign-in.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-RES-07 |
| Caller | Anyone |
| Rate limit | 120 requests per minute per address |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An institution with published elections | 200 (only `published` ones, newest publication first, at most 50) | | |
| 2 | An institution with none | 200 (`elections: []`) | | |
| 3 | Unknown, not a UUID, or a suspended institution | 404 | `not_found` | |
| 4 | Too many requests, another method | 429, 405 | the shared codes | |

## Responses

### 200

```json
{
  "data": {
    "id": "3b7a…", "name": "Collège Étoile du Matin", "type": "school", "logo": null,
    "description": "Un collège de Pétion-Ville.", "city": "Pétion-Ville",
    "elections": [
      { "id": "9f2c…", "title": "Conseil des élèves 2026", "published_at": "2026-10-17T14:00:00Z",
        "starts_at": "2026-10-12T12:00:00Z", "ends_at": "2026-10-16T19:00:00Z", "timezone": "America/Port-au-Prince", "language": "fr" }
    ]
  }
}
```

Only these profile fields: no address, phone, email, user, voter or count of voters. A draft, scheduled, open, closed or
archived election is never listed.
