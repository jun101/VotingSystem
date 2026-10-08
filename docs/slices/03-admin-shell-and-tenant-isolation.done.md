# Slice 03 — Admin shell and tenant isolation: what was done

2026-10-07 · branch `slice/03-admin-shell-and-tenant-isolation`

## What you can do now

1. `make up`, sign in at http://localhost:8080/login. You land in the admin shell: a side menu
   with a search ("go to"), a top bar with the user menu, and an empty dashboard of cards.
2. Open each menu entry. On a phone the menu is a drawer; it closes when you choose an entry,
   press Escape, or widen the screen.
3. Switch to English and back in the top bar. The choice is stored on your user, kept after a
   reload and after signing out and in again, and `<html lang>` follows it.
4. Behind the screen: a user of one institution cannot read or change another institution's
   record. It answers 404, the same as a record that does not exist.

## What was built

| Part | Content |
|---|---|
| Tenant isolation | `BelongsToInstitution` trait and `InstitutionScope` (read from the request each time, fail closed with no user or for a platform admin); `withoutInstitutionScope()` as the only way out, each call commented and checked by a test; route binding by UUID (top-level and nested), `TenantPolicy`; a session user provider so the lookup of the signed-in user works through the scope |
| API | `PATCH /api/v1/auth/me` (language only), exactly as `docs/api/auth/PATCH-auth-me.md`; CSRF now runs after `auth` |
| Tests | A test-only tenant table and routes prove the isolation before any real tenant model exists; a coverage test fails when a route is not listed, or lacks `auth` and `institution.active`; source checks reject scope removal and builder writes of `institution_id`; an Octane test for no leak between requests |
| Web | Admin layout, side menu, top bar, dashboard cards, language switch, mobile drawer, French and English messages |

## Decisions along the way

- `POST /auth/logout` has `auth` but not `institution.active`: a user of a suspended
  institution can still sign out (its endpoint file has no 403).
- A create with another institution's id named by code throws; with nobody signed in
  (sign-up) or a platform admin, the explicit id is kept.
- Builder-level writes (`update`, `insert`, `upsert`, `DB::table`) skip the model rules. A
  source test guards against `institution_id` in them; the limit is in `architecture.md`
  section 4.4 for later slices.

## Changed along the way

- Acceptance tests corrected by the test author after the code was written, none of them
  weakened in intent: `TenantScopeTest` (the exception type is `LogicException`),
  `TenantCoverageTest` (an empty route list is checked with an extra assertion, which always
  passes and only stops the test being flagged as empty), and two browser tests in
  `shell.spec.ts` (the drawer is closed between entries; a fresh load before the skip-link
  check).

## Review

Four reviews (correctness, security, modernity, id-leak): 4 security findings (1 should-fix),
9 correctness findings (3 should-fix, with overlaps). All fixed or documented; C9 accepted.
No id leak, no modernity finding. See `03-admin-shell-and-tenant-isolation.review.md`.

## Checks

- `make check` passes: 317 API tests, 124 web unit tests, 258 browser tests (6 skipped, each
  for the other screen size), generated files up to date, build and audit.
- A first run showed 21 failures that were all "connection refused": the database container
  restarted during the run. The rerun was clean. The browser step then waited on the API
  health limiter (429 for about 14 minutes), which is a development-stack quirk.
- The browser tests caught two real bugs the reviewers had not all seen: the language on the
  page after sign-in, and a control squashed to 20 px on a short phone screen.
- **Not verified:** the screens compared with the mockups by eye (Sidebar and Dashboard
  mockups); this is for you at the checkpoint. The Redis rate limiter at real limits and the
  home page's first-load JavaScript are still open from slice 02.

## Next

Slice 04: profile and users (profile and logo, invite a manager, remove a user, two-factor
authentication). Before it: your checkpoint on the pages above, the pull request and the merge.
