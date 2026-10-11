# GET /api/v1/public/elections/{election}/results

The public results of a published election. No sign-in.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-RES-04, FR-RES-07, FR-RES-08, FR-SEC-03, FR-SEC-04 |
| Caller | Anyone |
| Rate limit | 120 requests per minute per address and election |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election whose status is `published` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A published election, `results_display` `full` | 200 (votes and percentages per candidate, blank votes) | | |
| 2 | A published election, `results_display` `winners` | 200 (turnout, winners and tied candidates only: no `votes`, `percentage`, `cast` or `blank` key anywhere) | | |
| 3 | A tie | 200 (`tie` set, `winners` the ones above it) | | |
| 4 | Any status but `published`, an unknown election, not a UUID | 404 | `not_found` (the same answer for all) | |
| 5 | The institution is suspended | 404 | `not_found` | |
| 6 | Too many requests | 429 | `too_many_attempts` | |
| 7 | Another method | 405 | `method_not_allowed` | |

## Responses

### 200

```json
{
  "data": {
    "id": "9f2c1d3e-6a40-4b8a-8c11-2d5e7f0a9b34",
    "title": "Conseil des élèves 2026",
    "language": "fr",
    "timezone": "America/Port-au-Prince",
    "starts_at": "2026-10-12T12:00:00Z",
    "ends_at": "2026-10-16T19:00:00Z",
    "published_at": "2026-10-17T14:00:00Z",
    "display": "full",
    "institution": { "id": "3b7a…", "name": "Collège Étoile du Matin", "logo": null },
    "turnout": { "eligible": 300, "voted": 287, "percentage": 95.6 },
    "ballots": [
      {
        "id": "7a1c…", "title": "Président(e)", "seats": 1, "groups": ["4e année"],
        "cast": 287, "blank": 12,
        "candidates": [
          { "id": "c2b8…", "first_name": "Nadège", "last_name": "Pierre-Louis", "party": "Avenir Étudiant", "photo": null, "votes": 150, "percentage": 52.3 }
        ],
        "winners": ["c2b8…"],
        "tie": null,
        "note": null
      }
    ]
  }
}
```

With `display` `winners`, each ballot has `id`, `title`, `seats`, `groups`, `candidates` (only the winners and the tied
candidates, without `votes` or `percentage`), `winners`, `tie`, `note`. `groups` holds the names of a group ballot's
groups, empty for a general ballot. A general ballot is never split by group (FR-SEC-04). `turnout` is the election's
voters and those who voted. `logo` is the institution's logo as in [GET institution](../institution/GET-institution.md)
or `null`. `Cache-Control: no-cache`.

## Notes

The bulletin check, the voters' names, the credentials and every numeric id are never part of the answer. The
numbers come from the same counting as [GET results](../results/GET-elections-{election}-results.md).
