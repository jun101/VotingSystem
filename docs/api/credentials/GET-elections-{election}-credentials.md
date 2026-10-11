# GET /api/v1/elections/{election}/credentials

The state of the access codes of an election, for screen A11: totals and one line per group.

| | |
|---|---|
| Slice | 10 |
| Requirements | FR-CRED-01, FR-CRED-04 |
| Caller | Institution user: owner or manager |
| Rate limit | The shared API limit |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

No body, no query parameter.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election with voters, some with a code, some not | 200 (totals and groups) | | |
| 2 | An election with no voter | 200 (every count 0, `groups` empty) | | |
| 3 | Voters with no group | 200 (one extra line with `id` and `name` `null`, last) | | |
| 4 | A run is queued | 200 (`run_in_progress` true) | | |
| 5 | A closed, published or archived election | 200 (counts as they are; `files_ready` 0 because the files are deleted) | | |
| 6 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 5

```json
{
  "data": {
    "voters": 300,
    "with_code": 74,
    "without_code": 226,
    "with_email": 20,
    "run_in_progress": false,
    "groups": [
      { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "3e année", "voters": 36, "with_code": 36, "with_email": 0, "files_ready": 1 },
      { "id": null, "name": null, "voters": 4, "with_code": 0, "with_email": 1, "files_ready": 0 }
    ]
  }
}
```

`with_code` counts the voters who have a current code, `with_email` the voters with an email address, `files_ready` the
files of the group whose status is `ready`. Groups are sorted by name (accents and case ignored), the line of the voters
with no group is last and appears only when there is at least one such voter.

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md).

## Notes

Reads `credentials` and `generated_files` of the election through the tenant scope. The `code_hash` is never read here
or anywhere else in the API.
