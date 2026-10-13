# Slice 19c, step C (the Linux bundle and its release pipeline): done

Branch `slice/19c-installers`. Brief: [19c-installers.md](19c-installers.md). Files: `installers/bundle/`.

## Built

- **`sources.lock`**: the upstream files, each with the publisher's hash list it was checked against and its pinned sha256.
  FrankenPHP 1.13.0 (`-mimalloc`, PHP 8.5.11 with Caddy inside), a static PHP 8.5.8 CLI (`bulk`), Node 24.21.0, MariaDB
  12.3.3 (bintar), Redis 8.10.2 (source). The static PHP CLI publishes no hash list: it is pinned by its own checksum.
- **`fetch.sh`**: downloads, checks each file against the publisher (GitHub release digest, `SHASUMS256.txt`, the MariaDB
  `sha256sums.txt`, the `redis-hashes` list) and against the pin; `--pin` writes a pin once, after the publisher check.
- **`build.sh`**: assembles `votesystem-<version>-linux-x86_64.tar.gz` with a `SHA256SUMS` of every file and one for the
  archive: `bin/` (frankenphp, php, votectl), `node/`, `mariadb/` (trimmed and stripped: 1.5 GB to under 400 MB), `redis/`
  (core server compiled in `debian:bookworm`, glibc 2.36, so it runs on Debian 12 and 13 and Ubuntu 24.04 and 26.04),
  `api/` (Composer `--no-dev`, no tests), `web/` (the project's standalone build), `installers/`, `config/`.
- **`.github/workflows/release.yml`**: on a `v*` tag, builds the bundle and attaches it, with its checksum, to a DRAFT
  release; nothing is published by itself.

## Findings

- The static FrankenPHP bundles **PHP 8.5**, not 8.4, and its `php-cli` needs PHP 8.6: the bundle therefore carries a
  separate static PHP 8.5.8 CLI. Jun chose to run PHP 8.5 (2026-10-13).
- The whole API suite passes on that exact PHP 8.5.8 binary (run in the test container with `--entrypoint`, 25 folders,
  about 2 400 tests, no deprecation or fatal line). `composer.json` allows `^8.4`; the Docker image stays on 8.4.
- Redis 8 also builds search, JSON and time-series modules, which need Rust and CMake: only the core (`src/`) is built.

## Checked in the Debian 13 VM (unprivileged user)

Archive and inner checksums; FrankenPHP serves PHP 8.5; Laravel boots (`artisan --version`); Redis answers; MariaDB
initialises, starts on a socket and answers; the web `server.js` answers 200; no missing shared library; every extension the
application needs is present.

## Not done

- Debian 12, Ubuntu 24.04 and 26.04 were not tried (Debian 13 only): the Redis build and the MariaDB bintar target older
  glibc on purpose, and the static PHP binaries need none. A run on each is part of step G.
- The release workflow has not run on GitHub.
- aarch64 bundles are out of scope.
