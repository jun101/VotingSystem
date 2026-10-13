# Backup and restore: the written procedure (NFR-OPS-02)

What a backup holds: the whole database (a SQL dump, checked) and the re-encoded pictures, in one `.tar` with a `.sha256`
next to it. It never holds the audit private key, the `.env` or the `/etc/votesystem` files unless `--with-config` is
given, in which case the file must be kept as a secret.

## Make a backup

| Edition | Command | Where |
|---|---|---|
| Online (Docker) and school (Linux) | `sudo votectl backup [--out DIR]` | `/var/backups/votesystem` (online: the folder of the installation) |
| School (Windows) | `votectl backup [--out DIR]` in a PowerShell opened as administrator | `C:\VoteSystemData\backups` (zip) |

A nightly backup runs by itself on the school editions (Linux: systemd timer; Windows: scheduled task at 02:30, the last 14
kept). On the online edition, add a cron line: `0 3 * * * /usr/local/bin/votectl backup` and copy the archives off the
server (another machine or object storage): a backup that stays on the same disk is not a backup.

## Restore

1. Copy the `.tar` and its `.sha256` next to each other on the machine.
2. `sudo votectl restore /path/votesystem-backup-YYYYMMDDTHHMMSSZ.tar`
   It checks the checksum, stops the application, loads the database, puts the pictures back, starts the application and
   runs the migrations. It refuses a backup of another version unless `--force`: update (or install the same version) first.
3. The migrations and the table rights run by themselves at the end of the restore (online: the one-off `migrate`
   service, with the migrator account). The application account's rights are kept by the database server across a
   restore: they are not part of the dump and a dropped table does not take them away.
4. Check: `votectl status`, then sign in and open the election list. `GET /api/v1/health` answers 200.

On a machine with nothing installed: install the same version first (`install-online.sh` / `install.sh`), then restore.
The installer creates a new audit key pair; votes already recorded were sealed with the old public key, so **keep the old
private key**: it is the only way to read the old audit records.

## Rehearsal (2026-10-13, production images, the online edition)

| Step | Result |
|---|---|
| State before | votes 2 302, vote_choices 2 302, vote_audit 2 302, voters 2 820, elections 7, participations 2 302, users 11, institutions 10 |
| `votectl backup` | one `.tar` of 770 KB and its checksum |
| Disaster | services stopped, `DROP DATABASE votesystem`, empty database created |
| `votectl restore` | checksum verified, database loaded, services started, "Nothing to migrate" |
| State after | the same counts, row for row; health 200 |

The rehearsal found two faults, both fixed: on the Docker driver the pictures were restored with `exec` in a container that
the restore had just stopped, and then `tar` failed on the mount point's own permissions; so they were never put back
("the pictures could not be restored"). `votectl` now uses a one-off `run` and skips the folder entry. The migrations
after a restore also run through the one-off `migrate` service (the migrator account), not the application's account.

## What is not in a backup

The generated slip files and distribution files (they are deleted when an election closes), the Redis data (sessions,
queue: lost sessions mean people sign in again), and the Caddy certificates (obtained again automatically).
