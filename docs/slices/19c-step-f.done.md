# Slice 19c, step F: the Windows installer (done)

**Spike (2026-10-13):** native Windows works, no WSL2 and no Docker: see `installers/windows/SPIKE.md`. Differences from the
plan: the API runs as php-cgi FastCGI workers behind Caddy (no Octane, no RoadRunner); Redis is the Windows port of Redis 5.

**Built**
- `installers/windows/install.ps1` (install, update with backup and automatic return, uninstall), `votectl.ps1`,
  `lib/common.ps1`, `templates/` (Caddyfile, mariadb.ini, redis.conf).
- `installers/bundle/build-windows.sh` and `sources-windows.lock` (every file pinned by checksum); the release workflow
  builds the zip next to the Linux bundle.
- Services are scheduled tasks as SYSTEM, started with the computer, restarted when they stop; the API storage and cache are
  junctions into the data folder; `current` is a junction to the active release.

**Tried in a Windows 11 VM (5 GB, 4 CPUs)**: install in 71 s; the vote flow through the API; scheduler opens the election;
a replayed submit answers 200 and counts one vote; update in 64 s; the same version refused; a broken migration rolls back;
backup and restore; uninstall keeping the data; reboot brings every service back.

**Fixed on the way:** typographic apostrophes are quote characters in PowerShell 5.1 (the files are UTF-8 with BOM and
use plain apostrophes); a native program's error output must not become a PowerShell error (`Invoke-Native`); localised
group names (icacls by SID); `php.ini` next to `php.exe` for the commands the scheduler starts by itself; a one-element
array assigned from an `if` expression is a scalar.

**Open:** only x64; Redis 5 is unmaintained upstream (Garnet is the candidate); the release workflow has never run on
GitHub; the manual's Windows section was written from this run.
