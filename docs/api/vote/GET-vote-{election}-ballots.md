# GET /api/v1/vote/{election}/ballots

The voter's name and group, and the ballots they are eligible for with their candidates.

| | |
|---|---|
| Slice | 12 |
| Requirements | FR-VOTE-03, FR-BAL-05, FR-SEC-07 |
| Caller | The voter session |
| Rate limit | The shared API limit |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid session | 200 | | |
| 2 | A voter in a group | 200 (the general ballots and the group ballots that include the group) | | |
| 3 | A voter with no group | 200 (the general ballots only) | | |
| 4 | An election with a `shuffled` candidate order | 200 (the order is the same on every read of the session) | | |
| 5 | No cookie, an expired session, a session of another election, or a session ended | 401 | `voter_session_expired` | |
| 6 | The election is no longer open (closed or past its end) | 409, `error.phase` | `election_not_open` | |
| 7 | The voter has voted (the session is in the voted state) | 409 | `already_voted` | |
| 8 | Another method | 405 | `method_not_allowed` | |

## Responses

### 200

```json
{
  "data": {
    "voter": { "full_name": "Wideline Joseph", "group": "4e année" },
    "expires_at": "2026-10-12T12:15:00Z",
    "ballots": [
      {
        "id": "7a1c0b5e-2d4f-4e11-8d3a-0b9c6e4f1a22",
        "title": "Président(e)",
        "description": null,
        "scope": "general",
        "group_names": [],
        "seats": 1,
        "allow_blank": true,
        "candidates": [
          {
            "id": "c2b8e0a4-9d6f-4c3b-a1e7-5f0d3b6a8c91",
            "first_name": "Nadège",
            "last_name": "Pierre-Louis",
            "sex": "female",
            "slogan": "Une école qui nous écoute",
            "biography": null,
            "photo": "/media/5b0e7c1a-3d2f-4e58-9a61-0c7d4b2e8f13-md.webp",
            "party": { "name": "Avenir Étudiant", "acronym": "AE", "colour": "#5468D4", "logo": null }
          }
        ]
      }
    ]
  }
}
```

The voter has no `id` here: they are known by the session. `group` is `null` for a voter with no group. Ballots are in
the election's order. `group_names` names the groups of a group ballot (for the identity screen). `photo` and `logo`
are `null` or the public media path. No numeric id, no code, no count of votes.
