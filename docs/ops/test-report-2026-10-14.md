# Full re-test before rc.4, 2026-10-14

Branch `release/rc4-hardening` (from `staging`). Run on one machine, capped at 4 cores and 12 GB: the production-like
stack in Docker (compose.prod.yaml, images built from the branch, production mode, Caddy with a self-signed certificate
on `vote.test`; API 2 cores / 3 GB, database 1 core / 3 GB, web 0.5 core / 1 GB, proxy 0.5 core / 256 MB), the test
suites on the development stack, and one Debian 13 VM (4 cores, 8 GB) for the Linux bundle.

## Result

| Area | Result |
|---|---|
| Lint (Pint, PHPStan, ESLint, Prettier, tsc), OpenAPI and types up to date, production build, dependency audit | Green (one accepted dev-only advisory, GHSA-vfj7-8cjw-p6xm, review by 2026-12-06) |
| API tests (27 folders, one process each) | **2 531 passed, 0 failed** |
| Web unit tests | **688 passed, 0 failed** |
| Browser tests (Playwright, 3 workers, 28 min) | **932 passed, 34 skipped, 0 failed** |
| Secrets (gitleaks, 244+ commits) | Clean |
| Static analysis (Semgrep, 674 files) | 0 findings |
| Vulnerabilities (Trivy: files, api image, web image) | 0 fixable HIGH or CRITICAL (see exceptions) |
| ZAP, API scan with a signed-in owner, 117 URLs from the OpenAPI file | 0 fail; 1 warning kept (XSRF cookie readable by the page: by design) |
| ZAP, full active scan of the public site over HTTPS (earlier run) | 0 fail, 137 passed |
| Targeted probes (`tools/security/probes.py`) | **54 / 54**: tenant isolation on 12 election routes, numeric ids, anonymous access, CSRF (3 ways), cookie flags, mass assignment, headers, login throttle |
| TLS (testssl.sh) | TLS 1.2 and 1.3 only, HSTS 2 years, no server banner |
| Linux bundle in a fresh Debian 13 VM | **PASS**: install, vote flow, scheduler opening, results check, restart, update, failed update with rollback, same version refused, uninstall (45 checks) |

## Load, stress and soak (submit latency, ms, measured by the client)

| Run | Accepted | Errors | p50 | p95 | p99 | max |
|---|---|---|---|---|---|---|
| 500 voters spread over 60 s (NFR-PERF-02) | 500 / 500 | 0 | 9 | 19 | 26 | 86 |
| 1 000 voters over 60 s | 1 000 / 1 000 | 0 | 10 | 18 | 27 | 47 |
| Soak: 5 runs of 500 voters back to back (2 500 votes) | 2 500 / 2 500 | 0 | 9 | 18 to 19 | 21 to 32 | 56 |
| Burst: 1 000 voters in 9 s from one address | 984 / 1 000 | 16 (429) | 506 | 585 | 602 | 616 |
| Burst: 2 000 voters in 14 s from one address | 1 251 / 2 000 | 749 (429) | 760 | 822 | 895 | 923 |

- Every accepted vote was counted and the bulletin check was ok in all runs; no 5xx, no lost vote.
- The API process stayed at 180 to 210 MiB through the soak (flat). CPU peaks: API 8 %, database 8 %, proxy 8 % of
  their caps at 1 000 voters a minute.
- The ceiling met is the per-address limiter of the voters' routes (3 000 requests a minute per address and election,
  about 700 voters a minute from one address; `api/config/votes.php`). Beyond it the API answers 429 and the voter retries;
  the server stays at under 1 s and recovers at once. The server's own capacity was not reached from one address: a
  multi-address generator would be needed to find it.
- Registration is limited to 10 an hour per address in production (the factor `AUTH_RATE_LIMIT_FACTOR` is ignored outside
  local and testing, by design): a load run registers one throw-away institution, so the Redis counters were flushed in
  the throw-away stack between runs.

## Problems found and fixed in this run

| # | Finding | Fix |
|---|---|---|
| 1 | The API healthcheck marked the stack unhealthy when the health limiter was spent (HTTP 429); the dev proxy check hit the same route | Both accept 429 / read `robots.txt` (compose.yaml, compose.prod.yaml) |
| 2 | FrankenPHP v1.13.0 asset re-uploaded by its publisher again (same version, PHP 8.5.11, Caddy 2.11.7): the bundle build refused it | Re-pinned in `installers/bundle/sources.lock` after checking the version and the publisher digest |
| 3 | Missing COOP / COEP / CORP headers; TRACE answered 500 on the web side | Proxy headers and 405 for TRACE and TRACK (all four Caddyfiles) |
| 4 | npm and its bundled packages in the production web image carried 8 fixable HIGH advisories | npm, npx and corepack removed from the runtime image |
| 5 | Outdated curl in a cached API image layer (3 HIGH) | Gone with a clean build (`--no-cache`); the release workflow builds without a cache |
| 6 | The load tool could not reach an HTTPS test stack | `VOTE_LOAD_INSECURE`, `VOTE_LOAD_RESOLVE`; one more hard-coded plain connection fixed |
| 7 | One unused import (lint warning) | Removed |

## Accepted exceptions

- **FrankenPHP's Go library** `golang.org/x/net` v0.59.0 (CVE-2026-78669, HTTP/2): no FrankenPHP image or asset carries
  0.60 yet. The API listens on plain HTTP inside the compose network only (`HTTP/2 skipped because it requires TLS`),
  the public side is Caddy. Review when FrankenPHP publishes a fixed build.
- **About 40 OS-package findings in the base images with no fix available** are ignored by `--ignore-unfixed` and reappear
  when Debian ships a fix.
- **ZAP "Cookie No HttpOnly Flag"** for `XSRF-TOKEN`: the page copies it into the `X-XSRF-TOKEN` header (double submit).
  The session cookie is HttpOnly, Secure, SameSite=Lax.

## Not covered

A multi-address stress test to find the server's own capacity; a certificate from a public authority (ACME on a real
domain); real SMTP; the Windows zip (not rebuilt in this run); an active ZAP scan of every authenticated route with real
ids (the API scan used the OpenAPI placeholder ids; the probes cover cross-tenant access with real ids).

## Re-running

```bash
make security                       # gitleaks, Semgrep, Trivy, ZAP baseline against the dev stack
python3 tools/security/probes.py --base https://vote.test:8443      # with VOTE_LOAD_INSECURE=1, VOTE_LOAD_RESOLVE=vote.test=127.0.0.1
python3 tools/loadtest/vote_load.py --base https://vote.test:8443 --voters 500 --seconds 60
installers/online/tests/vm-online.sh IMAGE ARCHIVE WORKDIR         # online edition in a VM, self-signed
installers/linux/tests/vm-test.sh IMAGE BUNDLE.tar.gz --ram 8192 --cpus 4    # set TMPDIR to a disk folder: the VM overlay can fill a tmpfs
```
