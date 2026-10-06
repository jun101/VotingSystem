# Slice 01 — Walking skeleton

Brief · 2026-10-06 · branch `slice/01-walking-skeleton`

## Goal

One command starts the whole system, and one page proves that every part talks to the
next: browser → proxy → web → API → MariaDB and Redis. Everything later slices rely on
is in place: the error shape, the request id, the tests, the checks, CI, the generated
API description, the design tokens and the first base components.

At the end, a person runs `make up`, opens `http://localhost:8080` and sees "New Voting
System" with a status card showing the API, the database and Redis online, and the time
read from the database.

## Requirements covered

NFR-OPS-01 (one command), NFR-SEC-07 (secrets from the environment), NFR-SEC-08 (no
internal id leaves the server: the guards), NFR-OPS-04 (structured logs, request id),
NFR-UX-01 (French and English, first mechanism), NFR-UX-02 and NFR-UX-03 (tokens, base
components, accessibility checks).

## Read before coding

[architecture.md](../design/architecture.md) sections 2 to 4, 9 to 12 ·
[frontend.md](../design/frontend.md) sections 3, 4 and 6 ·
[API conventions](../api/README.md) ·
[GET /health](../api/system/GET-health.md)

## What to build

### 1. Stack

| Item | Requirement |
|---|---|
| `compose.yaml` | Services `proxy`, `api`, `queue`, `scheduler`, `web`, `db`, `redis`, `mailpit`, as in architecture.md section 2. Health checks on `db`, `redis`, `api`, `web`; services wait for the ones they need |
| Proxy | Listens on `http://localhost:8080` in development. `/api/*` → `api`; everything else → `web`. Sets `X-Content-Type-Options: nosniff` and a `Referrer-Policy`; removes any header naming software versions |
| Images | Built from `docker/`. Every base image pinned to an exact version. Current stable releases, checked on each project's own site at coding time |
| Database | Two accounts created at first start: `app` (used by `api`, `queue`, `scheduler`) and `migrator` (migrations). Neither is `root`. A separate database for the tests |
| Only the proxy and Mailpit's web page publish a port | `db` and `redis` are reachable only inside the Compose network |
| `.env.example` | Every variable the stack needs, with safe development defaults where a default is harmless and an empty value for every secret. `make setup` creates `.env` from it and generates the secrets that are missing, without replacing existing values |

### 2. API (`api/`)

| Item | Requirement |
|---|---|
| Framework | Laravel, current stable, PHP 8.4. Only what is needed: no front-end scaffolding, no token authentication package yet |
| Routes | Prefix `/api/v1`. One endpoint: [GET /health](../api/system/GET-health.md) |
| Error rendering | Every answer under `/api` is JSON in the shape of the API conventions, for every status in its table, whatever the debug setting and the `Accept` header. `reference` on 500 and 503 |
| Request id | A global middleware (it also covers routes outside the `api` group) sets a random version 4 UUID in `X-Request-Id` on every answer, ignores a value sent by the client, and adds it to every log line of the request |
| Language | `Accept-Language` `fr` or `en` selects the language of `error.message`; `fr` by default |
| Logs | JSON on standard output, with the request id. A processor that removes fields named like a secret (password, code, token, key, vote) |
| A service being down | A failure to reach MariaDB or Redis anywhere in the request, including in a middleware, is answered with 503 `dependency_unavailable`, never 500 |
| Rate limit | 60 per minute per IP address on `/health`; the limiter is declared by name so later slices add theirs the same way |
| Base classes for later slices | A base API resource that never outputs `id` or `*_id` and outputs the record's `uuid` as `id`; a model trait that gives a model a random version 4 `uuid` on creation and makes it the route key. No model uses them yet; each has unit tests |
| Tests | `api/tests/Acceptance` registered as a test suite next to `Feature` and `Unit`. The test configuration uses the real MariaDB test database and the real Redis |
| Static analysis and style | PHPStan through Larastan at the highest level that is practical from day one, and Pint, both with no error |

### 3. Generated API description

| Item | Requirement |
|---|---|
| `docs/api/openapi.json` | Generated from the API's code by a maintained tool, committed |
| `web/src/lib/api/schema.d.ts` | Generated from `openapi.json`, committed |
| `make generate` | Regenerates both |
| `make generate-check` | Regenerates both and fails if either differs from the committed file |

### 4. Web (`web/`)

| Item | Requirement |
|---|---|
| Framework | Next.js (App Router), current stable, TypeScript strict, Tailwind CSS. Standalone build for the image. The `X-Powered-By` header is off |
| Structure | The folders of frontend.md section 3; route groups created empty where nothing is built yet are not needed |
| Tokens | Every colour, radius and type step of frontend.md section 4, defined once as CSS variables on `:root` and exposed to Tailwind. Colours are named `--color-<token>` (`--color-primary`, `--color-ink-soft`, …) |
| Fonts | Bricolage Grotesque (600, 700) and Public Sans (400, 500, 600, 700), served from our own origin, Latin subset. The page makes no request to another origin |
| Language | Message files `fr.json` and `en.json`; no text in a component. The language is the `locale` cookie if it holds `fr` or `en`, else the browser's `Accept-Language`, else `fr`. `<html lang>` follows it |
| API client | A small typed client in `lib/api` using the generated types; on the server it calls the API through the internal network, in the browser through the same origin |
| Home page `/` | Rendered on the server. `h1` "New Voting System". A card titled "État du système" / "System status" (an `h2`) with four rows, described below |
| `/dev/components` | Shows every base component in every variant and state. Answers 404 when the application runs in production mode |
| Not found | An unknown address answers status 404 with a page in the current language |
| Base components | `Button`, `Input`, `Card`, `Pill`, `PageShell` in `components/ui`, as specified below, each with unit tests |
| Static analysis and style | `tsc` strict, ESLint and Prettier with no error |

**Status card of the home page**

| Row | `data-testid` | Label fr / en | Content |
|---|---|---|---|
| API | `status-api` | API / API | A pill: "En ligne" / "Online", or "Hors ligne" / "Offline" |
| Database | `status-database` | Base de données / Database | Same pill |
| Redis | `status-redis` | Redis / Redis | Same pill |
| Database time | `status-time` | Heure de la base de données / Database time | A `<time>` element; `datetime` is the `time` value of the health answer, unchanged; the text shows it in the visitor's language |

Each of the first three rows carries `data-state="ok"` or `data-state="down"`. When the
API cannot be reached, the page still renders, with the three rows down and no time.

**Base components**

| Component | Requirement |
|---|---|
| `Button` | A real `button`. Variants `primary` (background `primary`, white text), `secondary` (white background, `line-strong` border, `ink` text), `danger` (white background, `danger-line` border, `danger` text), `quiet` (no background, `primary` text). Sizes `admin` (44 px high at least) and `voter` (48 px at least). Radius 10 px. States: hover, keyboard focus (the selection ring of frontend.md), disabled, loading (`aria-busy="true"`, disabled, keeps its width) |
| `Input` | A label always present and tied to the field; optional help text; an error message that sets `aria-invalid="true"` and is tied by `aria-describedby`. 44 px high at least, radius 10 px, `line-strong` border. Text 16 px under the `md` breakpoint, 14 px from it |
| `Card` | `surface` background, 1 px `line` border, radius 16 px, no shadow. Optional title and actions |
| `Pill` | Fully rounded, always holds text. Tones `neutral`, `primary`, `teal`, `warm`, `danger`: a soft background with the matching ink colour (`teal`: `teal-soft` and `teal-ink`) |
| `PageShell` | The outer layout: `canvas` background, a centred column, the product name. Three variants are named now (`voter`, `public`, `admin`); only a plain one is needed in this slice |

**`data-testid` values on `/dev/components`**

`demo-button-primary`, `demo-button-secondary`, `demo-button-danger`,
`demo-button-quiet`, `demo-button-voter` (primary, voter size), `demo-button-disabled`,
`demo-button-loading`, `demo-input`, `demo-input-error`, `demo-card`, `demo-pill-neutral`,
`demo-pill-primary`, `demo-pill-teal`, `demo-pill-warm`, `demo-pill-danger`.

### 5. Commands

A `Makefile` at the root. Every target runs inside the containers, so only Docker is
needed on the machine.

| Target | Does |
|---|---|
| `make setup` | Creates `.env`, generates missing secrets, builds the images, installs dependencies, runs the migrations |
| `make up` / `make down` | Starts and stops the stack |
| `make test` | API tests (all suites) and web unit tests |
| `make e2e` | Browser tests against the running stack |
| `make lint` | Pint, PHPStan, ESLint, Prettier, `tsc` |
| `make generate`, `make generate-check` | Section 3 |
| `make check` | `lint`, `test`, `generate-check`, `e2e`: everything CI runs |

### 6. CI

`.github/workflows/ci.yml`, on every pull request and on pushes to `main`: the jobs of
architecture.md section 11, running the same `make` targets. Actions pinned to a commit.
The workflow has read-only permissions.

### 7. Read-me

A `README.md` at the root: what the project is in three lines, what is needed (Docker),
the commands above, where the documents are.

## Acceptance tests

Written before the code. **They are not edited during coding.** If one seems wrong, stop
and say why.

| File | Checks |
|---|---|
| `api/tests/Acceptance/Slice01/HealthTest.php` | The five scenarios of the health endpoint, and the language of messages |
| `api/tests/Acceptance/Slice01/ApiConventionsTest.php` | Request id, 404 and 500 shapes, JSON whatever the `Accept` header |
| `api/tests/Acceptance/Slice01/IdLeakScannerTest.php` | The scanner that guards every response |
| `api/tests/Acceptance/Architecture/RouteKeysTest.php` | Every model in a route is bound by `uuid` |
| `web/e2e/slice01/home.spec.ts` | The home page: content, time from the database, languages, own-origin only, fonts, tokens, width, accessibility |
| `web/e2e/slice01/components.spec.ts` | The base components on `/dev/components` |
| `web/e2e/slice01/api.spec.ts` | The API through the proxy; headers |

The harness is also fixed: `api/tests/TestCase.php`, `api/tests/Pest.php`,
`api/tests/Support/`, `web/playwright.config.ts`, `web/e2e/support/`. The framework's
installer must not replace them: install elsewhere and move the files in, or restore
them from git afterwards.

Browser tests run on two projects, `desktop` (1280 px) and `phone` (320 px).

## Out of scope

- Any table of database.md, any model, any sign-in (slices 02 and after). The only
  migrations are the framework's own that this slice needs.
- The language switch in the interface (slice 03). Only the cookie and browser-language
  mechanism is built here.
- The Content-Security-Policy and HSTS (slice 19); the production Compose file
  (slice 20).
- The other components of frontend.md section 6.

## Done when

1. On a machine with only Docker: `make setup`, `make up`, then `make check` passes
   entirely.
2. Every acceptance test passes, unedited, on both browser projects.
3. `http://localhost:8080` shows the home page as described, in French and in English.
4. Stopping the `db` service makes `/api/v1/health` answer 503 within three seconds and
   the home page show the rows down; starting it again brings them back.
5. `git grep` finds no secret value in the repository; `.env` is not committed.
6. The CI workflow passes on the pull request.
7. No reviewer finding is left open.
