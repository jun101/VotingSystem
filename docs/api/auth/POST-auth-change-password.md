# POST /api/v1/auth/change-password

A signed-in user changes their own password. In school mode it is also how the one-time password of a new or reset
account is replaced at first sign-in.

| | |
|---|---|
| Slice | 19c |
| Requirements | NFR-SEC-01 |
| Caller | Institution user or platform admin, signed in (including a user who must change the password) |
| Rate limit | 10 requests per hour per user |

## Request

| Field | Type | Rules |
|---|---|---|
| current_password | string | Required. The current password (counted with the wrong passwords of sign-in) |
| password | string | Required, 12 to 128 characters, not equal to the email, not equal to the current password |
| code, recovery_code | string | One of the two, only if the user has two-factor on |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid request | 204 | | |
| 2 | The user had to change the password: they can use the other routes afterwards | 204 | | |
| 3 | `current_password` or `password` missing | 422 | `validation_failed` | |
| 4 | `current_password` wrong | 422 | `validation_failed` (`current_password: incorrect`) | |
| 5 | `password` shorter than 12 or longer than 128 | 422 | `validation_failed` (`password: min`, `password: max`) | |
| 6 | `password` equals the email or the current password | 422 | `validation_failed` (`password: same_as_email`, `password: unchanged`) | |
| 7 | Two-factor on and the second factor missing or wrong | 422 | `validation_failed` (`code: required` or `invalid`) | |
| 8 | Not signed in | 401 | `unauthenticated` | |
| 9 | CSRF; too many requests; method | 419, 429, 405 | the shared codes | |

## Side effects

- The password hash is replaced, `must_change_password` is false, and the user's **other** sessions are ended; this
  session continues with a new id.
- The password is never logged.
