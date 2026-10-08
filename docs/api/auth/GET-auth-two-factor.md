# GET /api/v1/auth/two-factor

The state of the signed-in user's own two-factor authentication.

| | |
|---|---|
| Slice | 04b |
| Requirements | FR-INST-04 |
| Caller | Institution user or platform admin, signed in |
| Rate limit | none |

## Request

No path parameter, no query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Two-factor is not turned on | 200 | | |
| 2 | A setup was started and not confirmed | 200 | | |
| 3 | Two-factor is turned on | 200 | | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | Another method than GET or HEAD | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 to 3

```json
{ "data": { "enabled": true, "setup_started": false, "recovery_codes_left": 6 } }
```

- `enabled`: the second factor is confirmed and asked at sign-in.
- `setup_started`: a secret was issued by [setup](POST-auth-two-factor-setup.md) and not
  confirmed yet (`enabled` is then false).
- `recovery_codes_left`: how many unused recovery codes remain, `null` when not enabled.
- Neither the secret nor a code is ever returned here.

### 401, 403, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.
