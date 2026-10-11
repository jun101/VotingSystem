# php artisan platform:create-admin {email}

The only way to create a platform admin (there is no sign-up for it). Run by the operator on the server.

| | |
|---|---|
| Slice | 18 |
| Requirements | FR-ADM-01, NFR-SEC-02 |
| Caller | The operator, on the command line |

## Behaviour

| # | Scenario | Result | Test |
|---|---|---|---|
| 1 | A valid email not used by any user; password asked twice (questions `Password`, `Confirm password`; hidden) | A user with role `platform_admin`, no institution, email verified, the password hashed as at sign-in; exit code 0; the password is never printed or logged | |
| 2 | The two passwords differ, or the password is shorter than 12 characters | Nothing created; exit code 1 | |
| 3 | The email is invalid | Nothing created; exit code 1 | |
| 4 | The email already belongs to a user (any role, removed or not) | Nothing created; exit code 1 | |

The new admin signs in at the same page as everyone and is sent to `/platform`. The command writes no audit entry
(it is run by the operator, outside the application) and prints the new user's uuid.
