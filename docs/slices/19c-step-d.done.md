# Slice 19c, step D (the Linux installer): done

Branch `slice/19c-installers`. Brief: [19c-installers.md](19c-installers.md). Files: `installers/linux/`.

## Built

`installers/linux/install.sh`, run from the unpacked bundle with `sudo`:

- **`install`** (default): checks (OS, x86_64, 4 GB, 10 GB, systemd, root), the web port (80, else the next free one) and five
  internal ports on 127.0.0.1, the firewall question, a plan; then the system user `votesystem`, the release under
  `/opt/votesystem/releases/<version>` (`current` links to it), secrets, `/etc/votesystem/{env,mariadb.cnf,redis.conf,Caddyfile,votectl.conf}`,
  the database (private first start, root password set, `app` and `migrator` accounts), migrations and `db:grant-app`,
  the owner and the platform admin (one-time passwords in a 0600 file), seven systemd services and a daily backup timer
  (14 days kept), the firewall rule, and the audit key take-away. `votectl` is linked in `/usr/local/bin`.
- **`update`**: backup, copy the new release, migrate, switch the link, restart, check the health; on any failure it goes
  back to the old release, removes the new folder, keeps the migration output in `/var/lib/votesystem/last-update.log` and
  says whether the old version answers again. A migration already run is not undone (the backup is the way back).
- **`uninstall [--purge-data]`**: stops and removes the services, the firewall rule and the links; data, configuration and
  backups stay unless `--purge-data` (which makes a final backup in `/root` first and removes the service user).
- Options: `--yes`, `--dry-run`, `--web-port`, `--school`, `--owner-email`, `--admin-email`, `--credentials-file`, `--lang`.
- The audit private key is written once to a 0600 take-away file in the person's home with a plain warning (FR and EN),
  asked to be copied then deleted; it is in no configuration of the server.

## Checked in a fresh Debian 13 VM

Install (about a minute), every service active, health 200 through the entry point, the registration page 404 (school mode),
sign-in with the generated one-time password, `password_change_required` on every other route until the password is changed,
change-password 204, then `elections` 200; `votectl version | status | user list | backup`; reboot (all services back on
their own); update 0.1.0 to 0.1.1 (health 200); failed update (a migration that throws) rolled back to 0.1.1 with health
200 in 7 seconds; the same version refused; uninstall keeps the data and removes the unit files and the firewall rule; a
reinstall over leftover data is refused with the command to start clean; `--purge-data` leaves nothing.
`bats`: 17 installer tests and 13 `votectl` tests pass.

## Findings on the way

- Octane finds FrankenPHP through the `PATH`: the units put the bundle's `bin` first.
- The commands run as the service user, so credentials are written to a file that user owns, then moved into place.
- A backup run by root is owned by root: the daily one runs as the service user, which can still prune them.
- The test VM disk first lived in `/tmp`, which is RAM-backed on Jun's machine (7.7 GB): it now lives in `/home/jun/OS`.

## Not done

- Debian 12, Ubuntu 24.04 and 26.04 were not run (Debian 13 only); step G does them.
- The browser spec of school mode (needs a stack started in school mode and a seeded owner).
- The installer has no HTTPS option (plain HTTP is Jun's decision for school mode).
- Moving existing data into a fresh installation (reinstall keeping the data) is refused, not handled.
