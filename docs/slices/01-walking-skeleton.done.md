# Slice 01 — Walking skeleton: what was done

2026-10-06 · branch `slice/01-walking-skeleton`

## What you can do now

1. `make setup` once, then `make up`.
2. Open http://localhost:8080: "New Voting System" and a status card showing the API,
   the database and Redis online, with the time read from the database. It follows your
   browser's language (French or English).
3. Open http://localhost:8080/dev/components: the five base components in every variant.
4. Stop the database (`docker compose stop db`), reload: the rows show "offline" and
   `/api/v1/health` answers 503. Start it again: everything comes back.
5. `make check` runs everything CI runs.

## What was built

| Part | Content |
|---|---|
| Stack | Eight services in Docker Compose: proxy, API, queue, scheduler, web, MariaDB, Redis, Mailpit. Only the proxy (8080) and Mailpit's page (8025) are reachable, from this computer only |
| API | Laravel on Octane with FrankenPHP. One endpoint, `GET /api/v1/health`. The shared error shape for every status of the conventions; a request id on every answer and in every log line; logs in JSON without secrets; a service being down answers 503 |
| Database accounts | `app` for the application, `migrator` for the schema. Rights are granted table by table after each migration; the vote and audit tables will receive "insert only" |
| Web | Next.js with Tailwind. Design tokens, the two fonts served from our own address, French and English messages, the home page, the components page, five base components |
| Guards for later slices | Every API test response is scanned for internal ids; every route must bind records by `uuid`; a base API resource and a model trait that hide internal ids |
| Generated | `docs/api/openapi.json` from the API's code and the web's TypeScript types from it; a check fails when they are out of date |
| Tooling | Makefile, CI workflow, read-me, audit of every dependency with a dated list of accepted advisories |

## Changed along the way

- The API runs under Octane (decided during the slice). It brought one rule: nothing
  that belongs to a request may stay in memory for the next.
- The vote audit uses a key pair. `make setup` creates a development pair and writes
  the private key outside the repository.
- Four acceptance tests were wrong when first run against real code and were corrected:
  the default language, the comparison of a colour token, and the malformed-host test
  twice. Their intent did not change.

## Review

Two reviews, 26 findings: 2 blocking, 10 to fix, 14 notes. 25 are closed; container
hardening waits for slice 20. Details in
[01-walking-skeleton.review.md](01-walking-skeleton.review.md).

## Checked before the checkpoint

| Check | Result |
|---|---|
| API tests | 91 pass |
| Web unit tests | 55 pass |
| Browser tests, desktop and phone | 55 pass, 1 skipped by design |
| Lint and static analysis | clean |
| Generated files | up to date |
| Production build of the web image | builds |
| Dependency audit | clean; one accepted advisory in a development tool, to review by 2026-11-06 |
| Blocking findings on the running site | `/api/` answers JSON; no cross-origin header; `OPTIONS` answers 405; an error's reference is found in the logs |

## Known limits

- The CI workflow runs for the first time on this pull request.
- `make setup` was not run on a fresh clone of the repository.
- The API tests use the in-memory cache; the rate limiter on Redis has its own test.
- After changing API code, run `make restart-api`: the application stays in memory.
- A kept database volume with a new `.env` cannot start: recreate it with
  `docker compose down -v` (development data only).

## Next

Slice 01b, motion and colours: the showcase theme, the animations and the composition
rules of the front-end design, shown on a demo page.
