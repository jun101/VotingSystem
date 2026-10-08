# Slice 04b — Two-factor authentication

Brief · 2026-10-08 · branch `slice/04b-two-factor`

## Goal

A person turns on two-factor authentication (TOTP) from a new page "Mon compte", scans a QR code
with an authenticator application, confirms with a first code, and keeps eight recovery codes. From
then on, signing in asks for a code after the password. The person can turn it off, renew the
recovery codes, or sign in with a recovery code when the phone is lost. An owner can turn off another
user's two-factor; the platform operator can do it for a locked-out last owner. The users card of
slice 04 shows who has it on.

This is the second half of FR-INST-04; slice 04 was cut in two on Jun's decision (2026-10-07).

## Requirements covered

FR-INST-04 (optional two-factor, TOTP, for institution users; a platform admin can turn it on too),
FR-INST-03 (the owner's reset of a user), NFR-SEC-01 (ASVS level 2: re-authentication to change the
second factor, replay refused), NFR-SEC-05 (rate limits on the code), NFR-SEC-03 (the new tenant
route joins the isolation suite), NFR-UX-03 (accessibility).

## Read before coding

[architecture.md](../design/architecture.md) sections 4.3 and 4.4 ·
[database.md](../design/database.md) section 2.1 (`users`: `two_factor_secret`,
`two_factor_recovery_codes`, `two_factor_confirmed_at` already exist) ·
[frontend.md](../design/frontend.md) · [API conventions](../api/README.md) · the eight files below ·
[POST /auth/login](../api/auth/POST-auth-login.md) (scenario 10 is new) · the slice 04 review file.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `POST /auth/login` (new scenario 10) | [auth/POST-auth-login.md](../api/auth/POST-auth-login.md) |
| `POST /auth/two-factor-challenge` (public, needs a pending sign-in) | [auth/](../api/auth/POST-auth-two-factor-challenge.md) |
| `GET /auth/two-factor` | [auth/](../api/auth/GET-auth-two-factor.md) |
| `POST /auth/two-factor/setup` | [auth/](../api/auth/POST-auth-two-factor-setup.md) |
| `POST /auth/two-factor/confirm` | [auth/](../api/auth/POST-auth-two-factor-confirm.md) |
| `POST /auth/two-factor/disable` | [auth/](../api/auth/POST-auth-two-factor-disable.md) |
| `POST /auth/two-factor/recovery-codes` | [auth/](../api/auth/POST-auth-two-factor-recovery-codes.md) |
| `DELETE /users/{user}/two-factor` (owner) | [users/](../api/users/DELETE-users-{user}-two-factor.md) |
| `GET /users` gains `two_factor_enabled` | [users/GET-users.md](../api/users/GET-users.md) |

`GET /auth/me`, the login, register, accept and `PATCH /auth/me` bodies do **not** change: the
`UserResource` keeps its keys. The state of the second factor is read from `GET /auth/two-factor`.

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| TOTP | A small maintained library (`spomky-labs/otphp`, Jun's choice of 2026-10-07): SHA-1, 6 digits, 30 seconds, secret of 160 bits in base32, window of one period before and after. Issuer "New Voting System", label the user's email in the `otpauth` link |
| Replay | A migration adds `users.two_factor_last_step` (unsigned big integer, null): the period of the last accepted code. A code of a period at or below it is refused, at confirmation and at sign-in; clearing the second factor clears it |
| Secret and codes | The secret stays in the existing encrypted `two_factor_secret`. Eight recovery codes of ten lowercase letters and digits with a dash in the middle (`k3m9x-4tq7a`), generated with a CSPRNG; only a keyed hash of each is stored (HMAC-SHA-256 with the application key) in the encrypted `two_factor_recovery_codes`, as a JSON list; a code is normalised (lower case, no dash or space) before hashing and compared in constant time; a used code is removed from the list |
| Pending sign-in | `POST /auth/login` for a user with `two_factor_confirmed_at` set checks everything it does today (password, suspension, throttling counters) and then stores in the session **only** the user's key, the time and a counter of wrong codes, answers scenario 10, and does **not** sign in, set `last_login_at`, or clear the failure counter. It expires after 5 minutes. The challenge reads that, never a request value; a request body cannot name a user |
| Challenge | Checks the pending sign-in (401 if missing, expired or ended), re-checks that the institution is not suspended (403), verifies `recovery_code` if present, else `code`; a wrong one counts, the fifth ends the pending sign-in; on success logs the user in, regenerates the session id and the CSRF token, removes the pending state, sets `last_login_at`, answers the `UserResource`. Limiters: `auth-two-factor-challenge` 10 per minute per IP, multiplied by `AUTH_RATE_LIMIT_FACTOR` like the others |
| Own settings | `setup`, `confirm`, `disable`, `recovery-codes`: group `cookie-session`, `auth`, `institution.active`, one shared limiter `two-factor` 10 per minute per user (multiplied by the factor). The password is checked with the same hash check as sign-in (a rehash if needed is not done here). A wrong password is a 422 `password: incorrect`, never a 401 (the person is signed in) |
| Owner reset | `DELETE /users/{user}/two-factor`: the `owner` middleware and binding of slice 04 (404 before 403); the 409 for oneself comes before the 409 for "not enabled" |
| Operator command | `php artisan auth:reset-two-factor {email}`: clears the second factor of the user with that email (including a removed user's? no: only live users). Exit 0 and a line naming the user's uuid, never the email; exit 1 and a neutral line when there is no such user or none is enabled. It writes one log line (`two_factor.operator_reset`, the user's uuid, the outcome); no address in it. The only path outside the web application for a locked-out last owner |
| Users resource | `TeamMemberResource` gains `two_factor_enabled` (true when `two_factor_confirmed_at` is set) |
| Tenant suite | `api/tests/Support/Tenancy.php` lists the new routes: the own-settings routes in `OWN_USER_ROUTES`, the challenge in `PUBLIC_ROUTES`, `DELETE /users/{user}/two-factor` in `TENANT_ROUTES` |
| Logs | Never a code, a secret, a recovery code, a password or an address; the request id and the outcome only |
| Generated files | `docs/api/openapi.json` and `schema.d.ts` regenerated, never by hand |

### 2. Web

| Item | Requirement |
|---|---|
| Login | After the password, if the answer is `two_factor_required`, the same page shows the code step (no new URL): a 6-digit field (`inputmode="numeric"`, `autocomplete="one-time-code"`, focus on it), a link "Utiliser un code de récupération" that swaps the field for the recovery code, the submit button, and "Annuler" that goes back to the password step. A 422 shows the error under the field; a 401 (expired or ended) returns to the password step with a polite message; success goes to `/admin` and refreshes as slice 03 sign-in does, so `<html lang>` follows the stored language |
| User menu | A new entry "Mon compte" (`/admin/account`) in the user menu, above sign out |
| Page `/admin/account` | In the admin shell, title "Mon compte" / "My account". A **Sécurité** card: the state pill ("Activée" / "Désactivée"), the number of recovery codes left, and the actions. Cards, no table. No "change my name or password" yet (out of scope) |
| Set up | "Activer" asks for the password; then a card shows the **QR code** (drawn in the browser from the `otpauth` link with a small library; the link or the secret never goes to any other service) and the secret in groups of four for manual entry, a field for the first code, and "Confirmer". Then the **recovery codes** are shown once: a list, "Copier", "Télécharger" (a text file named `codes-de-recuperation.txt`, no address or institution name in it), and a checkbox "J'ai conservé ces codes" that enables "Terminer". Leaving the page before finishing says the codes are lost (the setup is already active; they can be renewed) |
| Disable, renew | "Désactiver" and "Renouveler les codes" each open a dialog with the password (`ConfirmDialog` of slice 04: focus trap, Escape, focus returned). The renewed codes show in the same list as above |
| Users card | Each user card shows a chip "Double authentification activée" / "Sans double authentification" (the mockup); for an owner looking at another user whose second factor is on, a "Réinitialiser" action with a confirmation naming the person, which calls the reset |
| Messages | Every text in the French and English files, none in a component |
| Motion and tokens | Reveal kit of slice 01b, off under reduced motion; tokens only |
| Accessibility | Labels and `aria-describedby`; the QR code has a text alternative (the secret is next to it); focus moves to the first error after a failed step and to the recovery list after confirming; usable from 320 px by keyboard alone |

### 2b. Names the browser tests rely on

| Where | `data-testid` values |
|---|---|
| Login | `challenge-form`, `challenge-code`, `challenge-recovery-toggle`, `challenge-recovery-code`, `challenge-submit`, `challenge-code-error`, `challenge-recovery-code-error`, `challenge-cancel`, `challenge-expired` (shown back on the password step after a 401) |
| Menu | `user-menu-account` (inside the opened user menu) |
| Page | `account-page`, `security-card`, `two-factor-status` (its text holds "Activée"/"Désactivée" or "On"/"Off"), `recovery-codes-left` |
| Set up | `two-factor-setup-open`, `two-factor-password`, `two-factor-password-error`, `two-factor-setup-submit`, `two-factor-qr` (an `svg` or `img` with a text alternative), `two-factor-secret` (the secret in groups of four), `two-factor-code`, `two-factor-code-error`, `two-factor-confirm`, `two-factor-error` |
| Recovery codes | `recovery-codes` (the list), `recovery-code-<n>` (n from 1), `recovery-copy`, `recovery-download`, `recovery-saved` (the checkbox), `recovery-done` |
| Disable, renew | `two-factor-disable-open`, `two-factor-disable-dialog`, `two-factor-disable-confirm`, `two-factor-disable-cancel`, `two-factor-codes-open`, `two-factor-codes-dialog`, `two-factor-codes-confirm`, `two-factor-codes-cancel` (the dialogs use `two-factor-password` and `two-factor-password-error` too) |
| Users card | `user-two-factor-<n>` (the chip, `data-enabled` = `true`/`false`), `user-reset-two-factor-<n>`, `user-reset-dialog`, `user-reset-confirm`, `user-reset-cancel`, `user-reset-error` |

Words checked by tests, French first: the pill "Activée" / "On" and "Désactivée" / "Off"; the chip
"Double authentification activée" / "Two-factor on" and "Sans double authentification" / "No
two-factor".

## Rules checked by tests

1. Every scenario of the eight endpoint files, one test per scenario.
2. **Sign-in:** a user with two-factor never gets a session from `POST /auth/login`; `GET /auth/me`
   answers 401 until the challenge succeeds; `last_login_at` changes only at the challenge; the
   session id changes at the challenge; a pending sign-in older than 5 minutes is 401; the fifth wrong
   code ends it; a pending sign-in cannot be used from a body that names another user.
3. **Replay:** a code accepted once is refused the second time, at sign-in and at confirmation; a code
   of the previous or next period is accepted once.
4. **Recovery codes:** eight, the right shape, shown once, stored only as keyed hashes; each works
   once; renewing kills the old ones; no code, secret or hash in any answer, log or mail.
5. **Re-authentication:** setup, disable and renewal refuse a wrong password with 422; a stolen open
   session without the password cannot change the second factor.
6. **Isolation:** `DELETE /users/{user}/two-factor` answers 404 for another institution's user, the
   same as an unknown one, and 403 for a manager.
7. **Operator command:** resets a live user, writes a log line without the address, exits 1 for an
   unknown or not-enabled user.
8. **No id leaves the server:** the id-leak test passes on the new routes; no `two_factor_*` column
   and no numeric key in any response.
9. Accessibility check on the login code step, `/admin/account` and the dialogs at 320 and 1280 px, in
   both languages.

## Acceptance tests

Written before the code, committed on the branch before the code, **not edited during coding**. If one
seems wrong, stop and say why.

| Where | Checks |
|---|---|
| `api/tests/Acceptance/Slice04b/` | One file per endpoint (`LoginTwoFactorTest` for scenario 10, `TwoFactorChallengeTest`, `TwoFactorStatusTest`, `TwoFactorSetupTest`, `TwoFactorConfirmTest`, `TwoFactorDisableTest`, `TwoFactorRecoveryCodesTest`, `ResetUserTwoFactorTest`), plus `OperatorResetTest` and the `two_factor_enabled` test of the users list |
| `api/tests/Support/` | `Totp.php` (computes a code for a secret and a period, base32, no library), helpers in `Accounts.php` to turn the second factor on for a user, `Tenancy.php` lists, `Pest.php` block for the new folder |
| `web/e2e/slice04b/` | `twofactor.spec.ts` (set up from the account page, sign in with a code, with a recovery code, wrong codes, turn off, renew, owner reset), `quality.spec.ts`. Helpers `web/e2e/support/totp.ts` |

All earlier tests keep passing. The slice 04 `ListUsersTest` key list gains `two_factor_enabled`
(updated with these tests).

## Out of scope

- Changing one's own name, email or password while signed in; "remember this device";
  WebAuthn or SMS; making the second factor mandatory for owners.
- Email notices when the second factor is turned on or off (a later hardening slice).
- Ending other sessions when the second factor is turned on, off or renewed.
- The audit log of these actions (slice 17).

## Done when

1. `make check` passes entirely, with every earlier test.
2. A person does the whole path in a browser, in French and in English, at desktop and phone width:
   set up with a real authenticator application, sign out, sign in with a code, sign in with a recovery
   code, renew the codes, turn it off; an owner resets another user.
3. The isolation suite fails when a route, a model or a table is added without a test.
4. `docs/api/openapi.json` and `schema.d.ts` are up to date.
5. CI passes; no reviewer finding is left open. Review: `correctness-reviewer` and `security-reviewer`
   (with the id-leak checklist); the replay rule, the pending sign-in and the recovery codes are the
   places to read hardest.

## Decisions taken in this brief

Confirmed by Jun on 2026-10-08.

- **Where:** a new page "Mon compte", reached from the user menu.
- **Lockout:** an owner can turn off another user's second factor; for a locked-out last owner the
  platform operator runs `auth:reset-two-factor`. No reset link by email (it would weaken the factor).
- **Password:** asked again to set up, to turn off and to renew the recovery codes.

Proposed here, for Jun to veto at the checkpoint: eight recovery codes of ten characters; five wrong
codes end the pending sign-in; the pending sign-in lasts five minutes; open sessions stay open when the
second factor changes; the platform admin can use two-factor too; a person cannot reset their own
through the owner endpoint.
