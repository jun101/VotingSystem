# POST /api/v1/users

School mode only. An owner creates a team member directly (there is no email to send an invitation to) and receives a
one-time password, shown once, to hand to the person. The person must change it at first sign-in.

| | |
|---|---|
| Slice | 19c |
| Requirements | FR-INST-03, NFR-SEC-01 |
| Caller | Institution user: owner |
| Mode | `DEPLOYMENT_MODE=school`; in `online` mode this method is not allowed on `/users` (405, `Allow: GET, HEAD`, as before this slice) and invitations are used |
| Rate limit | 20 requests per hour per user |

## Request

| Field | Type | Rules |
|---|---|---|
| name | string | Required, 1 to 100 characters after trimming |
| email | string | Required. The sign-in identifier: a valid address, at most 255 characters, compared in lower case. No email is ever sent; it may be any address, for example `name@school.local` |
| role | string | Required. `owner` or `manager` |
| password | string | Required. The **caller's** own current password (counted with the wrong passwords of sign-in) |
| code, recovery_code | string | One of the two, only if the caller has two-factor on |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid request, role `manager` | 201 | | |
| 2 | Valid request, role `owner` | 201 | | |
| 3 | `name`, `email`, `role` or `password` missing | 422 | `validation_failed` | |
| 4 | `email` not an address, longer than 255 | 422 | `validation_failed` (`email: email`, `email: max`) | |
| 5 | `role` not `owner` or `manager` | 422 | `validation_failed` (`role: in`) | |
| 6 | The address already belongs to a user (any institution, any letter case) | 422 | `validation_failed` (`email: taken`) | |
| 7 | The caller's `password` wrong | 422 | `validation_failed` (`password: incorrect`) | |
| 8 | The caller has two-factor on and sends no valid second factor | 422 | `validation_failed` (`code: required` or `invalid`) | |
| 9 | The caller is a manager | 403 | `forbidden` | |
| 10 | `DEPLOYMENT_MODE` is `online` | 405 | `method_not_allowed` | |
| 11 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 12 | The institution was suspended since sign-in | 403 | `institution_suspended` | |
| 13 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 14 | Too many requests | 429 | `too_many_attempts` | |
| 15 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 and 2

```json
{
  "data": { "id": "…", "name": "Jean Pierre", "email": "j.pierre@ecole.local", "role": "manager", "email_verified": true, "last_login_at": null, "two_factor_enabled": false, "is_you": false },
  "one_time_password": "K7MP-4QX9-RTBC"
}
```

`data` has the shape of one item of [GET /users](GET-users.md). `one_time_password` is the only place the password is ever
shown: three groups of four characters from the alphabet of access codes, drawn with a secure random source.

## Side effects

- One row in `users`, in the caller's institution, `email_verified_at` set, `must_change_password` true.
- No email. Nothing is logged but the outcome (never the password).
- The user's first sign-in succeeds, then every route except `GET /auth/me`, `POST /auth/logout` and
  [POST /auth/change-password](../auth/POST-auth-change-password.md) answers 403 `password_change_required`.
