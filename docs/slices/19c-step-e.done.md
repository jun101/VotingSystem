# Slice 19c, step E: the Docker online installer (summary)

Branch `slice/19c-step-e`. Decisions of 2026-10-13: images published to the GitHub registry and pulled by version; a
release archive with a checksum (no `curl | bash`); Caddy HTTPS or "behind my proxy"; SMTP asked and tested; platform admin
created with a generated password; one domain only.

## Built

- `docker/api/Dockerfile`: a `prod` target (code and packages, no development ones, user `www-data`); the default target is
  still `dev`, the last stage, so the development stack is unchanged.
- `compose.prod.yaml`: db, redis, api (Octane), queue, scheduler, web, proxy (Caddy, ports 80 and 443 or 127.0.0.1 behind
  another proxy), one-off `migrate`; images pinned by `VOTE_VERSION`; no Mailpit; volumes `db_data`, `redis_data`, `storage`,
  `media`, `caddy_data`, `caddy_config`. `docker/db/init-prod.sh`: no test database.
- `installers/online/install-online.sh` (+ `lib/online.sh`): checks, Docker (installed from Docker's apt repository after a
  question), the domain and its DNS, ports 80 and 443 (or `--behind-proxy` on a free local port), the firewall question,
  SMTP and a test message, secrets, audit key pair (private half in a take-away file), migrations, platform admin, health
  check; `update` (archive or `--version`, checksum, backup, pull, migrate, rollback on failure) and `uninstall [--purge-data]`.
- `installers/online/build.sh` (the archive and its `.sha256`); release workflow: job `online` pushes the images and uploads
  the archive to the draft release.
- `php artisan mail:test` (+ `docs/api/system/COMMAND-mail-test.md`, 3 acceptance tests) and `votectl mail test`,
  `votectl update` (online edition).
- `installers/online/README.md`.

## Checked

- Prod images built locally; the stack ran from them (`up --wait`, health 200, CSP and HSTS headers).
- Full install with `--behind-proxy --yes` in a test folder: admin created, `votectl status`, `user list`, `backup` work;
  `update` to a second version works; an update that cannot pull its images goes back and the old version keeps answering;
  `uninstall` keeps the volumes, `--purge-data` removes them.
- `installers/online/tests/run.sh`: 50 checks (validation, `.env`, DNS, dry run, refusals, archive). API slice 19c: 52 tests.

## Not checked, to do

- The release workflow `online` job never ran on GitHub; the two packages must be made public after the first push.
- Docker's apt repository step was not run (Docker was present); Ubuntu 26.04's codename may not be in Docker's repository yet.
- The HTTPS path (certificate from a real domain) was not run: it needs a public DNS name and open ports 80 and 443.
- Only x86_64 images.
