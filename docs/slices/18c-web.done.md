# Slice 18c (web): done

The platform admin enters an institution from `/platform`, sees a banner on every admin page while acting, and ends it.

## Built

- **Card** (`InstitutionCard`): "Se connecter en tant que" / "Sign in as" (`platform-impersonate-{n}`) on active cards only.
  `PlatformPage.enter` calls `POST /platform/institutions/{id}/impersonate`, then a full page load of `/admin`
  (`lib/navigation.ts` `loadPage`). Errors in words in `platform-impersonate-error` (403 suspended, 409, 429 with the wait).
  A search not yet sent is dropped and one under way is waited for before the call: its late answer would otherwise
  carry the old session cookie back and undo the sign-in.
- **Banner** (`components/admin/ImpersonationBanner.tsx`, shown by `AdminShell` while `user.impersonating`):
  `impersonation-banner` (warm tokens, icon, institution, admin's name, audit-log text) and `impersonation-end`
  (`DELETE /platform/impersonation`, then a full load of `/platform`; a 409 `not_impersonating` ends the same way).
  Stacks on phone, button full width, text breaks long names.
- **Layout**: `/admin` sends a platform admin to `/platform` only when not acting.
- **Types**: `CurrentUser` gains `impersonating?: {by}` (the generated `auth.me` schema does not carry the
  conditional field).
- **Messages**: `platform.impersonate`, `admin.impersonation.*`, `errors.already_impersonating`,
  `errors.not_impersonating` (fr and en).

## Results

`make lint-web` clean (the one existing warning), `make test-web` 625 pass, `make generate-check` clean,
`e2e/slice18` desktop 8 pass, phone 8 pass (repeated runs, run alone).

## Notes

- A few runs failed while other suites were probably running on the same stack; they passed when run alone.
- The banner shows no time left: Jun's decision is that there is no time limit.
