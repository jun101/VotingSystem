# Slice 19c, step A (school mode in the application): done

Branch `slice/19c-installers`. Brief: [19c-installers.md](19c-installers.md).

## Built

- **Mode**: `config/deployment.php` (`DEPLOYMENT_MODE`, `online` default or `school`; another value stops the boot),
  `App\Support\Deployment`, passed to the API and web containers by `compose.yaml` and listed in `.env.example`.
- **Closed in school mode (404)**: register, verify email and its resend, forgot and reset password, accept invitation,
  list, create and cancel invitations. Middleware `deployment:online`.
- **Owner creates the team** (school mode only): `POST /users` (name, address as identifier, role, the owner's own
  password and second factor) returns `one_time_password`, shown once (`OneTimePassword`, 12 characters of the access
  code alphabet); `POST /users/{user}/password-reset` gives a new one and ends the person's sessions. In online mode
  `POST /users` stays "405 method not allowed", as before.
- **`POST /auth/change-password`** (both modes, new): current password, new password (12 to 128 characters, not the
  address, not the same), second factor when on; ends the other sessions, keeps this one.
- **`must_change_password`** (migration `2026_10_22_100001`): a user with a one-time password can only reach
  `GET /auth/me`, logout, CSRF and change-password; everything else answers 403 `password_change_required`
  (`EnforcePasswordChange`, in the `cookie-session` group). A platform admin acting as that user is not held by it.
  `GET /auth/me` carries `must_change_password: true` only while it applies.
- **No email channel for codes**: `email: true` and the scope `failed_email` answer 422 `email: unavailable` on credential
  runs and on reissue.
- **Cookies**: the voter cookie is `Secure` when the request is HTTPS, and outside local development unless school mode;
  `config/session.php` defaults `Secure` off in school mode.
- **Web**: create-member form and the one-time password shown once with copy (`CreateMemberForm`, `IssuedPassword`,
  in `UsersCard`), "New password" per member, the change-password card on the account page, the forced-change screen
  (admin layout), the "unencrypted connection" notice, login without the sign-up and forgot links, the sign-up and link
  pages answer 404, the email channel and its counts hidden in the send dialog, no invitations list. The edition is read on
  the server (`lib/deployment.ts`) and given to client components by `DeploymentProvider`.

## Results

API: 25 folders green, Slice19c 34 tests; the tenant-isolation route list names the three new routes. API lint clean
(Pint, PHPStan), `make generate` run (openapi and schema updated). Web: unit 636, lint (the old warning only), `tsc`.

## Notes and what is left

- No browser spec for school mode yet: accounts cannot be registered in school mode, so it needs `owner:create` (step B).
- `style` of the new components follows the existing tokens; no new colour.
- Not covered by a test: the two-factor variants of create, reset and change-password (the shared second-factor check is
  the one the two-factor reset already tests).
- Step B adds `owner:create`, `user:reset-password` and `votectl`.
