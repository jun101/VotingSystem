# Slice 18c (server) — done

## Built

- `POST /api/v1/platform/institutions/{institution}/impersonate` and `DELETE /api/v1/platform/impersonation`
  (`ImpersonationController`, action `ImpersonateInstitution`, helper `App\Support\Platform\Impersonation`).
- Session design: the guard's login key is the institution's oldest owner (lowest id, not removed), so policies, the
  tenant scope and `Auth::user()` are the owner's with no change to the guard. The real admin is kept in the session
  (`impersonator_id`, `impersonator_uuid`, `impersonator_name`). Start and end log the user in through the guard, which
  regenerates the session id; the CSRF token is regenerated too. Sign-out and the other session endings invalidate the
  whole session. Nothing is held in static or singleton state: the request's session is read on each call.
- Starting does not touch the owner's `last_login_at`, the failure counter or any two-factor field; no email is sent.
- Checks of the start: 401, 403 role (`platform.admin:acting`, which also lets an admin who is acting through so that
  the answer is 409 and not 403), 404 binding, 429 (`platform-write`, keyed on the real admin's uuid while acting), 409
  `already_impersonating`, 403 `institution_suspended`. The end sits behind `auth` only and answers 409
  `not_impersonating` when the session is not acting; it works for a suspended institution. If the admin's row is gone,
  the whole session is ended (401).
- Audit: while acting, `AuditLog::record` writes `user_id` null, `actor_name` "<admin> (plateforme)" and
  `details.impersonated_by`. `platform.impersonation_started` and `_ended` are written with the institution's id
  (new optional `institution` argument of `record`), subject the institution, details `{by}`. The scheduler path
  (`system: true`) and the platform suspend/reactivate entries are unchanged.
- `UserResource` adds `impersonating: {by}` for the session user while acting (`GET /auth/me`, the start, the other
  endpoints that return the current user); the end returns the admin's plain body.
- `make generate` run; `make lint-api` clean.

## Tests that look wrong (not edited)

`api/tests/Acceptance/Slice18/ImpersonationTest.php`, three tests fail before reaching what they check, for reasons in
the tests themselves:

- "lets the admin act as the institution's owner" and "names the admin on every entry written while acting": they
  `POST /elections` with `['title' => ...]` only; `starts_at` and `ends_at` are required (`CreateElectionRequest`) so
  the answer is 422, not 201.
- "has the owner's whole power, account security included": after `DELETE /users/{manager}` it reads the row with
  `Accounts::userRow($email)`, but a removed user's email is rewritten to `removed-{uuid}@removed.invalid`
  (`RemoveUser`), so the row is not found. `Accounts::userRowByUuid($t['a']['manager']['user'])` is the lookup that works.

With those three lines corrected in a scratch copy of the file (deleted afterwards), the file passes: 21 tests.

## Fix pass (security review, 2026-10-12)

- **Bound to the owner**: the session keeps `impersonated_owner_id` beside the admin's keys; `Impersonation::by()` answers
  only while the guard's user is that owner. A listener on the `Login` event (`AppServiceProvider`) clears the keys on
  every sign-in, registration and invitation acceptance; `start()` puts its keys after its own login, so it is not hit.
- **Admin's password**: a fingerprint of the admin's password hash is stored at the start (`Impersonation::fingerprint`).
  The middleware `EndStaleImpersonation` (in the `cookie-session` group, after `AuthenticateSession`) ends the session
  (logout, flush, 401) when the admin is gone, is no longer a platform admin, or the fingerprint no longer matches.
- **`platform.impersonation_action`**: `Impersonation::note($what, $subject)` writes one entry while acting
  (`{what, impersonated_by, impersonated_by_uuid}`) from `UserController::destroy` (`user.removed`), `resetTwoFactor`,
  `InvitationController::store` (`invitation.created`), `InstitutionController::update` (`institution.updated`),
  `LogoController::update` and `destroy` (`institution.logo_changed`), and `TwoFactorController::setup`, `confirm`,
  `disable`, `recoveryCodes` (`two_factor.changed`; `confirm` is added to the list of the brief since it is what turns the
  factor on).
- **Password confirmations**: `ConfirmOwnPassword` checks the admin's password and counts failures, lock and 5th-failure
  logout on the admin's `PasswordFailures` while acting.
- **Limiter**: `DELETE /platform/impersonation` has no rate limiter; start, suspend and reactivate keep `platform-write`
  keyed on the real admin.
- **`details.impersonated_by_uuid`**: the admin's uuid, written by `AuditLog::record` beside `impersonated_by`.
- `make generate` run (limiter text of the end endpoint); `make lint-api` clean.

### Tests that look wrong (not edited)

- "asks for the admin's own password on account-security routes ...": the admin (`platformAdmin()`) and the owner
  (`Team::two()`) are both created with `Accounts::PASSWORD`, so the owner's password is also the admin's and the first
  call cannot answer 422.
- "ends even after sixty requests of the hour were used": 1 start + 60 loop starts is 61 `platform-write` hits, so the
  61st start answers 429, the admin is not acting and the last DELETE answers 409 (the limit of 60 stays by the
  contract). The loop should run 59 times.
- "names the admin on every entry ..." and "writes an entry naming the admin ...": they read `details.impersonated_by_uuid`
  through `GET /audit`, and `tests/Support/IdLeakScanner` (run on every response) refuses any key ending in `_id`, so
  these two tests cannot both pass; the key `impersonated_by_uuid` is the one asked for by the brief and stays.
