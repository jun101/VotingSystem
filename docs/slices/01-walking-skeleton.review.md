# Slice 01 — Walking skeleton: review

2026-10-06 · two reviews (correctness, security) of `main...slice/01-walking-skeleton`.

Severity: **B** blocking, **S** should fix, **N** note. Decision: **fix** in this slice,
or **later** with the slice that takes it. Findings reported by both reviews are merged.

## Findings

| # | Sev. | Where | What is wrong | Decision |
|---|---|---|---|---|
| 1 | B | `api/app/Exceptions/ApiErrorRenderer.php:47`, `api/bootstrap/app.php:26` | The path `/api/` (nothing after the slash) is not matched by `api/*`. A request with a malformed `Host` header gets the framework's debug page: exception class, stack trace, paths, PHP and framework versions. A plain `GET /api/` gets an HTML 404. Reproduced | fix |
| 2 | B | `api/config/logging.php:27`, `compose.yaml` (api service) | The application log goes to standard output inside the Octane worker, and Octane discards that stream: no application log line reaches `docker compose logs api`. A 503 or 500 gives a `reference` that matches nothing in the logs. Not provoked live; rests on the Octane source and on the absence of any JSON line among 195 | fix |
| 3 | S | `api/bootstrap/app.php:16` (no `config/cors.php`) | The framework's default CORS settings are active: every `/api` answer carries `Access-Control-Allow-Origin: *` and any preflight is approved. The application is same-origin and needs none. Reproduced | fix |
| 4 | S | `compose.yaml:29-30`, `api/config/database.php:46` | The `migrator` account's password is in the environment of `api`, `queue` and `scheduler`, and its connection is defined in the serving process. A flaw in the application reaches the schema-owning account | fix |
| 5 | S | `docker/db/init.sh:14` | `app` has `UPDATE` and `DELETE` on the whole database; a database-wide grant cannot be narrowed per table, and `migrator` cannot grant. The tables of database.md section 6 would inherit full rights | fix |
| 6 | S | `api/app/Logging/RedactSensitiveFields.php:15,38` | Redaction covers only array keys of the context. It misses the message, exception messages (a failed query logs its bindings) and objects; the name list misses `authorization`, `cookie`, `session`, `credential`, `ballot`, `choice`, `hash`, `signature`. It also matches substrings, so `voter_uuid`, `status_code`, `error_code` and `cache_key` would be removed | fix |
| 7 | S | `api/app/Http/Resources/ApiResource.php:48,52`, `api/app/Models/Concerns/HasUuid.php:31` | The identifier filter recurses only into plain arrays and matches only snake case. A nested model or collection, or a key like `institutionId`, passes through with its numeric keys (NFR-SEC-08). Nothing uses it yet | fix |
| 8 | S | `.dockerignore:2-3` | `.env` files are excluded only at the root. A local `web/.env.local` or `api/.env` would be copied into an image layer and read by the build | fix |
| 9 | S | `api/bootstrap/app.php:19` | Every proxy and every forwarded header is trusted, and no trusted host is set. Address spoofing was tested and does not work, but `Host`, `X-Forwarded-Port` and `X-Forwarded-Prefix` would shape absolute links once emails exist | fix |
| 10 | S | `api/routes/api.php:8` | `OPTIONS /api/v1/health` answers 200 with an empty HTML body. The endpoint file says any method but GET and HEAD is 405, and every answer under `/api` is JSON. Observed | fix |
| 11 | S | `api/tests/bootstrap.php:20` | Tests force the in-memory cache, though the brief asks for the real Redis: the rate limiter on Redis is exercised by no test | fix |
| 12 | S | `api/app/Providers/AppServiceProvider.php:16`, `api/composer.json:14` | A class of a development-only package is called at every start. An install without development packages fails on every request | fix |
| 13 | N | `api/app/Exceptions/ApiErrorRenderer.php:110-116` | The "service down" test matches the message of any exception, and includes "Access denied". A wrong database password, or an unrelated error whose message says "Connection refused", is answered 503 instead of 500 | fix |
| 14 | N | `docker/proxy/Caddyfile:7-13` | Every answer carries `Via: 1.1 Caddy` | fix |
| 15 | N | `compose.yaml:18,24` | `APP_DEBUG`, `APP_ENV` and `LOG_LEVEL` default to development values when unset | fix |
| 16 | N | `compose.yaml:130` | The database's `root` account accepts connections from any container | fix |
| 17 | N | `compose.yaml:148` | The Redis password is on the command line, readable in the process list | fix |
| 18 | N | `compose.yaml:176` | The browser-test container uses the host's network, so test code and its dependencies reach every service on the machine's loopback | fix |
| 19 | N | `docker/scripts/audit-keypair.sh:18-22,35,38` | Nothing refuses a private-key path inside the repository; the directory of any given path is re-moded; the temporary copy of `.env` is not removed on failure; a second clone on the same machine stops at setup with no hint | fix |
| 20 | N | `Makefile:65` | `generate-check` writes to predictable names in the shared temporary directory | fix |
| 21 | N | `Makefile:78` | The dependency audit skips development packages and anything below high | fix |
| 22 | N | `docker/db/init.sh`, `Makefile:26` | The test database's name is written literally in the Makefile but derived from `DB_DATABASE` elsewhere. Nothing tells a developer that a kept database volume with a new `.env` cannot start | fix |
| 23 | N | `web/src/lib/api/health.ts:27`, `web/src/app/page.tsx:16` | Any answer but 200, including 429, shows the system as down. Three runs of the browser tests in a minute, or busy browsing, fail the home tests | fix |
| 24 | N | `api/config/database.php:22` | The 2-second limit of the health check covers the connection only, not a database that connects and then hangs | fix |
| 25 | N | `api/tests/Feature/WorkerStateTest.php:31`, `RequestIdTest.php:15`, web component tests | The request-id and worker-state tests read the shared log context, not a written line; no test writes through the real log channel (which is why finding 2 was not seen). Component unit tests assert class names only | fix (log test); class-name tests accepted, the browser tests cover the real sizes and colours |
| 26 | N | `docker/api/Dockerfile:3`, `compose.yaml:43-61` | No `USER` in the images, no dropped capabilities, no read-only root, images pinned by tag and not by digest | later: slice 20 |

## How each fix is to be done

| # | Fix |
|---|---|
| 1 | Render every exception of the API application through the one renderer, whatever the path: nothing but the API is served by it. Add tests for `/api`, `/api/` and a malformed host |
| 2 | Write the log where Octane relays it, raw. Add a test that writes through the real channel configuration into memory and checks the JSON line, its request id and the redaction. Then provoke a 503 on the running stack and find its `reference` in `docker compose logs api` |
| 3 | A CORS configuration with no path, and a test that no `Access-Control-*` header is returned |
| 4 | The `migrator` password is given only to the one-off migration commands. The serving containers do not have it and define no such connection |
| 5 | `app` receives its rights table by table, applied after each migration by a step that reads one declared list: full read and write by default, no `UPDATE` and no `DELETE` on the tables named in database.md section 6. A test checks the grants of a protected table created for the test |
| 6 | Query errors are logged without their bindings. The processor also covers messages and nested values. Names are matched as whole segments, from an explicit list that includes the missing ones |
| 7 | Nested models, collections and anything convertible to an array are converted, then filtered; camel-case keys are matched too. Tests for each case |
| 8 | Exclude `.env` files at any depth, keeping `.env.example` and `api/.env.testing` |
| 9 | Trust only the Compose network, and only the forwarded address and scheme. Trusted hosts taken from `APP_URL` |
| 10 | A non-preflight `OPTIONS` under `/api` answers the 405 error shape. Acceptance test added to the health file's scenario 4 |
| 11 | Tests use the Redis cache, on its own database number |
| 12 | Guard the call so that an install without development packages starts |
| 13 | Only a connection failure raised by the database or Redis client is a 503. "Access denied" is a 500 |
| 14–17 | Remove `Via`; default to production values in the Compose file and set development values in `.env.example`; `root` limited to the database container itself; Redis password from a file |
| 18 | The browser tests join the Compose network and reach the proxy by its service name |
| 19 | Refuse a path under the repository; change the mode only of a directory the script creates; remove the temporary file on exit; explain the second-clone case in the read-me |
| 20, 21 | A private temporary directory; audit every dependency, with a dated list of accepted advisories |
| 22 | Derive the test database's name in the Makefile; a read-me note on recreating the volume |
| 23 | The page's health call is cached for two seconds. A 429 means the API is up: the API row stays online and the two others show "unknown" |
| 24 | A statement time limit on the health query |

## Confirmed sound

- A visitor cannot forge an address to escape or poison the rate limit.
- Only two ports are published, both on the local interface; the database, Redis, the
  API and the web server publish none. Admin endpoints of the proxy and the API server
  are not reachable.
- No documentation page is served. No `Server` or `X-Powered-By` header.
- `.env` is untracked and readable by its owner only; no secret is tracked.
- The `locale` cookie is checked against a fixed list; nothing is rendered from user
  input.
- Language and log context are reset for each request under Octane.
- CI: read-only permissions, no secret, pinned actions that exist upstream.
- Acceptance tests and harness were not modified by the coding commits.

## Not verified by the reviews

- `make setup` on a fresh clone and the CI workflow: read, not run.
- `/dev/components` answering 404 in a production build: read in the code; the developer
  reports having tried it on the production image.
- "Done when" item 4 (stopping the database): the reviews could not stop containers; the
  developer reports having tried it.

## Outcome of the fixes

2026-10-06. Findings 1 to 25 are closed, 26 stays for slice 20. The whole check suite
passes: 91 API tests, 55 web unit tests, 55 browser tests (1 skipped by design), lint,
generated files, production build, audit.

| # | Outcome |
|---|---|
| 1, 3, 10 | Fixed; covered by acceptance tests; confirmed on the running stack |
| 2 | Fixed: the log is written to the stream the server relays. Confirmed by stopping the database: the `reference` of the 503 is found in the API's log as a JSON line with its request id |
| 4, 5 | Fixed: the serving containers no longer hold the schema account. Rights are granted table by table by a command run after each migration; the tables of database.md section 6 receive insert only. The API tests run in their own one-off service, the only one besides migrations to hold that account |
| 11 | Not changed as written: the API tests keep the in-memory cache, because the "Redis does not answer" acceptance test needs Redis untouched before it runs. A separate test builds the rate limiter on the real Redis and obtains a 429 |
| 17 | Fixed differently: the Redis password is read from a mounted configuration and is no longer on the command line; it remains in the container's environment |
| 21 | Every dependency is audited; one advisory in a development tool with no fixed release is accepted until 2026-11-06 |
| others | Fixed as planned |

One acceptance test written for finding 1 was wrong and was corrected: the test client
cannot send a malformed `Host` header, so the request is now built by hand.
