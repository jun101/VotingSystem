# Slice 04b — Two-factor authentication: what was done

2026-10-08 · branch `slice/04b-two-factor`

This is the second half of FR-INST-04 (slice 04 was cut in two on 2026-10-07).

## What you can do now

1. `make up`, sign in, open the user menu and choose **Mon compte** (two clicks from any admin page).
2. **Activer**: type your password, scan the QR code with an authenticator application (or type the key shown in
   groups of four), then give the first 6-digit code. Eight **recovery codes** are shown once, with Copier and
   Télécharger (a text file with the codes only); tick "J'ai conservé ces codes" to finish.
3. Sign out and sign in again: after the password, a second step asks for the code. "Utiliser un code de
   récupération" swaps the field; a recovery code works once. Five wrong codes send you back to the password.
4. **Désactiver** and **Renouveler les codes** each ask for your password **and** a current code (or a recovery
   code).
5. An owner sees on each user card whether two-factor is on ("Double authentification activée" / "Sans double
   authentification"), and can turn off another user's with **Réinitialiser** (the owner's password, and the
   owner's own code if the owner has two-factor on). If the last owner is locked out, the operator runs
   `php artisan auth:reset-two-factor {email}` on the server.

## What was built

| Part | Content |
|---|---|
| API | Seven new endpoints and one new scenario: `POST /auth/login` (scenario 10, two-factor asked), `POST /auth/two-factor-challenge`, `GET /auth/two-factor`, `POST /auth/two-factor/{setup,confirm,disable,recovery-codes}`, `POST /users/{user}/two-factor/reset`; `GET /users` gains `two_factor_enabled` |
| Sign-in | A user with two-factor gets no session from the password step: the session holds only a pending sign-in (user, time, a hash of the password hash), valid 5 minutes. The challenge regenerates the session, sets `last_login_at`, and answers the usual user body |
| TOTP | `spomky-labs/otphp`; SHA-1, 6 digits, 30 seconds, secret of 160 bits, one period either side; a code of a period already used is refused (stored period, claimed atomically) |
| Recovery codes | Eight, ten characters each, from a CSPRNG; only a keyed hash of each is stored, in the encrypted column; each works once |
| Limits | 5 wrong codes per 15 minutes per **account** (cache; from the sixth, 429 even for a right code or a recovery code) plus 5 per pending sign-in and 10 per minute per address; one **password-failure** counter per account (5 in 15 minutes) fed by wrong passwords at sign-in from any address and on the settings routes: the fifth on a settings route ends the session, later attempts answer 429 and keep it; sign-in itself is never refused by it |
| Web | The code step on the login page (numeric keyboard, one-time-code hint, focus), the **Mon compte** page with the QR code drawn in the browser (`qrcode`, nothing sent to another service), the recovery list with a leave warning, the disable, renew and reset dialogs, the chip and the reset on the users card; fr and en |
| Operator | `auth:reset-two-factor {email}`: names the user's uuid, never the address; one log line without an address |
| Tests | 150 Slice 04b acceptance tests, 70 browser tests for the slice; the helper that computes codes is written without the library (checked against the RFC 6238 test vectors) |

## Decisions along the way

- **Where:** a new page "Mon compte" (Jun). **Lockout:** an owner turns it off; the operator for the last owner; no
  reset link by email (Jun). **Password:** asked to set up, turn off and renew (Jun).
- **Review decisions (Jun, 2026-10-08):** 5 wrong codes per 15 minutes per account; 5 wrong passwords in 15 minutes
  end the session, later ones answer 429 with the wait; the owner's reset is a POST that asks the owner's password;
  login failures feed the password counter; turning off and renewing ask for a second factor too; a password reset
  clears no counter; no fourth review round.
- Proposed here and not vetoed so far: eight recovery codes of ten characters; the pending sign-in lasts 5 minutes;
  open sessions stay open when the second factor changes; the platform admin can use two-factor too; a person
  cannot reset their own through the owner endpoint.
- The checks of the tests now read a real test log (a `file` channel): the older "nothing sensitive is logged"
  tests used to read nothing.

## Changed along the way

- Acceptance tests corrected by the test author after the code was written, none weakened in intent: the recovery
  code dataset (a closure inside a closure), the last-sign-in time, the users list key check, the chip locator, and
  a self-reset order (the endpoint file now matches the test).
- Endpoint files changed after each review, before the code: the account limit, the password lockout and the 429,
  the owner's reset as a POST with the owner's password, the second factor to turn off and renew.

## Review

Three rounds of two reviewers (correctness, security with the id-leak checklist). All findings fixed except what is
accepted and written in the endpoint files (the fixed 15-minute window, a success clearing the count, anyone who knows
an email being able to keep the settings routes at 429) and one deferred (the generated error shape of 403 and 409,
older than this slice). No id leak. See `04b-two-factor.review.md`.

## Checks

- `make check` passes: 725 API tests, 141 web unit tests, 390 browser tests (6 skipped, each for the other screen
  size), generated files up to date, build and audit.
- No mockup exists for these screens; the set-up card, the recovery list, the account page and the code step were
  looked at in screenshots at 1280 px and 320 px: cards on the product's own tokens, the QR code on white, the code
  field focused with a numeric keyboard.
- **Not verified:** the set-up with a real authenticator application on a real phone (done-when item 2); the Redis
  limiters at real limits and in production (the tests use the array cache); the browser's back/forward buttons are
  not guarded while the recovery list is shown (only the browser's own leave warning).
- Running three full browser runs in one hour used up the test stack's sign-up limit (1,000 an hour from one
  address): a limit of the test setup, not of the code.

## Next

Slice 05, elections (create, edit, duplicate, delete a draft; cards with filters; screens A03 and A04). Before it:
your checkpoint on the pages above, the pull request and the merge.
