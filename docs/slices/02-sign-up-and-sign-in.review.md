# Slice 02 — Review

Four reviewers, 2026-10-07, on commit bc3c1dc: correctness, security, modernity, id-leak.
Status: **Open** until fixed or decided; **Test author** means the finding is about the
tests or the endpoint files, which are not the coder's to change.

## Id-leak

No finding. Traced: resources, models, routes, migrations, notifications and their mail
views, queue payloads, logs, limiter keys, errors, cookies and session, the generated
OpenAPI file and TypeScript types, the web pages. The token tables have a numeric id and
`user_id` but never leave the server.

## Modernity

No dependency was added or changed. No blocking and no should-fix.

| # | Where | Note | Status |
|---|---|---|---|
| M1 | `User`, `Institution` | `$fillable` and `$hidden` as properties; Laravel 13 documents `#[Fillable]` attributes. The properties still work | Accepted |
| M2 | `components/auth/useAuthForm.ts` | Hand-rolled form state instead of `useActionState`; justified, the call goes to the Laravel API with the XSRF header | Accepted |
| M3 | `lib/i18n/client.tsx` | `Context.Provider` instead of `<Context value>`; still supported | Accepted |

## Security

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| S1 | Should fix | `Notifications/ResetPasswordNotification.php`, `VerifyEmailNotification.php` | The raw token is in the queue payload in Redis, which is written to disk (`--appendonly yes`): a copy of the volume or a backup gives a live reset token. Make both notifications `ShouldBeEncrypted` | Fixed |
| S2 | Should fix | `config/auth.php`, `.env.example` | `AUTH_RATE_LIMIT_FACTOR=100` copied into a production `.env` silently removes the limits. Honour the factor only when `APP_ENV` is `local` or `testing`; force 1 otherwise | Fixed |
| S3 | Should fix | `docker/proxy/Caddyfile` | No frame protection on the pages that change something: add `X-Frame-Options DENY` (or CSP `frame-ancestors 'none'`) | Fixed |
| S4 | Note | `AttemptLogin.php`, limiter in `AppServiceProvider.php` | Per-email limiter keys use the exact string; the collation ignores accents, so `josé@` and `jose@` have separate counters. Key by the matched user's uuid when there is one | Fixed |
| S5 | Note | `RequestPasswordReset.php` | Forgot-password does visibly more work for a known address (upsert and queue push). Do the lookup and the sending in the queued job so both paths cost one push | Fixed |
| S6 | Note | `mail/action-text.blade.php`, `RegisterRequest.php` | A free-text `name` appears in the greeting of the email to the address owner: phishing text under our name. Use a greeting without the name | Fixed |
| S7 | Note | `User.php`, `Institution.php` | `role`, `institution_id`, `suspended_at` are fillable; a later `create($request->validated())` would let a caller set them. Remove from `$fillable` | Fixed |
| S8 | Note | `LoginRequest.php` | No maximum on `email` and `password`: Argon2id on megabytes of input. Add `max:255` and `max:1024` | Fixed |
| S9 | Note | `config/session.php` | 120 minutes idle and no absolute lifetime; ASVS 3.3.2 (level 2) expects about 12 hours. Recorded for slice 19 (hardening) | Deferred to slice 19 |

Checked and correct: Argon2id parameters and rehash, timing at sign-in, proxy trust and IP,
session rotation and invalidation, CSRF (header only, no shortcut), cookie flags, token
generation and single use, links from `APP_URL`, `no-referrer`, logs, open redirects, XSS,
storage, `allowedDevOrigins` (dev server only).

## Correctness

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| C1 | Blocking (process) | `CrossCuttingTest.php`, `RegisterTest.php`, `Support/AuthClient.php` | The coding step's commit carried edits to acceptance tests and harness (made by the test author after the coder stopped, as the rule asks). The edits leave "no Accept-Language gives French" untested: the test client always adds one | Fixed: the harness can send no header; both tests now test the French default for real |
| C2 | Should fix | `routes/api.php`; `docs/api/auth/POST-auth-verify-email-resend.md` | Resend answers 403 `institution_suspended` (middleware) and the endpoint file does not list it | Fixed: scenario 7 added to the endpoint file and tested |
| C3 | Should fix | `components/auth/useAuthForm.ts`, `Button.tsx` | After an error that belongs to no field, focus drops to `<body>` (the submit button was disabled). Focus the `form-error` notice (`tabIndex={-1}`) | Fixed |
| C4 | Should fix | `Actions/Auth/ResetPassword.php`, `Rules/SameAsEmail.php` | Registration trims before comparing the password with the email, reset does not: `"  a@b.c  "` passes at reset. Reuse the rule | Fixed |
| C5 | Note | `RequestPasswordReset.php` | Same as S5 | Fixed |
| C6 | Note | `AttemptLogin.php` | Rewriting the hash at sign-in (after the cost parameters change) ends the user's other sessions. Accept and document in the endpoint file, or refresh the session hash | Accepted, written in the endpoint file |
| C7 | Note | `VerifyEmailState.tsx` | The `error` state (429, 5xx, network) has no retry and the result is cached for the token. Add a retry button | Fixed |
| C8 | Note | `lib/api/browser.ts` | A rate-limited `GET /auth/csrf` makes the person read "session expired" instead of "too many attempts". Turn that answer into an `ApiError` | Fixed |
| C9 | Note | `components/admin/AdminPlaceholder.tsx` | A platform admin sees their own name as the institution. Show nothing when `institution` is null | Fixed |

Checked and correct: tokens (hash, shape, lock, single use, replace, 410 keeps the row),
registration transaction and concurrent sign-ups, removed users, suspension, session
security, CSRF, limiter keys, Octane state, error-code mapping, logs, mail language, fr and
en message parity, rules 9 and 10, and the slice 01b changes (`useEnter`, `GrowBar`).
