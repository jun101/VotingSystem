# Slice 04 — Review

Two reviewers, 2026-10-08, on commit aaf357e: correctness and security (the security reviewer
also applied the id-leak checklist; modernity and id-leak reviewers are reserved for slices 02,
03 and 12).
Status: **Open** until fixed or decided.

## Security

No blocking finding. Checked and correct: tenant scope and the 404-before-403 order, token
handling (hash only, single use, locked, never returned or logged), session regeneration on
accept, removed users ignored by the session provider and at sign-in, CSRF on every new write,
mail escaping, metadata stripping, and numeric ids in resources and in the generated OpenAPI and
TypeScript files (the only integers are `page`, `per_page` and `total`).

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| S1 | Should fix | `Actions/Users/RemoveUser.php`, `DELETE-users-{user}.md` | A removed user's unaccepted invitations stayed valid: an owner about to be removed could invite their own second mailbox as owner and come back. The unaccepted invitations are now deleted in the same transaction; accepted ones and other users' stay (endpoint file changed first) | Fixed |
| S2 | Should fix | `docker/proxy/Caddyfile`, `docker/api/Dockerfile` | `/media/*` was served from the app's origin with the type of any file extension, no CSP, and the volume was `0777`: anything that could write `x.html` there would run script on the app origin. Only existing `*.webp` files are served, with a strict CSP; everything else is a plain 404 without a long cache; the directory is `1777` | Fixed |
| S3 | Note | `ImageReEncoder.php`, `php.ini` | 40 million pixels need about 160 MB per decode and `memory_limit` was 512M for every request. Back to 256M; raised to 512M only around the decode, restored in a `finally` | Fixed |
| S4 | Note | `LogoController.php` | The previous logo was read outside the transaction: two concurrent uploads could orphan files on the public volume. Re-read under `lockForUpdate()`, old files deleted after commit | Fixed |
| S5 | Note | `InvitationNotification.php` | The institution's name is free text and appears in the invitation email; a link written in it could be made clickable. Escaped, so no markup. Only an owner can set it and the mail goes to an address the owner chose | Accepted, written in `docs/design/email.md` |

## Correctness

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| C1 | Should fix | `AcceptInvitation.php`, `BelongsToInstitution.php` | Accepting an invitation in a browser that already held a session of a user of another institution answered 500 (the create hook saw two institutions). The controller now ends the current authentication first; the session stays so CSRF still holds | Fixed |
| C2 | Should fix | `ImageReEncoder.php`, `PUT-institution-logo.md` | The endpoint file said an animated picture keeps its first frame; GD cannot decode an animated WebP, so it answered 415 against the contract. Contract changed: an animated WebP or PNG is refused with 415 (GIF already was) | Fixed (contract changed first) |
| C3 | Should fix | `admin/institution/page.tsx` | A slow or failing API showed "not found" instead of the error page. `notFound()` only when not signed in; otherwise throw | Fixed |
| C4 | Note | `LogoController.php` | Same as S4 | Fixed |
| C5 | Note | `CreateInvitation.php` | "One live invitation per address" was a delete then an insert with no lock: two simultaneous invitations could deadlock or leave two live links. The inviter's institution row is locked first | Fixed |
| C6 | Note | `CreateInvitation.php` | `expires_at` and `created_at` were taken from two clocks, so "7 days" could be 7 days minus a second. One `$now` for both | Fixed |
| C7 | Note | `ImageReEncoder.php` | EXIF orientation is read for JPEG only. Contract now says so; PNG and WebP carry no orientation that is read | Fixed (contract) |
| C8 | Note | `routes/api.php`, `bootstrap/app.php` | The rate limiter ran before the role check: a manager hitting an owner route again and again got 429 instead of 403. The middleware priority puts the owner check before the throttle (and after route binding, so another institution's record is still 404 first). Written in the API README | Fixed |
| C9 | Note | `Caddyfile` | `/media/` answered 404 with the one-year immutable cache header. Only existing files get it | Fixed (with S2) |
| C10 | Note | `lib/format/timeZones.ts` | The static time zone list was not checked against PHP's. A test now compares them | Fixed |
| C11 | Note | `lib/api/server.ts` | The 101st user or invitation was dropped without a sign. The page shows "100 of N" | Fixed |

Decisions of the coder that the review judged correct: no counter headers on accept-invitation
(`Retry-After` kept on a 429), the implicit `Choice` rule and `ReportsRuleCodes` (empty `type`,
`language`, `role` give `in`), and `owner` ordered after route binding.

Checked and correct: the last-owner lock, the single use of the accept token (`lockForUpdate` and
`whereNull('accepted_at')`), the order of the accept checks (404, 410, 422, 403, 409), the session
id regenerated at accept, the queued encrypted email in the institution's language, and the French
and English message files.
