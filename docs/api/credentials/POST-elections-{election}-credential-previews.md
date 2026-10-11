# POST /api/v1/elections/{election}/credential-previews

What a run would do for a scope, without doing it: the numbers the person confirms before emails are sent.

| | |
|---|---|
| Slice | 11 |
| Requirements | FR-CRED-07 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is a **draft, scheduled or open** |

Body, JSON: the `scope` and `group` of [POST credential-runs](POST-elections-{election}-credential-runs.md), the same
rules (`scope` `all`, `group`, `ungrouped` or `failed_email`).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `scope` `all` with voters, some with an email, some with a code | 200 (counts as below) | | |
| 2 | `scope` `group` or `ungrouped` | 200 (counts of that scope only) | | |
| 3 | `scope` `failed_email` | 200 (`to_generate` and `with_email` count the voters whose last email failed; `without_email` 0) | | |
| 4 | More than 100 voters without an address | 200 (`without_email` is the true count, the list holds the first 100 by name) | | |
| 5 | Nothing to generate | 200 (all counts 0; the run, not the preview, answers 409) | | |
| 6 | The election is closed, published or archived | 409 | `election_codes_locked` | |
| 7 | Body not valid (the rules of POST credential-runs) | 422 | `validation_failed` | |
| 8 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 9 | Not signed in, suspended institution, CSRF, too many requests, wrong method, bad JSON | 401, 403, 419, 429, 405, 400 | the shared codes | |

## Responses

### 200 — scenarios 1 to 5

```json
{
  "data": {
    "to_generate": 226,
    "with_email": 190,
    "without_email": 36,
    "skipped": 74,
    "voters_without_email": [
      { "id": "a8b1c6d2-0f4e-4d7a-9b35-6e2f1c8d4a70", "full_name": "Wideline Joseph", "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" } }
    ]
  }
}
```

`to_generate` is the number of voters who would get a code (no code yet, or a failed email for `failed_email`),
`with_email` those among them with an address, `without_email` the others (they are left for print), `skipped` the
voters of the scope who already have a code. `group` is `null` for a voter with no group.

## Notes

A reading: no code is made, nothing is queued, nothing is written. The numbers can change between the preview and the
run; the run checks `confirm_emails` against them again.
