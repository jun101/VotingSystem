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
