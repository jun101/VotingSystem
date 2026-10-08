# New Voting System — Architecture

Version 1.0 · 2026-10-06 · goes with [SPEC.md](../SPEC.md) 1.3,
[database.md](database.md), [frontend.md](frontend.md) and
[the API conventions](../api/README.md).

## 1. Overview

```
                    ┌──────────── one host, one origin ────────────┐
 browser ── HTTPS ──▶  proxy ──┬── /api/*    ──▶ api  ──┬── MariaDB │
                               ├── /media/*  ──▶ files  ├── Redis   │
                               └── the rest  ──▶ web ───┘           │
                               queue worker and scheduler: same     │
                               image as api, no port open           │
                    └───────────────────────────────────────────────┘
```

Everything is served from **one origin**. The browser never talks to a second domain, so
there is no cross-origin configuration, cookies are first-party, and the
Content-Security-Policy can stay strict (NFR-SEC-04).

## 2. Services

One Compose file for development (`compose.yaml`) and an override for production
(`compose.prod.yaml`). The whole system starts with `docker compose up` (NFR-OPS-01).

| Service | Image built from | Role |
|---|---|---|
| `proxy` | Caddy | Terminates HTTPS (automatic certificates in production), routes by path, serves `/media`, sets the security headers |
| `api` | `api/` (PHP 8.4, Laravel Octane on FrankenPHP) | The REST API. Plain HTTP on the internal network only |
| `queue` | same image as `api`, plain command line, no Octane | Background jobs: imports, PDF generation, emails (NFR-PERF-03) |
| `scheduler` | same image as `api` | Runs the scheduled commands every minute (NFR-OPS-03) |
| `web` | `web/` (Node, Next.js standalone build) | Pages |
| `db` | MariaDB | Data |
| `redis` | Redis | Sessions, cache, queues, rate limits |
| `mailpit` | Mailpit | Catches emails in development only |

Exact versions are pinned in slice 01 to the current stable release of each, and checked
again at each slice review.

## 3. Repository layout

```
api/                 Laravel application
web/                 Next.js application
docker/              Dockerfiles and proxy configuration
docs/
  SPEC.md
  design/            architecture, database, front end, mockups
  api/               README.md (conventions), one file per endpoint, openapi.json (generated)
  slices/            per slice: brief, review, description of what was done
compose.yaml
compose.prod.yaml
Makefile             make up, make check, make test, ...
.env.example
.github/workflows/   CI
```

## 4. API application

### 4.1 Structure

| Layer | Holds | Rule |
|---|---|---|
| Routes | `routes/api.php`, grouped by area | Records are bound by `uuid`, never by `id` |
| Form requests | Validation and authorisation of one endpoint | Related records are named by `uuid` in the body |
| Controllers | One per resource, thin | No query, no business rule |
| Actions | One class per use case (`ScheduleElection`, `CastVote`, `ReissueCredential`) | All business rules; each runs in a transaction when it writes more than one row |
| Models | Eloquent models and their scopes | Tenant scope applied by a shared trait |
| Policies | Who may do what | One per model |
| API resources | The JSON shape of a record | Never output `id` or a `*_id` column |
| Jobs | Background work | Safe to run twice |

### 4.2 A long-running application

The API runs under Laravel Octane with the FrankenPHP server: the application is loaded
once and stays in memory, and each worker serves many requests. It answers faster, and it
changes one rule: **nothing that belongs to a request may outlive it.**

- No request data in a singleton, a static property or a service built once: not the
  signed-in user, not the institution, not the voter, not the language, not the request
  id.
- The tenant scope reads the institution from the current request each time; it never
  remembers it.
- Workers are recycled after a fixed number of requests.
- A test in every slice that adds per-request state shows that two requests in a row,
  from two different callers, do not see each other's data.

The separate `proxy` service stays in front of it: it is the only public entry, so the
API can be restarted without taking the pages or the certificates down.

### 4.3 Four kinds of caller

| Caller | Routes | Identified by |
|---|---|---|
| Institution user | `/api/v1/…` | Session cookie after sign-in (same-origin cookie session; no token in the browser's storage) |
| Platform admin | `/api/v1/platform/…` | Same sign-in; role `platform_admin` required by middleware |
| Voter | `/api/v1/vote/…` | A voter session opened with the access code (section 6) |
| Public | `/api/v1/public/…` | Nothing; read-only, published data only |

Sign-up, email verification, password reset and two-factor authentication use the
framework's headless authentication package rather than hand-written flows.

### 4.4 Tenant isolation (FR-INST-05, NFR-SEC-03)

Three layers, each enough on its own:

1. **Global scope.** Every tenant model uses one trait that adds
   `WHERE institution_id = <the signed-in user's institution>` to every query, and fills
   the column on insert. Code that needs to cross tenants (platform admin, scheduler,
   public pages) must remove the scope by name, which makes it visible in review.
2. **Route binding.** A record named in a URL is looked up through the scope, so another
   institution's `uuid` gives **404**, the same answer as a `uuid` that does not exist.
3. **Policies.** Every action checks the record's institution and the user's role.

**Limit of the model rules.** The trait's rules (institution filled on create, refused when
it names another institution, never changed on save) run in model events. A builder-level
write (`Model::query()->update()`, `insert()`, `upsert()`, `DB::table()`) fires no model
event, so none of them applies. The global scope still limits `query()->update()`, but
`insert()`, `upsert()` and `DB::table()` are unscoped. Rule for later slices: write a tenant
record through its model; never name `institution_id` in a builder-level write. A source
test (`tests/Feature/TenantBuilderWriteTest.php`) fails when the application does.

A test suite written in slice 03 creates two institutions and checks every route of the
first against a user of the second. A test added to that suite fails if a new route is
not covered.

### 4.5 Identifiers (NFR-SEC-08)

No numeric `id` leaves the server: not in a URL, a body, a header, a cookie, a file name,
an email, a PDF, an export, an error message or a page. Enforced by construction (API
resources, route binding), by an automated test that calls every route and scans the
response, and by review.

### 4.6 Errors

Every error has a stable machine code; the front end translates it. Shapes and status
codes are in [the API conventions](../api/README.md).

## 5. Web application

Described in [frontend.md](frontend.md). In short:

- The **voting flow** and the **public pages** are rendered on the server and ship very
  little JavaScript (NFR-PERF-01). Public pages are rendered on the server so that the
  sharing preview works (FR-RES-05).
- The **admin area** is a signed-in application that reads the API from the browser.
- Next.js never reads the database. It only calls the API: from the browser through the
  proxy, from the server through the internal network.

## 6. Voting flow

| Step | Call | Server |
|---|---|---|
| Open the election's voting page | `GET /api/v1/vote/{election}` | Public information only: title, institution, period, status |
| Enter the code | `POST /api/v1/vote/{election}/session` | Rate limit; hash the code; find the credential; refuse if already voted; open a voter session |
| See identity and ballots | `GET /api/v1/vote/{election}/ballots` | Name, group, and only the ballots the voter is eligible for |
| Confirm | `POST /api/v1/vote/{election}/submit` | The transaction of [database.md](database.md) section 4 |

**Voter session.** A random 256-bit token in a cookie that is `HttpOnly`, `Secure`,
`SameSite=Strict` and limited to the `/api/v1/vote` path. The server keeps the token in
Redis with the voter, for 15 minutes at most (FR-VOTE-06). It is not the admin session and
gives access to nothing else.

**Retry.** If the answer to `submit` is lost on a bad connection, the voter's device
sends it again. The session is kept for its remaining minutes in a "voted" state that
answers the same confirmation again, without writing anything. The voter is always told
whether the vote was recorded (SPEC section 11).

**The code in the link.** The personal link carries the code after `#`. Browsers do not
send that part to the server, so it is in no log (FR-CRED-06). The page reads it, sends it
in the body of the session call, and removes it from the address bar.

**Rate limit on code entry (FR-VOTE-08).** Counted on failures only, per IP address and
election together, with a limit sized for a class behind one address; a second, higher
limit per election slows a distributed guess. Numbers are set in slice 12.

## 7. Time

- The scheduler runs one command every minute that opens elections whose start has
  passed and closes those whose end has passed.
- The vote transaction checks the time itself, so a late scheduler never lets a vote in
  after the end (FR-ELEC-05, NFR-OPS-03).
- All servers run in UTC. The election's timezone is used only to read and show times.

## 8. Background jobs

| Job | Slice | Notes |
|---|---|---|
| Check and import a voter file | 08 | Streams the file; never evaluates a formula (NFR-SEC-06) |
| Generate codes and the slip PDF or export file | 10, 11 | One job writes the codes and the file, since a code cannot be read back later |
| Send one code email | 11 | Rate-limited; status written on the credential |
| Delete expired generated files | 10 | Scheduled |
| Re-encode an uploaded image | 04, 06 | Produces the fixed sizes; drops the original |

Progress is read by polling a status endpoint. No WebSocket server: one less service on a
small host.

## 9. Secrets and keys

All from the environment (NFR-SEC-07). `.env` is never committed; `.env.example` lists the
names.

| Variable | Protects |
|---|---|
| `APP_KEY` | Sessions, the framework's encrypted columns, encrypted files |
| `VOTE_AUDIT_PUBLIC_KEY` | Encrypts the vote audit records (FR-SEC-08). 64 hex characters. Not a secret: it cannot decrypt. The matching private key is never given to the server (NFR-SEC-09) |
| `CREDENTIAL_HASH_KEY` | The keyed hash of access codes (NFR-SEC-02) |
| `DB_*`, `REDIS_*`, `MAIL_*` | Service passwords |

## 10. Logs

Structured JSON on standard output, collected by Docker. One request id per request,
returned in the `X-Request-Id` header. A log processor removes any field named like a
code, a password, a token or a vote before the line is written (NFR-OPS-04). Request
bodies of the voting routes are never logged.

## 11. Tests and checks

| Kind | Tool | Written by | Covers |
|---|---|---|---|
| API acceptance | Pest | Before the code, from the endpoint files | One test per scenario of each endpoint; each names its requirement ID |
| Browser acceptance | Playwright | Before the code | The user's path, in French and English, at desktop and phone width |
| Unit | Pest, Vitest | With the code | Actions, components |
| Tenant isolation | Pest | Slice 03, then extended automatically | Every route |
| Identifier leak | Pest | Slice 01 | Every route |
| Static analysis | PHPStan (Larastan), TypeScript strict | | |
| Style | Pint, ESLint, Prettier | | |

`make check` runs all of it locally: the same commands as CI.

**CI** (GitHub Actions, one workflow, on every pull request): install, lint, static
analysis, API tests against a real MariaDB and Redis, build the web application,
browser tests against the running stack, regenerate the OpenAPI file and the TypeScript
types and fail if they differ from the committed ones, dependency audit. The workflow
file is created in slice 01, with the first code it can run.

## 12. Generated API description

The endpoint files in `docs/api/` are the contract and are written by hand. Besides them,
`docs/api/openapi.json` is generated from the API's code, and `web/` gets its TypeScript
types generated from that file. Neither is edited by hand.

## 13. Libraries to choose at the slice that needs them

Chosen then, against what is current and maintained at that date, and recorded here.

| Need | Slice | Constraint |
|---|---|---|
| Reading and writing spreadsheets | 08 | Streaming; no formula evaluation |
| PDF of slips, PDF report | 10, 16 | Runs in the `queue` container without a browser engine, if the layout allows |
| QR codes | 10 | Server-side, SVG output |
| Image re-encoding | 04 | WebP output, strips metadata |
| Sharing preview image | 16 | Generated by the web application |

## 14. Production, in short

Detailed in slice 20. One VPS; the same Compose services without Mailpit; the proxy gets
certificates by itself; MariaDB and Redis are not reachable from outside; a daily
database dump is copied off the server (NFR-OPS-02); the audit key is backed up
separately.
