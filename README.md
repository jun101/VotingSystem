# New Voting System

Online elections for institutions, starting with schools in Haiti. A voter opens a
personal link, enters an access code and votes; the institution sees the results only
once the election is closed. A Laravel API, a Next.js web application, MariaDB and Redis,
all in Docker Compose.

## Install it for an institution

Three installers, one per situation. The full guide (French and English, with the screens)
is in the application for the signed-in owner and platform admin, under "Guide d'utilisation",
"Installation", and in [docs/ops/installation-guide.md](docs/ops/installation-guide.md), which opens the three
step-by-step guides: [Linux (school)](docs/guides/install-school-linux.md),
[Windows (school)](docs/guides/install-school-windows.md) and
[online VPS](docs/guides/install-online-vps.md).

| Situation | Installer | Needs | Email |
|---|---|---|---|
| A Linux server in the institution, no internet | `install.sh` (school edition, native, no Docker) | Debian 12 or 13, Ubuntu 24.04 or 26.04, x86_64, 4 GB memory, 5 GB free, systemd | No: the installer creates the accounts; passwords are reset with `votectl` |
| A Windows computer in the institution | `install.ps1` (school edition, native, no Docker or WSL) | Windows 10 1809 or later, Windows 11, Windows Server 2019 or later, 64-bit, 4 GB, 5 GB free | No |
| An online server with a domain name | `install-online.sh` (online edition, Docker, automatic HTTPS) | Debian or Ubuntu with Docker (installed by the script when missing), ports 80 and 443, a domain name | Yes (SMTP) |

Steps, the same for the three:

1. Download the version's archive and its `.sha256` file from the project's releases, check
   the sum, unpack.
2. Run the installer as an administrator. `--dry-run` (Linux, online) or `-DryRun` (Windows)
   shows the checks and the plan and changes nothing. `--yes` accepts the proposed values.
3. Read the summary: site address, chosen ports (port 80 by default, else the next free
   one), files written.
4. Two files are written: the accounts (`votesystem-identifiants.txt`, temporary passwords)
   and the **audit private key** (`votesystem-cle-audit-A-EMPORTER.txt`). Copy the key to a
   USB key, then delete it from the machine.
5. Open the site address: each account changes its temporary password at first sign-in. Turn
   on two-factor authentication for the owner and the administrator.
6. Firewall: the installer offers to open the site's one port (private network) and removes
   the rule on uninstall.
7. Backups: one a night (school editions); copy the archives to an external disk. Procedure
   and rehearsal: [docs/ops/backup-restore.md](docs/ops/backup-restore.md).

Operation: `votectl status | version | logs | backup | restore | update | user list | user
reset-password | owner create | admin create | 2fa reset | mail test` (Windows: the same
command in an administrator PowerShell). To update, unpack the new version and run
`install.sh update` (`install.ps1 update`; online edition: `install-online.sh update --archive FILE`, or
`votectl update --version X.Y.Z`): backup, migrations, and an automatic return to the previous version on failure. `install.sh
uninstall` keeps the data unless `--purge-data` is given.

Tried: Debian 12 and 13, Ubuntu 24.04 and 26.04 (fresh virtual machines), Windows 11 (virtual
machine), and the online edition on a real VPS with a domain and HTTPS, behind Nginx Proxy Manager
([docs/ops/production-vps.md](docs/ops/production-vps.md)).

## Development

### What you need

Docker with the Compose plugin. Nothing else: PHP, Composer and Node run in containers.

### Commands

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

### Good to know

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
