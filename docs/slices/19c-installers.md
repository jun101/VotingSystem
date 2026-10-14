# Slice 19c — Installers (school mode, Linux, Windows, Docker online)

Brief · 2026-10-13 · branch `slice/19c-installers` (from `staging`). Jun's decisions of 2026-10-13 are in the tables.
Comes before slice 20 (load test, VPS rollout, go-live checks), which this slice shrinks: backup, the production Compose
file and the deploy steps are built here.

## Goal

1. A person with no developer skills can install the system on a school server with one script, without internet, and get
   a `credentials.txt` with the accounts to sign in (Linux and Windows).
2. The online edition installs with Docker and is managed with a `votectl` command (create admin, reset a password,
   create an SSH key, backup, update).
3. One product, two modes: `DEPLOYMENT_MODE=school` has no email anywhere; `online` is the system as it is today.

## Jun's decisions (2026-10-13)

| Question | Decision |
|---|---|
| Linux school installer | Bash, **native, no Docker**, self-contained bundle of pinned binaries under `/opt/votesystem` (FrankenPHP static with PHP 8.4, Node, MariaDB, Redis, Caddy); systemd units; Debian 12 and 13, Ubuntu 24.04 and 26.04; x86_64 |
| Windows school installer | **PowerShell script, no .exe**; native Windows services. A spike comes first (see below), WSL2 is the fallback |
| Online installer | Bash, Docker-based, asks for the domain and the mail settings, installs `votectl` |
| Accounts created in school mode | Platform admin **and** the school's owner, both in `credentials.txt` (mode 0600) |
| Team members in school mode | The owner creates them in the web app; a one-time password is shown once; the person must change it at first sign-in |
| Ports | The installer probes the preferred port (80, then 443 if HTTPS is on) and takes the next free one; internal services get free ports on `127.0.0.1`; the chosen ports go in the config and the final summary |
| Firewall | Detect the active firewall (ufw, firewalld, nftables or iptables, Windows Firewall), show the exact rule, ask once "open port N? [Y/n]" (`--yes` skips the question); open only the web port, local network by default; remove the rule on uninstall |
| HTTPS in school mode | **Plain HTTP** (Jun's choice). See "What plain HTTP changes" |
| Audit private key in school mode | Shown once, written to a take-away file, deleted after the person confirms it was copied; never kept on the server |

## What plain HTTP changes (written down on purpose)

- Passwords and voter codes cross the school network in clear. Anyone on that network can read them.
- The `Secure` flag is dropped from the session and the voter cookies **when the request is not HTTPS** (school mode); in
  online mode it stays. `HttpOnly` and `SameSite=Strict` stay in both.
- No HSTS in school mode. The CSP has no `upgrade-insecure-requests`.
- Admins see a notice "connexion non chiffrée" on the admin shell and the platform screen in school mode.
- An optional `--https` (self-signed by Caddy) is possible later without redesign; not built here.
- Tests that assert the `Secure` flag get a school-mode variant.

## Step A — School mode in the application

Done: see [19c-step-a.done.md](19c-step-a.done.md). Two points differ from the first draft: the account identifier stays the
email address field (any address works, no email is sent), and in `online` mode `POST /users` answers 405 as before.

`config/deployment.php`: `mode` from `DEPLOYMENT_MODE` (`online` default, `school`); an unknown value is refused at boot.

| Area | `online` | `school` |
|---|---|---|
| Sign-up of an institution | As today (email verification) | Closed: `POST /auth/register` answers 404; the institution and its owner are made by the installer or the CLI |
| Email verification | Required | None: accounts are verified when created |
| Password reset by email | As today | Closed (404); reset by the CLI, or by the owner for a team member |
| Invitation of a team member | Email with a link | **Owner creates the user** (name, email-less login identifier, role); a one-time password is returned once; `must_change_password` forces a change at first sign-in |
| Voter codes by email (slice 11) | As today | The email channel is refused (422 `channel_unavailable`) and hidden; print and file stay |
| Mail settings | `MAIL_*` | Not read; no mail is ever sent |
| Cookies | `Secure` | `Secure` only on HTTPS requests |

Endpoint files are written first (`docs/api/auth/` changes, a new `POST /users` in school mode, the credential channel
rule); acceptance tests run in both modes.

## Step B — `votectl` and the commands

Done: see [19c-step-b.done.md](19c-step-b.done.md) (`update` moves to steps D and E, which hold the bundles).

Artisan commands (the code), and a bash wrapper `votectl` that calls them (inside the container for Docker, from
`/opt/votesystem/current` for the native bundle):

| Command | Does |
|---|---|
| `admin:create {email}` | Platform admin (exists as `platform:create-admin`); `--password-from-env` or a generated password printed once |
| `owner:create` | An institution and its owner (school mode setup) |
| `user:reset-password {email}` | New one-time password, printed once; sessions of the user ended |
| `2fa:reset` | Exists as `platform:reset-two-factor` |
| `backup` / `restore` | Dump of the database and the files, with a checksum; restore refuses a dump of another version |
| `status` | Versions, ports, services, disk, last backup |
| `update` | Backup, unpack the new release, migrate, switch, roll back on failure |
| `ssh-key:create` | Creates a key pair for the operator (online edition: access for backups and deploys); the private part is shown once |
| `logs`, `version` | |

## Step C — Build and release pipeline

GitHub Actions workflow on a tag: build the web (`next build` standalone), install PHP dependencies without dev tools,
assemble the Linux bundle (pinned binaries from official releases, checksums verified), build the Docker images, run the
checks, write `SHA256SUMS`. Artifacts: `votesystem-<version>-linux-x86_64.tar.gz`, `votesystem-<version>-windows.zip`,
the Docker images (registry) and `install-online.sh`.

## Step D — Linux installer (`install.sh`)

1. Preflight: OS and version in the list, x86_64, RAM at least 4 GB, disk at least 10 GB free, systemd, root.
2. Ports: probe, choose, show the result; internal services on `127.0.0.1`.
3. Unpack to `/opt/votesystem/releases/<version>`, link `current`; own user `votesystem`, no login shell.
4. Initialise MariaDB in its own data folder and socket; create the application and migrator accounts with generated
   passwords; run migrations and `db:grant-app`.
5. Generate every secret (`APP_KEY`, hash keys, database and Redis passwords) into `/etc/votesystem/env` (0600, root).
6. Audit key pair: public key to the config; the private key shown once and written to the take-away file (see below).
7. Create the platform admin and the school's owner; write `credentials.txt` next to the take-away folder (0600).
8. systemd units: database, redis, api, web, proxy, queue worker, scheduler timer (every minute), backup timer (daily);
   start at boot; restart on failure.
9. Firewall question and rule; final summary (address, ports, files, what to do next).
10. `uninstall.sh` removes the services and, only on request, the data; always offers a final backup.

The script is idempotent: a second run on an installed system offers update, repair or uninstall.

## Audit key take-away

The installer asks for a folder (default: the home of the person running it), writes `audit-key.txt` there with a plain
warning (what it is, that losing it means the audit of this installation can never be read, that it must not stay on
the server), asks the person to copy it to a USB stick and type `copied`, then deletes the file and says so.

## Step E — Docker installer (`install-online.sh`)

Same preflight idea; installs Docker Engine if missing (the person is asked first); asks the domain (or "behind a
proxy"), the mail settings and the first admin. **One domain only** (Jun, 2026-10-13: the example is
`vote.fleuridor.com`): the website calls `/api` on its own host (`web/src/lib/api/client.ts`), so no API name is asked and
no CORS or cookie change is needed. A separate API name stays a later, optional second site address of the same Caddy file; pulls the pinned images; generates secrets; starts `compose.prod.yaml`
(no Mailpit, nothing but the proxy port published); installs `votectl`; prints the summary. The same port and firewall
rules as step D.

## Step F — Windows (`install.ps1`)

A PowerShell script (run as administrator, with a one-line `-ExecutionPolicy Bypass` instruction in the guide). **First
the spike** (`installers/windows/SPIKE.md` and `spike.ps1`): proves on a real Windows PC that Octane with RoadRunner
(`pcntl` does not exist on Windows), a Redis-compatible store (candidate: Garnet) and MariaDB run the application and
pass a smoke flow. If it fails, F becomes "WSL2 plus the Linux installer inside it". If it passes, F follows the steps
of D with Windows services (WinSW), the Windows Firewall, `%ProgramData%\VoteSystem`, the same files and warnings.

## Not in this slice

The load test, the VPS rollout and the domain (slice 20); an HTTPS option for school mode; arm64 bundles; automatic
firewall changes without the question; a graphical installer.

## Tests

- Server (step A, B): Pest acceptance tests first, both modes; a test that school mode sends no email at all.
- Scripts: `bats` tests for port probing, firewall detection (with fake tools), idempotence, uninstall that keeps data.
- CI matrix: the Linux script in four container images with systemd for Debian 12 and 13, Ubuntu 24.04 and 26.04.
- Windows: the spike by Jun, then a run on a real machine.

## Open points

- Windows spike result (decides F).
- Size and hosting of the bundles (about 400 MB each) and of the Docker images.
- Languages of the installer prompts: French first, English second (proposed).
