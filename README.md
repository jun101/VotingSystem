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
| `make setup` | Creates `.env` and generates the secrets that are missing (never replaces a value already set), builds the images, installs the dependencies, runs the migrations and gives the `app` database account its rights table by table |
| `make restart-api` | Reloads the API workers after editing PHP code (Octane keeps the code in memory) |
| `make up` | Starts the whole system on http://localhost:8080 |
| `make down` | Stops it |
| `make test` | API tests (every suite) and web unit tests. The API tests run in the one-off `test` service, which also holds the `migrator` account for the test of the table rights; run one file with `docker compose run --rm test vendor/bin/pest <file>` |
| `make e2e` | Browser tests against the running stack |
| `make lint` | Pint, PHPStan, ESLint, Prettier, `tsc` |
| `make generate` | Regenerates `docs/api/openapi.json` and `web/src/lib/api/schema.d.ts` |
| `make generate-check` | Regenerates both and fails if either differs from what is committed |
| `make build` | Builds the production web image |
| `make audit` | Checks every dependency, development tools included, for known vulnerabilities. An advisory with no fix yet is accepted, with a reason and a review date, in `web/audit-accepted.json`; a date in the past fails the check |
| `make check` | Everything CI runs |

Start with `make setup`, then `make up`, and open http://localhost:8080: the home page
shows the state of the API, the database and Redis. Emails sent in development are caught
by Mailpit at http://localhost:8025.

The port can be changed with `HTTP_PORT` in `.env` (8080 by default).

## Good to know

- **Development and production values.** `.env.example` sets the development values
  (`APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`). If a variable is missing from
  `.env`, `compose.yaml` falls back to production values, never to development ones.
- **Logs.** `docker compose logs api` shows one JSON line per log record, with the
  `request_id` that an error answer gives as `reference`. The API writes them to standard
  error because Octane throws away what a worker writes to standard output.
- **A database volume that already exists keeps its accounts and passwords.** If you change
  `DB_DATABASE`, `DB_PASSWORD` or `DB_MIGRATOR_PASSWORD` in `.env` after the first start,
  the database no longer opens with the new values. Remove this project's volumes with
  `docker compose down -v` (the development database and Redis data are lost) and run
  `make setup` again.
- **Database accounts.** The serving containers (`api`, `queue`, `scheduler`) hold the
  `app` account only. The `migrator` password is given to the one-off `migrate`,
  `migrate-test` and `test` services. `app` reads every table and writes only where
  `php artisan db:grant-app` gives it the right; the tables that only receive new rows
  (`database.append_only_tables` in `api/config/database.php`) get `INSERT` and nothing
  else. Run `make setup` (or `docker compose run --rm migrate`) after adding a migration:
  it runs the migrations, then the grants.
- **A second clone on the same machine.** `make setup` writes the private key of the vote
  audit (development) to `~/.config/new-voting-system/vote-audit-dev.private`, outside the
  repository, and refuses a path inside it. A second clone finds that file but has no public
  key in its `.env`, and stops. Copy the `VOTE_AUDIT_PUBLIC_KEY` line from the first clone's
  `.env`, or set `VOTE_AUDIT_PRIVATE_KEY_FILE` to another path for the second clone.
- **Browser tests** run in a container on the Compose network and reach the proxy as
  `http://proxy:8080`; they see no other service of the machine.

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
