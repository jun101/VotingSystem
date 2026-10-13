# POST /api/v1/users/{user}/password-reset

School mode only. An owner gives a team member who forgot their password a new one-time password, shown once. The
person's sessions end and they must change the password at next sign-in.

| | |
|---|---|
| Slice | 19c |
| Requirements | FR-INST-03, NFR-SEC-01 |
| Caller | Institution user: owner |
| Mode | `DEPLOYMENT_MODE=school`; 404 in `online` mode (the person uses "forgot password") |
| Rate limit | 10 requests per minute per user (shared with the own-settings routes) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| user | UUID | A user of the caller's institution, not removed, not a platform admin, and not the caller |

| Field | Type | Required | Rules |
|---|---|---|---|
| password | string | yes | The caller's own current password |
| code, recovery_code | string | one of the two, only if the caller has two-factor on | The caller's second factor |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Reset a manager's password | 200 | | |
| 2 | Reset another owner's password | 200 | | |
| 3 | `user` is the caller | 409 | `cannot_reset_self` | |
| 4 | `password` missing or wrong; second factor missing or wrong | 422 | as [two-factor reset](POST-users-{user}-two-factor-reset.md) scenarios 3a to 3e | |
| 5 | The user is another institution's, removed, or unknown | 404 | `not_found` | |
| 6 | The caller is a manager | 403 | `forbidden` | |
| 7 | `DEPLOYMENT_MODE` is `online` | 404 | `not_found` | |
| 8 | Not signed in; institution suspended; CSRF; rate limit; method | 401, 403, 419, 429, 405 | the shared codes | |

## Responses

### 200 — scenarios 1 and 2

```json
{ "one_time_password": "T3WK-9HVD-M6PA" }
```

## Side effects

- The user's password is replaced, `must_change_password` is true, every session of the user is ended (their next
  request answers 401), their two-factor settings are left as they are.
- No email. The password is never logged.
