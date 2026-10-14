# GET /api/v1/elections/{election}/generated-files

The files of an election (slip PDFs), newest first.

| | |
|---|---|
| Slice | 10 |
| Requirements | FR-CRED-04, FR-CRED-05 |
| Caller | Institution user: owner or manager |
| Rate limit | The shared API limit |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

No body. Query: none (an election holds a few hundred files at most).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An election with files | 200 (`data` is the list, newest first, ties by group name then part) | | |
| 2 | An election with no file | 200 (`data` is `[]`) | | |
| 3 | Files of another election or another institution | Never listed | | |
| 4 | A closed, published or archived election | 200 (`data` is `[]`: the files are deleted at the end) | | |
| 5 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 6 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 8 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 4

```json
{ "data": [ { "id": "5b0e7c1a-3d2f-4e58-9a61-0c7d4b2e8f13", "kind": "slips_pdf", "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" }, "part": 1, "parts": 1, "voter_count": 38, "status": "ready", "failure": null, "size_bytes": 148230, "expires_at": "2026-10-12T14:00:00Z", "created_at": "2026-10-10T19:12:05Z" } ] }
```

The file object is described in [POST credential-runs](POST-elections-{election}-credential-runs.md).

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md).

## Notes

A file of a group that was deleted afterwards keeps its row with `group` `null` (the group column is set to null on
delete) and its slips; it is listed like the file of the voters with no group.
