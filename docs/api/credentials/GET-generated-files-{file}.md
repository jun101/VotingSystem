# GET /api/v1/generated-files/{file}

One file's status, for polling after a run.

| | |
|---|---|
| Slice | 10 |
| Requirements | FR-CRED-04, FR-CRED-05 |
| Caller | Institution user: owner or manager |
| Rate limit | The shared API limit |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| file | UUID | A file of the caller's institution |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A queued file | 200 (`status` `queued`, `size_bytes` null) | | |
| 2 | A ready file | 200 (`status` `ready`, `size_bytes` set) | | |
| 3 | A failed file | 200 (`status` `failed`, `failure` `generation_failed`) | | |
| 4 | Not a UUID, unknown, deleted, or another institution's (one answer for all) | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{ "data": { "id": "5b0e7c1a-3d2f-4e58-9a61-0c7d4b2e8f13", "kind": "slips_pdf", "group": null, "part": 1, "parts": 1, "voter_count": 4, "status": "queued", "failure": null, "size_bytes": null, "expires_at": "2026-10-12T14:00:00Z", "created_at": "2026-10-10T19:12:05Z" } }
```

### 404, 401, 403, 405

The shared error shape of [API conventions](../README.md).
