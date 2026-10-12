# GET /api/v1/vote/{election}

What a voter may know before entering a code: the election, its period and whether it can be voted in now.

| | |
|---|---|
| Slice | 12 |
| Requirements | FR-VOTE-01, FR-VOTE-02 |
| Caller | Anyone, no session |
| Rate limit | The shared API limit |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election that is not a draft |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A scheduled election (or an open one whose start has not come) | 200 (`phase` `before`) | | |
| 2 | An open election, server time between start and end | 200 (`phase` `open`) | | |
| 3 | A closed election, or an open one whose end has passed, or a published or archived one | 200 (`phase` `closed`; `results` set once published) | | |
| 4 | A draft, an unknown election, not a UUID | 404 | `not_found` (the same answer) | |
| 5 | The institution is suspended and the election is `scheduled` or `open` (whatever the time) | 200 as above, `phase` `paused` (nothing is closed; votes resume on reactivation) | | |
| 5b | The institution is suspended and the election is closed, published or archived | 200, `phase` `closed` as before | | |
| 6 | Another method | 405 | `method_not_allowed` | |
| 7 | Too many requests | 429 | `too_many_attempts` | |

## Responses

### 200

```json
{
  "data": {
    "id": "9f2c1d3e-6a40-4b8a-8c11-2d5e7f0a9b34",
    "title": "Conseil des élèves 2026",
    "institution": "Collège Étoile du Matin",
    "starts_at": "2026-10-12T12:00:00Z",
    "ends_at": "2026-10-16T19:00:00Z",
    "timezone": "America/Port-au-Prince",
    "language": "fr",
    "phase": "open",
    "results": null
  }
}
```

`phase` is `before`, `open`, `closed` or `paused`. `results` is the path of the public results page (`/results/{election}`) once the election is `published`, else
`null`. The time is the server's: the page never decides the phase from the device's clock. No numeric id, no count
of voters or votes, no ballot.
