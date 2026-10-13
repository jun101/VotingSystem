# Slice 19 — Security hardening and the operator's vote-audit command

Brief · 2026-10-12 · branch `slice/19-security-hardening` (from `staging`). Jun's choices of 2026-10-12 are in the table.

## Goal

1. The operator can read the vote audit of one election with a command that runs on the operator's own machine, with the
   private key given for that use only, and every use is logged (FR-SEC-09, NFR-SEC-09).
2. The web application sends a strict Content-Security-Policy, the proxy sends HSTS in production, every API answer
   carries the standard hardening headers (NFR-SEC-04).
3. A signed-in session ends 12 hours after sign-in whatever its activity (ASVS 3.3.2, finding S9 of slice 02).
4. In production the platform admin cannot use the platform screen without two-factor authentication turned on.
5. Dependencies and logs are audited automatically (NFR-OPS-04, supply chain).

## Requirements covered

FR-SEC-08, FR-SEC-09, FR-SEC-10, NFR-SEC-01, NFR-SEC-04, NFR-SEC-05, NFR-SEC-07, NFR-SEC-09, NFR-OPS-04.

## Jun's decisions (2026-10-12)

| Question | Decision |
|---|---|
| Where the audit tool runs | **On the operator's machine**, standalone; the private key never touches the server (NFR-SEC-09). The command refuses to run when `APP_ENV` is `production` |
| What it prints | **A CSV of decrypted records** for one election, a `--reason` required, to a file the operator names (never to a log) |
| Hardening in this slice | Strict CSP and HSTS and headers; absolute session lifetime; two-factor required for the platform admin; dependency and secrets audit |

## The operator's command (`php artisan vote-audit:read`)

[docs/api/system/COMMAND-vote-audit-read.md](../api/system/COMMAND-vote-audit-read.md). Acceptance tests
`api/tests/Acceptance/Slice19/AuditReadCommandTest.php`.

## Rules

| Rule | Value |
|---|---|
| Absolute session lifetime | `SESSION_ABSOLUTE_LIFETIME` minutes, default **720**; measured from the sign-in (`session started_at`, set at login and at the second step of a sign-in); the session is flushed and the request answers 401 `unauthenticated`. Applies to institution users and platform admins; the voter session has its own short life |
| Platform admin two-factor | `platform.require_two_factor` (env `PLATFORM_REQUIRE_TWO_FACTOR`, default **on in production**, off elsewhere): a platform admin without two-factor gets 403 `two_factor_required` on every `/platform` route and on impersonation; `/auth/me` and the two-factor setup routes work |
| API headers | Every API answer (the proxy sets its own `Referrer-Policy` only when the answer has none): `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store` on every answer that is not a file or media (JSON of the admin and voting API), `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` |
| Web CSP | Per request nonce from the web proxy file: `default-src 'self'; script-src 'self' 'nonce-…' 'strict-dynamic'; style-src 'self' 'nonce-…'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'` and no `'unsafe-inline'` or `'unsafe-eval'` in `script-src` (development builds may add `'unsafe-eval'` only when `NODE_ENV` is not production) |
| HSTS | `Strict-Transport-Security: max-age=63072000; includeSubDomains` in `docker/proxy/Caddyfile.production` (created here, completed in slice 20); never on the development proxy (plain HTTP) |
| Permissions-Policy | `camera=(), microphone=(), geolocation=(), payment=()` at the proxy |
| Audit | CI runs `composer audit` and `npm audit --omit=dev --audit-level=high` as failing checks; a test runs a voting flow and checks that no log line holds a code, a password, a token or a vote (NFR-OPS-04) |

## What to build (runs)

- **19a, server:** the command and its connection config; absolute session lifetime middleware; platform two-factor
  middleware and config; API hardening headers middleware; the log test; CI audit steps.
- **19b, web and proxy:** CSP with nonces in `web/src/proxy.ts` (and `app/layout.tsx` for inline scripts that need the
  nonce), `docker/proxy/Caddyfile` headers (Permissions-Policy), `docker/proxy/Caddyfile.production`; fix every page
  that the strict CSP breaks (inline styles, third-party loads); browser tests `web/e2e/slice19/headers.spec.ts`.

## Not in this slice

Backups, the production Compose file, load tests, the VPS (slice 20).
