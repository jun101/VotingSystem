# New Voting System

Online elections for institutions, starting with schools in Haiti. A voter opens a
personal link, enters an access code and votes; the institution sees the results only
once the election is closed. A Laravel API, a Next.js web application, MariaDB and Redis,
all in Docker Compose.

## What you need

Docker with the Compose plugin. Nothing else: PHP, Composer and Node run in containers.

## Commands

| Command | Does |
|---|---|
| `make setup` | Creates `.env` and generates the secrets that are missing (never replaces a value already set), builds the images, installs the dependencies, runs the migrations |
| `make restart-api` | Reloads the API workers after editing PHP code (Octane keeps the code in memory) |
| `make up` | Starts the whole system on http://localhost:8080 |
| `make down` | Stops it |
| `make test` | API tests (every suite) and web unit tests |
| `make e2e` | Browser tests against the running stack |
| `make lint` | Pint, PHPStan, ESLint, Prettier, `tsc` |
| `make generate` | Regenerates `docs/api/openapi.json` and `web/src/lib/api/schema.d.ts` |
| `make generate-check` | Regenerates both and fails if either differs from what is committed |
| `make build` | Builds the production web image |
| `make audit` | Checks the dependencies for known vulnerabilities |
| `make check` | Everything CI runs |

Start with `make setup`, then `make up`, and open http://localhost:8080: the home page
shows the state of the API, the database and Redis. Emails sent in development are caught
by Mailpit at http://localhost:8025.

The port can be changed with `HTTP_PORT` in `.env` (8080 by default).

## Where things are

| Folder | Holds |
|---|---|
| `api/` | The Laravel API |
| `web/` | The Next.js application and its browser tests (`web/e2e`) |
| `docker/` | Dockerfiles, proxy and database set-up |
| `docs/design/` | Architecture, database, front end, mockups |
| `docs/api/` | API conventions, one file per endpoint, and the generated `openapi.json` |
| `docs/slices/` | One brief per slice of the work |
| `docs/SPEC.md` | The requirements |
