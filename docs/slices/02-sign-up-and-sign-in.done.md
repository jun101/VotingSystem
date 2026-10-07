# Slice 02 — Sign-up and sign-in: what was done

2026-10-07 · branch `slice/02-sign-up-and-sign-in`

## What you can do now

1. `make up`, then open http://localhost:8080/register. Create an institution (name, your
   name, email, a password of 12 characters or more). You land on `/admin`, signed in, with
   a banner saying the email is not verified.
2. Open Mailpit, http://localhost:8025: the verification email is there, in French or English
   as your browser. Click "Vérifier mon courriel" (or "Verify my email"): the page confirms
   it, and the banner is gone on `/admin`.
3. "Send the email again" in the banner sends a new link; the old one stops working.
4. Sign out, then sign in at `/login`. A wrong password says so without saying which part was
   wrong.
5. `/forgot-password`: give your address, find the reset email in Mailpit, set a new password;
   you are sent to `/login` with a confirmation. Other devices are signed out.
6. The pages follow the browser's language (French by default), work on a phone, and the forms
   can be used with the keyboard alone.

## What was built

| Part | Content |
|---|---|
| Database | `institutions`, `users`, `email_verification_tokens`, `password_reset_tokens`; the `app` account cannot delete from `institutions` and `users` |
| API | Nine endpoints under `/api/v1/auth` (CSRF cookie, register, verify email, resend, sign in, sign out, current user, forgot password, reset password), exactly as `docs/api/auth/` |
| Security | Argon2id (19 MiB, 2 passes); session in Redis, rotated at sign-in and sign-out; CSRF by header only; one-time 64-character tokens stored as SHA-256 hashes, valid 24 h (verification) or 60 min (reset); rate limits per address and per user; unknown email and wrong password look the same; queued payloads encrypted |
| Emails | Verification and reset, French and English, plain and HTML, greeting without the name, links with a token only |
| Web | Pages `/register`, `/login`, `/forgot-password`, `/reset-password`, `/verify-email` in the A01 layout, an `/admin` placeholder, an API client with CSRF handling, French and English messages for every error code |
| Stack | `AUTH_RATE_LIMIT_FACTOR` (100 in development and the browser tests; used only when `APP_ENV` is `local` or `testing`); the proxy sends `X-Frame-Options DENY` and `frame-ancestors 'none'`; the browser tests read Mailpit |

## Decisions along the way

- Custom tokens instead of the framework's links, so no email address or numeric id is ever in
  a link (confirmed by Jun).
- Registering with an address already in use says "taken" (confirmed by Jun).
- Sign-in works before the email is verified; verification only blocks opening an election
  (confirmed by Jun).
- Session and CSRF cookies are not encrypted: they are random values and carry nothing else.
- Forgot-password does its lookup and sending in an encrypted queued job, so a known and an
  unknown address cost the same.
- Raising the hashing cost ends a user's other sessions at their next sign-in; accepted and
  written in the endpoint file.
- No absolute session lifetime yet (ASVS 3.3.2 asks about 12 h): deferred to slice 19.

## Changed along the way

- The test harness was wrong when first used against real code: the browser client never sent
  its cookies, and two tests needed a request with no `Accept-Language`. Both corrected by the
  test author; their intent did not change, and the French default is now tested for real.
- The resend endpoint file gained the 403 `institution_suspended` scenario the code already
  gave.
- Two bugs of slice 01b surfaced once pages hydrated and were fixed (`useEnter`, `GrowBar`),
  and the web dev server allows the `proxy` origin for the browser tests.

## Review

Four reviews (correctness, security, modernity, id-leak): 25 findings or notes. Fixed: 1
blocking-process, 3 should-fix security, 3 should-fix correctness, and the notes except those
accepted or deferred (M1–M3, C6 accepted; S9 deferred to slice 19). No id leak. See
`02-sign-up-and-sign-in.review.md`.

## Checks

- `make check` passes: 231 API tests, 112 web unit tests, 180 browser tests (2 skipped, each
  for the other screen size), generated files up to date, build and audit.
- Compared with screen A01 (`Register.dc.html`) at 1280 px and 390 px: same two-panel layout
  and copy, with the showcase panel in the approved gradient (slice 01b) and the accent
  button for the main action, and a show/hide toggle on the password. The phone layout
  stacks and shows the verification banner.
- The verification email was read in Mailpit: right subject, greeting without the name, one
  link holding a token only.
- **Not verified:** the Redis rate limiter with the real limits (the browser tests run with the
  factor at 100, the API tests use an in-memory cache); the home page's first-load JavaScript
  against its slice 01b size (no baseline was kept; it was 173.5 KB gzip when measured by the
  coder).
- The Next.js development badge shows at the bottom-left in development only.

## Next

Slice 03: admin shell and tenant isolation (side menu, top bar, language switch, and the
two-institution isolation test suite). Before it: the checkpoint, the pull request and the
merge, after you try the pages above.
