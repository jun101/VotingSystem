# PATCH /api/v1/voter-imports/{import}

Corrects the column mapping of a checked import. The file is checked again in the background.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| import | UUID, path | An import of the caller's institution, status `checked`, whose election is a draft or scheduled |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| mapping | object | Required. Keys among `full_name`, `group`, `identifier`, `email`, `phone`; each value is an index of `columns` (an integer from 0) or `null`. A key that is absent keeps its value. `full_name` may not be `null`. Two fields may not take the same column |

```json
{ "mapping": { "group": 1, "phone": null } }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid mapping | 202 (status back to `uploaded`, counts reset until the check ends) | | |
| 2 | The same mapping as stored | 202 (checked again) | | |
| 3 | `mapping` missing, not an object, an unknown key, an index out of `columns`, `full_name` null, or one column used twice | 422 | `validation_failed` (`mapping: invalid`) | |
| 4 | The import is not `checked` (`uploaded`, `importing`, `done`, `failed`) | 409 | `import_not_checked` | |
| 5 | The election is no longer a draft or scheduled | 409 | `election_voters_locked` | |
| 6 | Not a UUID, unknown, or another institution's import | 404 | `not_found` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 202 — scenarios 1 and 2

The import resource of [GET /voter-imports/{import}](GET-voter-imports-{import}.md).

### 409, 422, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the record (404), the body (422), then
the state (409; scenario 5 before 4).
