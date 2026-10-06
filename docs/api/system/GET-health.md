# GET /api/v1/health

Says whether the API and the services it depends on are working. Used by the home page of
the walking skeleton, by the proxy's health check and by monitoring.

| | |
|---|---|
| Slice | 01 |
| Requirements | NFR-OPS-01 |
| Caller | Public |
| Rate limit | 60 requests per minute per IP address |

## Request

No path parameter, no query parameter, no body.

## What it checks

| Check | How |
|---|---|
| `database` | Runs `SELECT UTC_TIMESTAMP()` on MariaDB. The value read is returned as `time` |
| `redis` | Sends `PING` on the default Redis connection |

Each check has a short timeout (2 seconds), so the endpoint answers quickly when a service
is down.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | The database and Redis answer | 200 | | `answers 200 with the state of the database and Redis` |
| 2 | The database does not answer | 503 | `dependency_unavailable` | `answers 503 when the database does not answer` |
| 3 | Redis does not answer | 503 | `dependency_unavailable` | `answers 503 when Redis does not answer` |
| 4 | Another method than GET or HEAD | 405 | `method_not_allowed` | `answers 405 to another method` |
| 5 | More than 60 requests in a minute from one address | 429 | `too_many_attempts` | `answers 429 above the rate limit` |

## Responses

### 200 — scenario 1

```json
{
  "data": {
    "status": "ok",
    "checks": { "database": "ok", "redis": "ok" },
    "time": "2026-10-06T14:03:27Z"
  }
}
```

`data` has exactly these three fields. `time` is the database server's clock, in UTC, to
the second.

### 503 — scenarios 2 and 3

```json
{
  "error": {
    "code": "dependency_unavailable",
    "message": "Le service est momentanément indisponible.",
    "reference": "1f0c6d0e-3b1a-4c0f-9d59-6a4a6d1b7c11"
  }
}
```

The body does not say which service is down, and holds no detail of the fault. The log
does.

### 405 — scenario 4

Header `Allow: GET, HEAD`.

```json
{ "error": { "code": "method_not_allowed", "message": "Méthode non autorisée." } }
```

### 429 — scenario 5

Header `Retry-After: <seconds>`.

```json
{ "error": { "code": "too_many_attempts", "message": "Trop de requêtes. Réessayez dans un instant." } }
```

## Side effects

None. Nothing is written, no audit entry.

## Notes

- The endpoint is public, so it returns no version number, no host name and no
  configuration value.
- Every answer carries `X-Request-Id`, as on every endpoint.
- `message` follows `Accept-Language` (`fr` by default, `en`). Tests rely on `code` only.
