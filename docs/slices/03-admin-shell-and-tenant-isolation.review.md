# Slice 03 — Review

Four reviewers, 2026-10-07, on commit 71381fa: correctness, security, modernity, id-leak.
Status: **Open** until fixed or decided.

## Id-leak

No finding. Traced: `PATCH /auth/me` and `UserResource`, the scope, the policy and the user
provider (the numeric `institution_id` stays in SQL and server-side comparisons), the
generated OpenAPI file and TypeScript types, the web proxy, API clients and admin components
(keys, data attributes, links, forms). No migration, email, file name or log line was added.
Not read: `ApiResource` and `InstitutionResource` (not in the diff), the test support code.

## Modernity

No dependency, lock file, Dockerfile or Compose file changed. No finding. Not checked: whether
the versions already in use are the latest, and the `useEffect`/`useContext` calls against
`use(Context)`.

## Security

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| S1 | Should fix | `Models/Concerns/BelongsToInstitution.php` (`creating`) | An `institution_id` set by code is kept even when a signed-in user of another institution creates the record; only the column not being fillable protected it. Throw `LogicException` when the named institution differs from the signed-in user's | Fixed |
| S2 | Note | same (`resolveChildRouteBinding`) | The "not a UUID finds nothing" guard covered top-level bindings only; a nested `scopeBindings()` child went to SQL with the raw value. Same guard on the child and soft-deletable child bindings | Fixed |
| S3 | Note | same (model events) | The rules are model events; `query()->update()`, `insert()`, `upsert()` and `DB::table()` skip them. Source-grep test added, limit written in `architecture.md` section 4.4 | Fixed (limit documented) |
| S4 | Note | `Tenancy.php` coverage lists | The coverage test checked that routes are listed, not that they carry `auth` and `institution.active`. New test requires both; `POST /auth/logout` has `auth` only, so a suspended institution's user can still sign out | Fixed |

Checked and correct: the scope keeps no state and reads the guard each time (Octane flush
listeners and `ResetAuthState` in place); every `withoutInstitutionScope()` call has its
reason; queued-model restore; `PATCH /auth/me` (only `language`, `in:fr,en`, `auth`,
`institution.active`, CSRF, no log); the CSRF order change; `proxy.ts` overwrites a
client-sent `x-admin-area`; no `dangerouslySetInnerHTML`; menu links are fixed.

## Correctness

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| C1 | Should fix | `LoginForm.tsx`, `app/layout.tsx` | After sign-in `router.push('/admin')` keeps the root layout, so `<html lang>` and the `Accept-Language` of the API client stay on the browser's language, not the stored one. Fixed with `router.refresh()` after the push | Fixed |
| C2 | Should fix | `AdminShell.tsx` | If the drawer is open when the screen reaches `lg`, the content stays `inert` while the controls disappear. The drawer now closes at `lg` | Fixed |
| C3 | Should fix | `SideMenu.tsx` | Found by the browser tests: on a short phone screen the "new election" control was squashed to 20 px (needs 44 px). `shrink-0` on the flex children | Fixed |
| C4 | Note | `BelongsToInstitution.php` | Same as S1 | Fixed |
| C5 | Note | same | Same as S2 | Fixed |
| C6 | Note | same | Same as S3 | Fixed (limit documented) |
| C7 | Note | `bootstrap/app.php`, `docs/api/README.md` | CSRF now runs after `auth`: a signed-out request without a token gets 401, not 419, on endpoints needing a user. One sentence added to the API README; tested | Fixed |
| C8 | Note | `TenantPolicy.php`, `User.php` | Strict integer comparison with no cast on `institution_id`. Cast added | Fixed |
| C9 | Note | acceptance tests | `TenantScopeTest`, `TenantCoverageTest` and `shell.spec.ts` were corrected after the code commit; the `toBeArray()` assertion added to `TenantCoverageTest` cannot fail. Edits were made by the test author, not the coder; recorded in the summary | Accepted |

Checked and correct: `PATCH /auth/me` against its file; pre-sign-in lookups remove the scope
by name with a comment; emails follow `preferredLocale()`; French and English message files
have the same keys; reduced motion keeps the closed drawer hidden; the search ignores case and
accents.
