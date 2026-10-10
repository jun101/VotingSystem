# New Voting System — API conventions

Version 1.0 · 2026-10-06 · goes with [SPEC.md](../SPEC.md) 1.3 and
[architecture.md](../design/architecture.md).

This folder is the API contract. Every endpoint has its own file, written before the
code; the acceptance tests follow it, one test per scenario. `openapi.json`, in this
folder, is generated from the code and never edited by hand.

## 1. Basics

| Subject | Rule |
|---|---|
| Base path | `/api/v1` |
| Format | JSON in and out, UTF-8. Every answer under `/api` is JSON, whatever the `Accept` header says, errors included. File uploads use `multipart/form-data`; file downloads return the file |
| Identifiers | Every identifier is a random UUID, in a field named `id`. The database's numeric keys never appear (SPEC NFR-SEC-08) |
| Related records | Named by UUID: `"ballot": "0b0e…"` in a request, a nested object or a UUID in a response. Never a `*_id` number |
| Dates | ISO 8601 in UTC with `Z`: `2026-11-20T13:00:00Z`. An election also returns its `timezone` so the screen can show local time |
| Language | `Accept-Language: fr` or `en`. It affects only the human `message` of an error |
| Field names | `snake_case` |
| Unknown fields | Ignored in a request; never an error |

## 2. Who calls

| Caller | Paths | Identified by |
|---|---|---|
| Public | `/public/…`, `/vote/{election}` | Nothing |
| Institution user | everything else | Session cookie |
| Platform admin | `/platform/…` | Session cookie, role `platform_admin` |
| Voter | `/vote/{election}/…` | Voter session cookie |

Every request that changes something carries the CSRF token in the `X-XSRF-TOKEN`
header (SPEC NFR-SEC-04).

## 3. Response shapes

One record:

```json
{ "data": { "id": "6f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa", "title": "Conseil des élèves 2026" } }
```

A list, always paginated:

```json
{
  "data": [ { "id": "…" } ],
  "meta": { "page": 1, "per_page": 25, "total": 312 }
}
```

`?page=` and `?per_page=` (25 by default, 100 at most). Filters and sorting are query
parameters described in each endpoint file.

A background job that was accepted:

```json
{ "data": { "id": "…", "status": "queued", "progress": 0 } }
```

An error, always this shape:

```json
{
  "error": {
    "code": "election_not_editable",
    "message": "Cette élection ne peut plus être modifiée.",
    "fields": { "ends_at": ["after_start"] }
  }
}
```

- `code` is stable and is what the front end and the tests rely on.
- `message` is for a person and may change.
- `fields` is present only on a validation error: field name → list of rule codes.
- `reference` is present only on a 500 and a 503: the value of the `X-Request-Id`
  header, for the person to quote. Nothing else about the fault is ever returned: no
  exception name, no file, no trace, whatever the debug setting.

## 4. Status codes

Each endpoint file lists the ones it can return, with the scenario for each. No endpoint
returns a code that is not in this table.

| Code | Meaning here | Typical `error.code` |
|---|---|---|
| **200** OK | Read or change succeeded; a body is returned | |
| **201** Created | A record was created; the body is the record | |
| **202** Accepted | A background job was queued; the body gives its id and status | |
| **204** No Content | Succeeded, nothing to return (delete, sign-out) | |
| **400** Bad Request | The body is not valid JSON, or a parameter cannot be read | `malformed_request` |
| **401** Unauthorized | Not signed in, or the session or voter session has ended | `unauthenticated`, `invalid_credentials`, `voter_session_expired` |
| **403** Forbidden | Signed in, but the role does not allow it; email not verified; institution suspended | `forbidden`, `email_not_verified`, `institution_suspended` |
| **404** Not Found | The record does not exist **or belongs to another institution**. The two cases give the same answer on purpose | `not_found` |
| **405** Method Not Allowed | The path exists but not with this method; the `Allow` header lists the accepted ones | `method_not_allowed` |
| **409** Conflict | The request is valid but the record's state refuses it | `election_not_editable`, `already_voted`, `already_verified`, `last_owner`, … |
| **410** Gone | It existed and has expired: an invitation, a reset link, a generated file | `expired` |
| **413** Content Too Large | Upload above the limit | `file_too_large` |
| **415** Unsupported Media Type | Upload whose content is not an accepted type | `file_type_not_allowed` |
| **419** | CSRF token missing or wrong | `csrf_mismatch` |
| **422** Unprocessable Content | Validation failed; `fields` says where | `validation_failed` |
| **429** Too Many Requests | Rate limit; `Retry-After` header gives the seconds | `too_many_attempts` |
| **500** Internal Server Error | A fault on the server; the body holds only the code, a message and a `reference` | `server_error` |
| **503** Service Unavailable | Maintenance, or a service the API depends on is down | `maintenance`, `dependency_unavailable` |

For an endpoint that needs a signed-in user, **401 is checked before the CSRF check (419)**:
a request with no session and no token answers 401.

For an endpoint reserved for owners, the **role is checked before the rate limit**: a manager
who calls it again and again gets 403 every time, never 429.

Choosing between the close ones:

- Wrong shape or value of the input → **422**. Right input, wrong moment → **409**.
- Never **403** for another institution's record: it would confirm the record exists.
  Always **404**.
- An access code that is wrong, reissued or already used is not a validation error; the
  voter endpoints define their own codes in their files.

## 5. Headers

| Header | Direction | Use |
|---|---|---|
| `X-Request-Id` | Response | A random UUID set by the server on every answer; identifies the request in the logs. A value sent by the client is ignored |
| `Retry-After` | Response, with 429 and 503 | Seconds to wait |
| `X-XSRF-TOKEN` | Request | CSRF token |
| `Accept-Language` | Request | `fr` or `en` |

## 6. Layout of this folder

```
docs/api/
  README.md                         this file
  openapi.json                      generated
  system/                           GET-health.md
  auth/                             POST-auth-register.md, POST-auth-login.md, …
  institution/
  users/
  elections/                        GET-elections.md, POST-elections.md, GET-elections-{election}.md, …
  ballots/
  parties/
  candidates/
  voter-groups/
  voters/
  imports/
  credentials/
  vote/
  turnout/
  results/
  public/
  audit/
  platform/
```

File name: the method, then the path with `/` replaced by `-`, parameters kept in
braces. `DELETE /ballots/{ballot}` is `ballots/DELETE-ballots-{ballot}.md`. A record that
has a UUID of its own is addressed directly (`/ballots/{ballot}`); only its collection
(list, create, reorder) is under its parent (`/elections/{election}/ballots`).

## 7. Template of an endpoint file

````markdown
# POST /api/v1/elections

Creates a draft election.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-01, FR-ELEC-02 |
| Caller | Institution user: owner or manager |
| Rate limit | none |

## Request

Path parameters: none.

| Field | Type | Required | Rules |
|---|---|---|---|
| title | string | yes | 1 to 200 characters |
| starts_at | date-time | yes | |
| ends_at | date-time | yes | after `starts_at` |

```json
{ "title": "Conseil des élèves 2026", "starts_at": "2026-11-20T13:00:00Z", "ends_at": "2026-11-20T20:00:00Z" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid request | 201 | | `creates a draft election` |
| 2 | Not signed in | 401 | `unauthenticated` | … |
| 3 | Email not verified | 403 | `email_not_verified` | … |
| 4 | `title` missing | 422 | `validation_failed` (`title: required`) | … |
| 5 | `ends_at` before `starts_at` | 422 | `validation_failed` (`ends_at: after_start`) | … |
| 6 | CSRF token missing | 419 | `csrf_mismatch` | … |

## Responses

### 201 — scenario 1

```json
{ "data": { "id": "…", "title": "Conseil des élèves 2026", "status": "draft" } }
```

### 422 — scenario 4

```json
{ "error": { "code": "validation_failed", "message": "…", "fields": { "title": ["required"] } } }
```

(one block per status code that has a body)

## Side effects

What is written besides the record: audit entry, email, job.

## Notes

Anything a reader would get wrong without being told.
````

Rules for a file:

- Every scenario has a row, including the ones every endpoint shares (not signed in,
  wrong role, other institution, CSRF, rate limit) when they apply.
- Every status code in the scenario table has a response block, with the exact body.
- The **Test** column is filled with the test's name when the acceptance tests are
  written, and checked against the real responses before the slice is approved.

## 8. Index

Filled slice by slice: each slice adds its endpoints here with a link to their files.

| Area | Endpoint | Slice | File |
|---|---|---|---|
| System | `GET /health` | 01 | [system/GET-health.md](system/GET-health.md) |
| Auth | `GET /auth/csrf` | 02 | [auth/GET-auth-csrf.md](auth/GET-auth-csrf.md) |
| Auth | `POST /auth/register` | 02 | [auth/POST-auth-register.md](auth/POST-auth-register.md) |
| Auth | `POST /auth/verify-email` | 02 | [auth/POST-auth-verify-email.md](auth/POST-auth-verify-email.md) |
| Auth | `POST /auth/verify-email/resend` | 02 | [auth/POST-auth-verify-email-resend.md](auth/POST-auth-verify-email-resend.md) |
| Auth | `POST /auth/login` | 02 | [auth/POST-auth-login.md](auth/POST-auth-login.md) |
| Auth | `POST /auth/logout` | 02 | [auth/POST-auth-logout.md](auth/POST-auth-logout.md) |
| Auth | `GET /auth/me` | 02 | [auth/GET-auth-me.md](auth/GET-auth-me.md) |
| Auth | `PATCH /auth/me` | 03 | [auth/PATCH-auth-me.md](auth/PATCH-auth-me.md) |
| Auth | `POST /auth/forgot-password` | 02 | [auth/POST-auth-forgot-password.md](auth/POST-auth-forgot-password.md) |
| Auth | `POST /auth/reset-password` | 02 | [auth/POST-auth-reset-password.md](auth/POST-auth-reset-password.md) |
| Auth | `POST /auth/accept-invitation` | 04 | [auth/POST-auth-accept-invitation.md](auth/POST-auth-accept-invitation.md) |
| Institution | `GET /institution` | 04 | [institution/GET-institution.md](institution/GET-institution.md) |
| Institution | `PATCH /institution` | 04 | [institution/PATCH-institution.md](institution/PATCH-institution.md) |
| Institution | `PUT /institution/logo` | 04 | [institution/PUT-institution-logo.md](institution/PUT-institution-logo.md) |
| Institution | `DELETE /institution/logo` | 04 | [institution/DELETE-institution-logo.md](institution/DELETE-institution-logo.md) |
| Users | `GET /users` | 04 | [users/GET-users.md](users/GET-users.md) |
| Users | `DELETE /users/{user}` | 04 | [users/DELETE-users-{user}.md](users/DELETE-users-{user}.md) |
| Users | `GET /invitations` | 04 | [users/GET-invitations.md](users/GET-invitations.md) |
| Users | `POST /invitations` | 04 | [users/POST-invitations.md](users/POST-invitations.md) |
| Users | `DELETE /invitations/{invitation}` | 04 | [users/DELETE-invitations-{invitation}.md](users/DELETE-invitations-{invitation}.md) |
| Auth | `GET /auth/two-factor` | 04b | [auth/GET-auth-two-factor.md](auth/GET-auth-two-factor.md) |
| Auth | `POST /auth/two-factor/setup` | 04b | [auth/POST-auth-two-factor-setup.md](auth/POST-auth-two-factor-setup.md) |
| Auth | `POST /auth/two-factor/confirm` | 04b | [auth/POST-auth-two-factor-confirm.md](auth/POST-auth-two-factor-confirm.md) |
| Auth | `POST /auth/two-factor/disable` | 04b | [auth/POST-auth-two-factor-disable.md](auth/POST-auth-two-factor-disable.md) |
| Auth | `POST /auth/two-factor/recovery-codes` | 04b | [auth/POST-auth-two-factor-recovery-codes.md](auth/POST-auth-two-factor-recovery-codes.md) |
| Auth | `POST /auth/two-factor-challenge` | 04b | [auth/POST-auth-two-factor-challenge.md](auth/POST-auth-two-factor-challenge.md) |
| Users | `POST /users/{user}/two-factor/reset` | 04b | [users/POST-users-{user}-two-factor-reset.md](users/POST-users-{user}-two-factor-reset.md) |
| Elections | `GET /elections` | 05 | [elections/GET-elections.md](elections/GET-elections.md) |
| Elections | `POST /elections` | 05 | [elections/POST-elections.md](elections/POST-elections.md) |
| Elections | `GET /elections/{election}` | 05 | [elections/GET-elections-{election}.md](elections/GET-elections-{election}.md) |
| Elections | `PATCH /elections/{election}` | 05 | [elections/PATCH-elections-{election}.md](elections/PATCH-elections-{election}.md) |
| Elections | `DELETE /elections/{election}` | 05 | [elections/DELETE-elections-{election}.md](elections/DELETE-elections-{election}.md) |
| Elections | `POST /elections/{election}/duplicate` | 05 | [elections/POST-elections-{election}-duplicate.md](elections/POST-elections-{election}-duplicate.md) |
| Elections | `PUT /elections/{election}/cover` | 05 | [elections/PUT-elections-{election}-cover.md](elections/PUT-elections-{election}-cover.md) |
| Elections | `DELETE /elections/{election}/cover` | 05 | [elections/DELETE-elections-{election}-cover.md](elections/DELETE-elections-{election}-cover.md) |
| Ballots | `GET /elections/{election}/ballots` | 06a | [ballots/GET-elections-{election}-ballots.md](ballots/GET-elections-{election}-ballots.md) |
| Ballots | `POST /elections/{election}/ballots` | 06a | [ballots/POST-elections-{election}-ballots.md](ballots/POST-elections-{election}-ballots.md) |
| Ballots | `PUT /elections/{election}/ballots/order` | 06a | [ballots/PUT-elections-{election}-ballots-order.md](ballots/PUT-elections-{election}-ballots-order.md) |
| Ballots | `PATCH /ballots/{ballot}` | 06a | [ballots/PATCH-ballots-{ballot}.md](ballots/PATCH-ballots-{ballot}.md) |
| Ballots | `DELETE /ballots/{ballot}` | 06a | [ballots/DELETE-ballots-{ballot}.md](ballots/DELETE-ballots-{ballot}.md) |
