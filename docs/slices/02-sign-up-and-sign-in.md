# Slice 02 — Sign-up and sign-in

Brief · 2026-10-07 · branch `slice/02-sign-up-and-sign-in`

## Goal

A person registers their institution, verifies their email, signs in and out, and resets
a forgotten password. This is the first slice with a database schema, a session, emails
and pages that change something, so it also puts in place what every later slice relies
on: the migrations and the app's database rights, the session and CSRF protection, the
current-user resource, the rate limiters, the two email templates and the web's API client
for state-changing calls.

At the end, a person opens `http://localhost:8080/register`, creates an institution, finds
the verification email in Mailpit (`http://localhost:8025`), clicks the link, signs out,
signs in again, asks for a reset link, sets a new password and signs in with it.

## Requirements covered

FR-INST-01, FR-INST-04 (password part; two-factor is slice 04), FR-INST-06 (the sign-in
refusal only), NFR-SEC-02 (Argon2id), NFR-SEC-04 (secure cookies, CSRF), NFR-SEC-05
(rate limits on sign-in and reset), NFR-SEC-08 (no id in links or bodies), NFR-UX-01
(French and English, emails included), NFR-UX-02 and 03 (screen A01 at 320 px, keyboard,
accessibility check).

## Read before coding

[architecture.md](../design/architecture.md) sections 4.3 to 4.6 and 12 ·
[database.md](../design/database.md) sections 1, 2.1 and 4 (database rights) ·
[frontend.md](../design/frontend.md) sections 3, 4 and 6 · [API conventions](../api/README.md) ·
the nine endpoint files in [docs/api/auth/](../api/auth/) · the mockup
[Register.dc.html](../design/mockups/Register.dc.html) (screen A01).

## What to build

### 1. Database

| Item | Requirement |
|---|---|
| Migrations | `institutions`, `users`, `email_verification_tokens`, `password_reset_tokens` exactly as in database.md section 2.1 (the two token tables replace the framework's standard one), including the `users` check constraint, `uuid` on the first two, unique `users.email` and `token_hash`. Run by the `migrator` account |
| Rights | After each migration the `app` account is granted only what it needs, table by table (slice 01's mechanism). The two token tables allow insert, select, update and delete; `institutions` and `users` allow insert, select and update (no delete: a user is removed with `deleted_at`) |
| Models | `Institution`, `User` use the `uuid` trait of slice 01. `User` hides `password`, the two-factor columns and the remember token. No tenant scope yet: slice 03 adds it, with its test suite |
| Factories | One for each, to build test data; the user factory gives a verified owner by default and a `unverified()` state |

### 2. API

| Item | Requirement |
|---|---|
| Routes | The nine endpoints of [docs/api/auth/](../api/auth/), under `/api/v1/auth`, behaviour and status codes exactly as their files. If the code must differ, the file is changed first first |
| Session | Server-side session in Redis, cookie as described in [GET /auth/csrf](../api/auth/GET-auth-csrf.md). Only routes that need it start the session, so `/health` stays free of cookies |
| CSRF | Every POST under `/api/v1/auth`: a missing or wrong `X-XSRF-TOKEN` answers 419 `csrf_mismatch` in the shared error shape |
| Current user | One API resource for "the current user" (the `data` of [GET /auth/me](../api/auth/GET-auth-me.md)); never outputs `id` or `*_id` of the database |
| Passwords | Argon2id (`HASH_DRIVER=argon2id`), cost parameters in the config. Rule: 12 to 128 characters and not equal to the email. Nothing else (no composition rules) |
| Tokens | 64 hexadecimal characters from a secure random source. Only the SHA-256 hash is stored. Compared by hash, in constant time. Verification 24 h, reset 60 min. A new token replaces the user's previous one |
| Rate limits | Named limiters, declared like slice 01's, with the numbers of each endpoint file. Counters in Redis. The sign-in limiter counts failed attempts per email address and IP address |
| Sign-in timing | An unknown email costs about the same as a wrong password (hash against a dummy value) |
| Emails | Two queued notifications (verification, reset), in the user's language, French and English, plain and HTML parts, sent through the `queue` service. Links `{APP_URL}/verify-email?token=…` and `{APP_URL}/reset-password?token=…`. No numeric id, no email address in a link. The mail's `From` comes from the environment |
| Suspended institution | Sign-in and `GET /auth/me` refuse a user whose institution has `suspended_at` set (the platform admin that sets it is slice 18) |
| Octane | The signed-in user, the institution and the session are per request. A test shows that a request signed in as one user followed by an unauthenticated request on the same worker is unauthenticated (architecture.md section 4.2) |
| Logs | A sign-in, a registration or a reset logs the request id and the outcome only: never the email, password, token or link |
| Generated files | `docs/api/openapi.json` and `web/src/lib/api/schema.d.ts` regenerated by the existing command, never by hand |

### 3. Web

| Item | Requirement |
|---|---|
| Pages | `/register`, `/login`, `/forgot-password`, `/reset-password`, `/verify-email`, in the layout of screen A01: showcase panel (the `ShowcaseCard` split of slice 01b) and a light panel with the form; stacked under `md` |
| `/admin` | A placeholder that needs a signed-in user: "Welcome, {name}", the institution's name, a sign-out button, and a banner with a "Send the email again" button while the email is not verified. Slice 03 replaces it with the real shell |
| Redirects | Signed in on `/login`, `/register` or `/forgot-password` → `/admin`. Not signed in on `/admin` → `/login`. After sign-in → `/admin`. After a reset → `/login` with a short confirmation |
| API client | Extends `lib/api/client.ts`: fetches the CSRF cookie once, sends `X-XSRF-TOKEN` and `Accept-Language`, turns the error shape into a typed error. No token in browser storage |
| Forms | Labels, inline errors from `error.fields` (each rule code is a message in both languages), a disabled and busy submit button while waiting, focus on the first error, a visible password toggle. The main action uses the accent button. Reveal on entry, no other motion |
| Messages | Every text in the French and English message files, including each error code and rule code of the endpoint files |
| Language | The pages follow the browser's language; registering sends it as `language` |
| Pages without JavaScript | A form page renders without JavaScript (submitting needs it) |

### 4. Test data

`make seed` is not needed. The end-to-end tests create their users through the API and read
the emails from Mailpit's HTTP API (`http://localhost:8025/api/v1/messages`); a helper in
`web/e2e/support/` does this.

## Rules checked by tests

1. Another institution's data is never involved here; but a user answers only about
   themselves: `GET /auth/me` never returns another user's data whatever the request.
2. The two token tables hold only hashes; a token, a password or an email never appears in
   a log line or an error body.
3. No answer and no email contains a database id; every `id` is a UUID.
4. The same answer, status and headers, for a reset request on an existing and an unknown
   address; the same answer for an unknown email and a wrong password at sign-in.
5. A token works once; a new token kills the old; an expired one answers 410.
6. After sign-out, the old session cookie opens nothing; after a password reset, the user's
   other sessions stop working.
7. The pages pass the automated accessibility check at 320 px and 1280 px, in French and
   English, and are usable by keyboard alone.

## Acceptance tests

Written before the code, committed on the branch before the code and **not edited during
coding**. If one seems wrong, stop and say why.

| Where | Checks |
|---|---|
| `api/tests/Acceptance/Slice02/` | One file per endpoint, one test per scenario of its file, plus the Octane and logging tests |
| `web/e2e/slice02/` | The full path of the goal, in French and English, at desktop and phone width; the redirects; the errors shown in the form; reduced motion |

All the slice 01 and 01b tests keep passing.

## Out of scope

- Two-factor authentication, invitations, removing users, the profile and the logo
  (slice 04).
- The tenant scope and the cross-institution test suite (slice 03).
- The admin shell, the side menu and the language switch of the admin area (slice 03).
- Suspending an institution (slice 18); platform admin accounts (slice 18).
- Remember-me, social sign-in, a breached-password check.

## Done when

1. `make check` passes entirely, with the slice 01, 01b and 02 tests.
2. A person can do the whole path of the goal in a browser, in French and in English, and
   the two emails are in the right language in Mailpit.
3. `docs/api/openapi.json` and `schema.d.ts` are up to date with the code.
4. The CI workflow passes; no reviewer finding is left open.

## Decisions taken in this brief

- **Custom tokens instead of the framework's reset and verification links**, because those
  put the email address (reset) and the numeric id (verification) in the link, which
  NFR-SEC-08 forbids. The framework's package for sign-up flows (architecture.md section
  4.3) may still be used for its actions and password broker if that stays within these
  endpoint files; the files rule.
- **Registering with an address already in use answers 422** rather than hiding it; the
  rate limit slows enumeration. Jun can ask for the hidden variant (always accept, send
  "you already have an account" by email) at the checkpoint.
- **Sign-in works before the email is verified**; verification only blocks opening an
  election (slice 13) and is shown as a banner.
