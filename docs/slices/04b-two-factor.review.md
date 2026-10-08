# Slice 04b — Review

Three rounds of two reviewers, 2026-10-08: correctness and security (the security reviewer also applied the
id-leak checklist; modernity and id-leak reviewers are reserved for slices 02, 03 and 12). Round 1 on commit
acdd583, round 2 on e5064cd, round 3 on 17dabd6. Status: **Open** until fixed or decided.

## Round 1 (acdd583)

### Security

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| S1 | Blocking | `PendingSignIn`, `LoginController`, limiters | The wrong-code counter lived in the session and was reset by every password step; only the per-IP limits applied, so someone with the password could guess codes with no per-account ceiling (about 333,000 guesses on average) | Fixed: 5 wrong codes per 15 minutes per account, in the cache, atomic, 429 from the sixth even for a right code; the password step does not reset it |
| S2 | Should fix | `CompleteTwoFactorChallenge`, `LoginController` | The pending sign-in was not tied to the password and the session id was not regenerated at the password step | Fixed: it records a hash of the password hash (401 if it changed), the session is regenerated with the old one destroyed, a signed-in user is logged out, a sign-in without two-factor ends any pending one |
| S3 | Should fix | `TwoFactorController` | Wrong passwords on setup, disable and renewal were never counted | Fixed: one counter per account, the fifth wrong password ends the session (401) |
| S4 | Note | `UserController` | The owner's reset asked for no password | Fixed: `POST /users/{user}/two-factor/reset` with the owner's password (and the owner's second factor when the owner has two-factor on) |
| S5 | Note | `CompleteTwoFactorChallenge` | No length limit on the challenge body | Fixed: over 32 characters or not a string is a counted wrong code, never hashed |
| S6 | Note | `TwoFactorController` | Two confirmations at once could return two sets of recovery codes | Fixed: confirm and renewal under the user's row lock |

### Correctness

Overlaps with the security findings (wrong-code counter races, the owner reset, the lock) are fixed with them.

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| C1 | Should fix | `RecoveryCodes.tsx` | Only `beforeunload` warned that the codes are lost; in-app navigation did not | Fixed: a confirmation before an application link or the sign-out while the list is shown. Browser back/forward are covered by `beforeunload` only (the router cannot be intercepted cleanly) |
| C2 | Should fix | `UsersCard.tsx` | Focus was lost after a reset or a removal (the opener disappears) | Fixed |
| C3 | Should fix | `LoginForm.tsx` | Focus was lost after "Annuler" on the code step | Fixed: focus on the password field on every return |
| C4 | Should fix | `PasswordDialog.tsx` | A double Enter sent two requests | Fixed |
| C5 | Note | `TwoFactor.php` | The otpauth label encoded the colon (`%3A`) instead of the literal one of the endpoint file | Fixed |
| C6 | Note | requests | Confirm answered `code: string` or `code: max` for malformed values instead of `invalid` | Fixed |
| C7 | Note | openapi | The 403 and 409 responses describe `{message}` instead of the real `{error: {code, message}}` | Deferred: older than this slice, the generator has to learn the error shape |

## Round 2 (e5064cd)

| # | Severity | Problem | Status |
|---|---|---|---|
| R2-1 | Should fix | Login is also a way to test a password: no per-account limit across addresses, so a stolen session could find the password and then turn the second factor off | Fixed in two steps: wrong passwords at login, from any address, feed the same per-account counter as the settings routes (sign-in itself is never refused by it); the third round then closed it for good (see R3-1) |
| R2-2 | Should fix | After the lockout every attempt, even with the right password, ended the session | Fixed: the fifth wrong password ends the session; later attempts answer 429 with `Retry-After` and keep the session |
| R2-3 | Should fix | The step-one regeneration kept the old stored session; a pending sign-in survived a later non-2FA sign-in | Fixed |
| R2-4 | Should fix | Setup wrote the secret outside the row lock; confirm lost its 409 in the generated contract; the setup and scan steps could be submitted twice | Fixed |
| R2-5 | Note | The failed-password count was cleared for the challenge's address, not the login's | Fixed: the key of the address the password was typed from is kept in the pending state |
| R2-6 | Note | The account's wrong-code count was not cleared when two-factor was cleared | Fixed (own turning off, owner reset, operator command) |

## Round 3 (17dabd6)

### Security

| # | Severity | Problem | Status |
|---|---|---|---|
| R3-1 | Blocking | The shared counter is a fixed 15-minute window: someone with a stolen session can guess the password at sign-in from many addresses, wait the window out, then turn the second factor off with the right password | Fixed: turning two-factor off and renewing the recovery codes need the password **and** a current second factor (code or recovery code); the owner's reset needs the owner's own second factor when the owner has two-factor on. Wrong second factors count in the same account counter as wrong codes at sign-in |
| R3-2 | Should fix | A password reset cleared the wrong-code counter: someone with the mailbox gets more guesses (about 35 an hour instead of 20) | Fixed: a password reset clears nothing; the counts run out by themselves |
| R3-3 | Note | Anyone who knows only an email address can keep the settings routes at 429 and cut a person's session by feeding the shared count | Accepted and written in the endpoint files: sign-in is never refused by it |
| R3-4 | Note | Responses carrying the secret and the recovery codes had no `Cache-Control: no-store` | Fixed |
| R3-5 | Note | The fixed window allows about 10 guesses across a window edge, and a success clears the count (odds about 0.14 % a day under constant attack) | Accepted, documented as a fixed window |

### Correctness

| # | Severity | Problem | Status |
|---|---|---|---|
| R3-6 | Should fix | Two Feature tests could not fail (one logged out before checking; one tested half its title) | Fixed |
| R3-7 | Should fix | The 429 message said "try again in a moment" for a wait that can last 15 minutes | Fixed: the message shows the wait in minutes from `Retry-After` |
| R3-8 | Note | A misleading comment about the session at the password step; an empty password while locked answers 422 before 429 | Fixed (comment); order written in the endpoint files |
| R3-9 | Note | A reset of oneself asked for the owner's second factor before the 409 | Fixed in the endpoint file: for oneself the 409 comes right after the password |

Checked and correct in the last round: the shared counter, `Retry-After`, no double counting, logins refused by the
per-address limiter not feeding the counter, the clearing rules, the transaction and lock code, the tenant
isolation of the reset route, the operator command, the QR drawn in the browser, the downloaded text file, the
logs (outcomes only), and no numeric database id anywhere.
