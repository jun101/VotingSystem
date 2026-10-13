# Slice 19c, step B (`votectl` and the account commands): done

Branch `slice/19c-installers`. Brief: [19c-installers.md](19c-installers.md). Command file:
[COMMAND-school-accounts.md](../api/system/COMMAND-school-accounts.md).

## Built

- **Artisan commands** (`api/app/Console/Commands`):
  - `owner:create {email} --institution=NAME [--name] [--language] [--institution-uuid] [--credentials-file]`: an
    institution and its first owner, or another owner for an existing institution; one-time password printed once or
    appended to a 0600 file; the owner must change it at the first sign-in.
  - `user:reset-password {email} [--credentials-file]`: a new one-time password for a live user (owner, manager or
    platform admin); sessions end by themselves; the same line for an unknown or removed address.
  - `user:list [--institution=UUID]`: name, address, role, institution, two-factor, last sign-in; no id, no uuid of a user.
- **`votectl`** (`installers/common/votectl`, one bash file): a driver for Docker and a driver for the native bundle,
  read from `/etc/votesystem/votectl.conf`. Commands: `status`, `version`, `logs`, `admin create`, `owner create`,
  `user reset-password`, `user list`, `2fa reset`, `backup [--out] [--with-config]`, `restore FILE [--force]`,
  `ssh-key create [--out] [--comment]`.
  - `backup`: a private (0600) tar with the database dump, the pictures, a `MANIFEST` (version, date, driver) and a
    `.sha256` next to it; `--with-config` adds the environment file and says to keep it private.
  - `restore`: checks the checksum, refuses another version without `--force`, stops the services, restores the dump
    and the pictures, starts the services, runs the migrations.
  - `ssh-key create`: an ed25519 pair, the private part 0600, never overwritten, the public part and the fingerprint shown.
- **Web**: the platform screen also shows the forced-change screen for a platform admin whose password was reset.
- **Fixes found on the way**: `POST /auth/change-password` now sits behind `institution.active` (a suspended
  institution's user is refused, 403) and the tenant lists name the commands' scope removals with their reason.

## Results

API: 49 school-mode tests (35 new in this step: 14 for the commands, 1 for the suspended refusal), Feature 200, Unit 130,
Slice03 61, lint clean (Pint, PHPStan). Web: 636 unit tests, lint (the old warning), `tsc`, Prettier. `votectl`: 13 `bats`
tests passing on Debian 13 in the test VM, with fake `docker`, `php`, database and `systemctl`.

## Notes

- `votectl update` is part of the installers (steps D and E): it needs the release bundles.
- The browser spec for school mode (accounts made with `owner:create`) is written with step D, when the installer starts
  a stack in school mode.
- A backup does not hold the generated slip files (they are private, transient and purged) nor the audit private key.
