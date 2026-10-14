# php artisan owner:create, user:reset-password, user:list

The operator's commands for an installation with no email (school mode, slice 19c), run on the server by the person who
installed it, or by the installer. Nobody signs in, so they cross institutions; the output names users and institutions
by uuid, never by a database id, and no password is ever written to a log.

| | |
|---|---|
| Slice | 19c, step B |
| Requirements | FR-INST-01, FR-INST-03, NFR-SEC-01 |
| Caller | The operator, on the command line (or `votectl`, or the installer) |

## `owner:create {email} --institution=NAME [--name=NAME] [--language=fr|en] [--institution-uuid=UUID] [--credentials-file=PATH]`

Creates an institution and its first owner, with a one-time password (the owner must change it at the first sign-in), or
adds another owner to an existing institution with `--institution-uuid`.

| # | Scenario | Result | Test |
|---|---|---|---|
| 1 | A valid email, `--institution` given | An institution (type `school`, timezone `America/Port-au-Prince`, the language) and an owner (email verified, `must_change_password` true); exit 0; prints the institution's and the owner's uuid and the one-time password, once | |
| 2 | `--credentials-file` given | The block of lines (institution, address, one-time password) is **appended** to the file, created with mode 0600; the password is not printed; the uuids are | |
| 3 | `--institution-uuid` of an existing institution | An owner is added to it; no institution is created | |
| 4 | `--institution-uuid` unknown | Nothing created; exit 1 | |
| 5 | The email is invalid or already belongs to a user (any role, removed or not) | Nothing created; exit 1 | |
| 6 | Neither `--institution` nor `--institution-uuid` | Nothing created; exit 1 | |
| 7 | `--language` is neither `fr` nor `en` | Nothing created; exit 1 | |

## `user:reset-password {email} [--credentials-file=PATH]`

Replaces the password of a live user (an owner, a manager or a platform admin) with a one-time password; their sessions
end and they must change it at the next sign-in. Exit 1 with the same line for an unknown or removed address.

| # | Scenario | Result | Test |
|---|---|---|---|
| 1 | A live user | New one-time password printed once (or appended to the file as in `owner:create`); the user's uuid is printed; exit 0 | |
| 2 | Unknown or removed address | One line, "No user has this address."; exit 1 | |

## `user:list [--institution=UUID]`

Prints one line per live user: name, address, role, institution (name), two-factor (`yes` or `no`), last sign-in (UTC or `-`).
No uuid of the user, no database id. `--institution` limits it to one institution; an unknown uuid exits 1.
