# GET /api/v1/auth/csrf

Starts the browser's session if it has none and sets the CSRF cookie. The web application
calls it once before its first state-changing request.

| | |
|---|---|
| Slice | 02 |
| Requirements | NFR-SEC-04 |
| Caller | Public |
| Rate limit | 60 requests per minute per IP address |

## Request

No path parameter, no query parameter, no body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Any request | 204 | | |
| 2 | More than 60 requests in a minute from one address | 429 | `too_many_attempts` | |
| 3 | Another method than GET or HEAD | 405 | `method_not_allowed` | |

## Responses

### 204 — scenario 1

No body. Two cookies are set:

| Cookie | Properties |
|---|---|
| `XSRF-TOKEN` | Readable by the page's script; `SameSite=Lax`; `Secure` outside local development; path `/` |
| session cookie (name from `SESSION_COOKIE`, default `new_voting_system_session`) | `HttpOnly`; `SameSite=Lax`; `Secure` outside local development; path `/`; lifetime 120 minutes since the last request |

The web application copies the `XSRF-TOKEN` value into the `X-XSRF-TOKEN` header of every
request that changes something.

## Side effects

A session is created in Redis. Nothing in the database.

## Notes

The session is shared by every `/api/v1` route that needs it. The voter session of slice 12
is a different cookie.
