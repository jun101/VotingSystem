# POST /api/v1/auth/logout

Ends the session.

| | |
|---|---|
| Slice | 02 |
| Requirements | FR-INST-04 |
| Caller | Institution user, signed in |
| Rate limit | none |

## Request

No path parameter, no query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Signed in | 204 | | |
| 2 | Not signed in | 401 | `unauthenticated` | |
| 3 | CSRF token missing | 419 | `csrf_mismatch` | |
| 4 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body. The session is destroyed in Redis and the CSRF token regenerated; the old session
cookie no longer opens anything.

### 401, 419, 405

The shared shapes, with the codes of the table.
