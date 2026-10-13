# Production on one VPS: the rollout runbook (slice 20)

Nothing in this file has been run on a real server: it needs the VPS, the domain and, every time, Jun's approval to connect
(Jun's standing rule: no SSH without a described, approved set of commands). Everything it asks the server to do was
rehearsed on this machine with the production images: install, vote flow, load test, backup, disaster and restore, update
and rollback, uninstall (`docs/slices/19c-step-e.done.md`, `docs/ops/load-test.md`, `docs/ops/backup-restore.md`).

## What Jun provides

| Item | Why |
|---|---|
| The VPS: Debian 12/13 or Ubuntu 24.04/26.04, x86_64, 2 vCPU, 4 GB, 20 GB disk at least | The stack: MariaDB, Redis, Octane, Next.js, Caddy |
| The domain name, one only (example `vote.fleuridor.com`), and its `A` record pointing to the VPS | Caddy gets the HTTPS certificate by itself |
| Ports 80 and 443 reachable (the provider's firewall too) | Certificate and site |
| An SMTP account (host, port, user, password, from address) | Verification and password emails |
| An email address for the platform admin | The first platform account |
| A way in over SSH (user with sudo, key), approved in this chat each time | The installation |
| A GitHub release of the version to install, with the two image packages made **public** (once, after the first tag) | The server pulls the images by version |

## Before the server

1. Merge `staging` into `main` (CI green), tag `vX.Y.Z`. The release workflow builds the images, pushes them to
   `ghcr.io/<owner>/votesystem-api` and `votesystem-web`, and attaches the installer archive to a **draft** release.
2. On GitHub, make the two packages public (Package settings, Change visibility), then publish the draft release.
3. Check the archive: `sha256sum -c votesystem-online-X.Y.Z.tar.gz.sha256`.

## On the server (to approve before each connection)

```bash
# 1. Fetch and check
wget https://github.com/<owner>/VotingSystem/releases/download/vX.Y.Z/votesystem-online-X.Y.Z.tar.gz{,.sha256}
sha256sum -c votesystem-online-X.Y.Z.tar.gz.sha256
tar -xzf votesystem-online-X.Y.Z.tar.gz && cd votesystem-online-X.Y.Z
# 2. Look first, change nothing
sudo ./install-online.sh --dry-run --domain vote.example.org --admin-email admin@example.org
# 3. Install (asks the domain once, the SMTP settings, sends a test message)
sudo ./install-online.sh --domain vote.example.org --admin-email admin@example.org
```

It installs Docker from Docker's apt repository after a question when it is missing, checks that the domain points to the
server and that 80 and 443 are free, writes `/opt/votesystem` (`.env` mode 0600), generates every secret and the vote
audit key pair, runs the migrations with the migrator account and `db:grant-app`, creates the platform admin with a
generated password and checks that the site answers.

Two files are written in the home of the person who ran `sudo`:
`votesystem-identifiants.txt` (the admin's one-time password) and `votesystem-cle-audit-A-EMPORTER.txt` (the **private**
audit key). Copy the key to Jun's machine and a second safe place, then delete it from the server. Nothing else can read
the audit records.

## After the install: the go-live checklist

| # | Check | How | Rehearsed |
|---|---|---|---|
| 1 | The site answers over HTTPS with a valid certificate | open `https://<domain>`; `curl -sI` shows `strict-transport-security` | HTTP path only; the certificate needs the real domain |
| 2 | Security headers and a CSP with a nonce, no violation on any page | `PROD_CSP=1 BASE_URL=https://<domain> PROD_REFERER=https://<domain> npx playwright test e2e/slice20/csp-production.spec.ts --project=desktop` | yes, 0 violations on the production build, 31 page kinds |
| 3 | `SITE_URL` is the public address (sharing previews, absolute links) | `grep SITE_URL /opt/votesystem/.env`; open a published results page and read its `og:url` | wired by the installer |
| 4 | Migrations and table rights ran | `sudo votectl status`; the `migrate` service ran at install and runs at every `votectl update` | yes |
| 5 | The platform admin signs in, changes the password, turns on two-factor | the sign-in page | yes (flow) |
| 6 | Email works | `sudo votectl mail test you@example.org` | yes (log mailer; SMTP needs the real account) |
| 7 | A backup is made and copied off the server | `sudo votectl backup`; add the cron line and the copy (see `backup-restore.md`) | yes |
| 8 | The load test passes from another machine | `tools/loadtest/vote_load.py --base https://<domain> --voters 500 --seconds 60` (raise nothing: rate limits are production's) | yes locally; to repeat on the VPS |
| 9 | The audit command works from Jun's machine only | `vote-audit:read` with the private key, on Jun's machine (never on the server) | slice 19 |
| 10 | A first real election with a small group, then the real one | | |

## Updates

`sudo votectl update --version X.Y.Z`: backup, pull, migrate, restart; goes back to the previous version if the migration or
the health check fails. Do it outside a voting period.

## Rollback of a whole rollout

`sudo /opt/votesystem/installer/install-online.sh uninstall` removes the containers and keeps the data;
`--purge-data` removes the data as well (only after a backup copied elsewhere).
