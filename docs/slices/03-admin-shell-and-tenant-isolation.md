# Slice 03 — Admin shell and tenant isolation

Brief · 2026-10-07 · branch `slice/03-admin-shell-and-tenant-isolation`

## Goal

A signed-in person sees the real admin area: the side menu, a top bar and an empty
dashboard, and can switch between French and English. Behind it, the server enforces that
an institution can never read or change another institution's data, and a test suite proves
it and fails when a new route is added without being covered.

Slice 02 left the `users` table with an `institution_id` and no scope on it. This slice
adds the shared institution scope, its route binding and policy layers, and the test
machinery every later slice extends. It is the last slice before tenant data (elections,
ballots, voters) starts to exist, so the machinery has to work **before** there is any
tenant model beyond `User`: the acceptance tests use a test-only tenant table to prove it.

At the end, a person signs in at `http://localhost:8080/login`, lands in the admin shell,
opens each menu entry, switches to English and back, and sees their language kept after a
reload. In the tests, a user of institution B gets 404 on every record of institution A.

## Requirements covered

FR-INST-05, FR-NAV-02 (side menu; the "go to" search over pages only, elections and voters
come with their slices), FR-NAV-03 (desktop first, usable on a phone), NFR-SEC-03,
NFR-UX-01 (admin language switch, stored on the user). FR-NAV-04 is respected by the
dashboard (cards, no table).

## Read before coding

[architecture.md](../design/architecture.md) sections 4.2, 4.4 and 4.5 ·
[database.md](../design/database.md) sections 1 and 2.1 ·
[frontend.md](../design/frontend.md) sections 2, 3, 4, 5 and 8 ·
[API conventions](../api/README.md) · [PATCH /auth/me](../api/auth/PATCH-auth-me.md) ·
[GET /auth/me](../api/auth/GET-auth-me.md) · the mockups
[Sidebar.dc.html](../design/mockups/Sidebar.dc.html) and
[Dashboard.dc.html](../design/mockups/Dashboard.dc.html) (the shell and the top bar) ·
the slice 02 review file, for the points it left for this slice.

## What to build

### 1. Tenant isolation, server side

| Item | Requirement |
|---|---|
| Scope | One global scope and one trait (`App\Models\Concerns\BelongsToInstitution`). The scope adds `WHERE institution_id = <current institution>` to every query, relations included; the trait fills `institution_id` on insert (and throws when there is no signed-in user with an institution to fill it from) and throws a `LogicException` on saving a record whose `institution_id` was changed |
| Where the institution comes from | **The current request, read each time** (rule 11): the signed-in user's institution. Nothing in a singleton or a static property. A request with no signed-in user, or a platform admin (no institution), **gets no rows** (the scope fails closed) |
| Removing the scope | Only by one **static** method on the trait, `Model::withoutInstitutionScope()`, which returns a query builder without the scope, and every call site carries a comment saying why. A test greps the code base and fails on a call without a comment line directly above it, and on any other way of removing the scope (`withoutGlobalScopes()`, `withoutGlobalScope(` on a tenant model) |
| `User` | Uses the trait. The places that must cross tenants because nobody is signed in yet (sign-in lookup by email, registration's duplicate check, the verification and reset token flows, the session guard's user retrieval) call `withoutInstitutionScope()` with their reason. Behaviour and status codes of the nine slice 02 endpoint files do not change |
| `Institution` | It **is** the tenant, so it has no scope. Anything that reads an institution reads the signed-in user's own (`$user->institution`), never by a request value |
| Route binding | A shared way to bind a route parameter through the scope, so another institution's `uuid` answers **404 `not_found`**, the same body and headers as a `uuid` that does not exist. A parameter that is not a UUID also answers 404 |
| Policies | An abstract `App\Policies\TenantPolicy` with `belongsToUsersInstitution(User $user, Model $record): bool`: true only when the user has an institution and it is the record's; false for a platform admin. Every later resource policy extends it. A record of another institution is a **404, never a 403** (API README section 4) |
| Octane | Test that a request signed in as user A of institution A, followed by one signed in as user B of institution B on the same worker, never sees A's institution, and that a request with no user after them sees none |
| Logs | Nothing new is logged, and no log line holds an institution's name or a user's email |

### 2. API

| Item | Requirement |
|---|---|
| New endpoint | [PATCH /auth/me](../api/auth/PATCH-auth-me.md): the user changes their own `language`. Under the same `cookie-session` group, CSRF and `institution.active` middleware as `GET /auth/me`. Behaviour and codes exactly as its file |
| Current-user resource | Unchanged; the `language` it returns is the stored one |
| Emails | The two slice 02 notifications use the user's stored language, so a switch applies to the next email |
| Generated files | `docs/api/openapi.json` and `web/src/lib/api/schema.d.ts` regenerated by the existing command, never by hand |

### 3. The tenant test suite (the main product of this slice)

Written before the code, extended by every later slice. Its parts:

| Part | What it does |
|---|---|
| Fixtures | A helper that builds two institutions, each with an owner, a manager and (once models exist) one record of each tenant model. Two test-only tables and models (`tenant_probes`, `tenant_probe_notes`), created by a migration that exists **only in the test environment**, stand in for tenant data until slice 05 |
| Test-only routes | Registered by the test case, not by the application: list, show, create, update and delete a probe, and one nested route (`probes/{probe}/notes/{note}`), all bound through the shared binding and policy |
| Behaviour checks | For the probes: the list shows only the caller's rows; show, update and delete of the other institution's `uuid` answer 404 with the **same body and headers** as an unknown `uuid`; create fills the caller's institution and ignores a body that names another; a nested record cannot be reached through a parent of the other institution; a total in a list counts only the caller's rows |
| Scope checks | A model query with no user returns nothing; a platform admin gets nothing from tenant models; changing `institution_id` on a saved record throws |
| **Route coverage test** | Walks every registered route under `/api/v1`. Each must be in the explicit list of public routes (`/health` and the pre-sign-in `/auth/*` routes) **or** be covered by a tenant test that names it. A route in neither list fails the suite, with the route name in the message. This is what makes later slices extend the suite |
| Model coverage test | Every model in `app/Models` that has an `institution_id` column in the schema must use the trait; a model with the column and no trait fails. A model listed as tenant-free needs a one-line reason in the list |
| Table coverage test | Every table in the schema that has an `institution_id` column is either used by a model with the trait or listed with a reason |

The route and model lists live in `api/tests/Support/Tenancy.php`. Adding a route or a
tenant model without extending that file makes `make check` fail on purpose.

### 4. Web

| Item | Requirement |
|---|---|
| Admin shell | `(admin)/admin/layout.tsx`: fixed side menu of 272 px from `lg`, a drawer below it (opened from a menu button of the top bar, closed by Escape, a tap outside or choosing an entry, with focus moved in and back), the top bar, and the content area that fills the width. Replaces `AdminPlaceholder` |
| Side menu | The mockup "Menu latéral": institution name and initials, the "go to" search, the "Nouvelle élection" button, the **Établissement** section (Tableau de bord, Élections, Établissement, Journal d'audit), the "Élection choisie" card and the **Cette élection** section. With no election yet (always true in this slice) the card shows an empty state ("Aucune élection choisie") with a link to Élections, and the election section is not shown. The user's initials, name, role and the FR/EN switch at the bottom. A tone of navy from the tokens; the selected entry in `navy-raised`; no raw colour in a component |
| Routes | `/admin` (dashboard), `/admin/elections`, `/admin/institution`, `/admin/audit`, each inside the shell. The three that belong to later slices render a shared, polite "pas encore disponible" page in the shell, so no menu entry is a 404 and the three-click rule can be tested. `/admin/elections/new` is the target of "Nouvelle élection" and renders the same page |
| Dashboard | Empty state of FR-NAV-01: a welcome with the user's name and the institution, and cards (not a table) for each block the dashboard will hold (open election, to-do, key figures, recent activity, latest elections), each with an honest empty message and, where an action exists, a link ("Créer une élection"). No number invented: nothing is counted yet. The verification banner of slice 02 stays, under the top bar |
| "Go to" search | Filters the menu entries by their translated label as the person types; Enter or a click opens the first match; `/` focuses it from anywhere in the shell unless a field has focus; no network call |
| Top bar | Page title, menu button below `lg`, the verification state, and the user menu: name, role, sign out |
| Language switch | The FR/EN control calls PATCH /auth/me, then updates the page without a full reload. The choice survives a reload and a new sign-in (it is the stored language). Failure shows an inline error and keeps the old language. The shell, the dashboard and every message follow it |
| Language at load | The admin pages render in the **stored** language of the user, not the browser's; `<html lang>` matches. The pages before sign-in keep following the browser (slice 02) |
| Guarding | As slice 02: not signed in → `/login`; suspended institution → `/login` with the existing message. A platform admin on `/admin` is out of scope (slice 18): they are signed out with a clear message rather than shown an institution area |
| Responsive and keyboard | Usable from 320 px; every control reachable by keyboard with a visible focus ring; skip link to the content; the current menu entry has `aria-current="page"`; the drawer traps focus while open |
| Motion | Reveal on entry of the dashboard cards, the menu's selected-entry transition and the drawer slide, all from the motion kit of slice 01b, all off under reduced motion. Nothing else |
| Messages | Every text in the French and English message files, no text in a component |
| Cleanup | `AdminPlaceholder` and its test are deleted; the slice 02 browser tests that targeted it are not edited by the coder (they are updated with the other acceptance tests, see below) |

### 4b. Names the browser tests rely on

`data-testid` values:

| Where | Values |
|---|---|
| Shell | `admin-shell`, `side-menu`, `menu-search`, `menu-new-election`, `menu-link-dashboard`, `menu-link-elections`, `menu-link-institution`, `menu-link-audit`, `menu-election-card`, `menu-user-name`, `menu-user-role`, `language-switch` (a button, `data-language` = the current one), `top-bar`, `top-bar-title`, `menu-button` (below `lg` only), `menu-drawer`, `user-menu`, `signout-button`, `skip-link` |
| Dashboard | `dashboard`, `dashboard-welcome`, `dashboard-institution`, `dashboard-card-open-election`, `dashboard-card-todo`, `dashboard-card-figures`, `dashboard-card-activity`, `dashboard-card-latest`, `dashboard-create-election` (link), plus the slice 02 `verify-banner`, `resend-button`, `resend-done` |
| Not yet available | `coming-soon` |
| Other | `menu-election-section` (the "Cette élection" section, absent in this slice), `menu-search-empty` (shown when the search matches nothing), `language-error` (shown when the switch fails) |

`user-menu` is a button that opens a small menu (Escape closes it and gives the focus back to the button); `signout-button` is inside it and is visible only while it is open. `language-switch` is in the side menu, so on a phone it is inside the drawer. A click on it changes to the other language.

Words the tests check (French first, then English), and no other copy:

| Where | French | English |
|---|---|---|
| `top-bar-title` and menu labels | Tableau de bord · Élections · Établissement · Journal d'audit | Dashboard · Elections · Institution · Audit log |
| `menu-user-role` of an owner | Propriétaire | Owner |
| `menu-election-card`, with no election | contains "Aucune élection choisie", and one link to `/admin/elections` | contains "No election selected", same link |
| `coming-soon` | holds "disponible" | does not hold "disponible" |

The search matches the translated label, ignoring case and accents. Each page has exactly one `h1`, and the top bar title is its text.

`admin-welcome`, `admin-institution` and `signout-button` of slice 02 keep working: the
first two are on the dashboard under the same names (`dashboard-welcome` and
`dashboard-institution` replace them; the slice 02 tests are updated with the acceptance tests), the last is
in the user menu.

## Rules checked by tests

1. **Every tenant query is scoped.** Behaviour checks of part 3 pass for list, show,
   create, update, delete and the nested route.
2. **Another institution's record is a 404 with the same body and headers as an unknown
   one**, for every route that binds a record.
3. **Fail closed.** No signed-in user, or a platform admin, reads no tenant row.
4. **The scope cannot be removed quietly**: the grep test of section 1.
5. **A new route, model or table without a tenant test fails the suite.**
6. **Octane**: nothing of one request's user or institution reaches the next.
7. **No id leaves the server**: the id-leak test runs on the new endpoint; no menu link, no
   URL and no data attribute holds anything but UUIDs.
8. The shell passes the automated accessibility check at 320 px and 1280 px, in French and
   English, and is usable by keyboard alone (drawer included).
9. A language switch is stored for the user and survives a reload and a new sign-in; it
   does not change the other institution's users.

## Acceptance tests

Written before the code, committed on the branch before the code and **not edited during
coding**. If one seems wrong, stop and say why.

| Where | Checks |
|---|---|
| `api/tests/Acceptance/Slice03/` | `PatchMeTest` (one test per scenario of its file); `TenantBehaviourTest`, `TenantScopeTest` (scope, fail-closed, Octane), `TenantPolicyTest`, `TenantCoverageTest` (routes, models, tables), `ScopeRemovalTest` (the source check) |
| `api/tests/Support/` | `Tenancy.php` and `Tenancy/` (the lists, the probe models and routes); `AuthClient` and `Accounts` extended (PATCH, a second user in an existing institution). `Pest.php` runs the slice 03 tests on a clean database with the two test-only tables |
| `api/tests/Support/Tenancy.php` | The lists the coverage tests read (written with the acceptance tests; later slices add to it) |
| `web/e2e/slice03/` | `shell.spec.ts` (shell, menu, search, drawer, keyboard, guards, two institutions), `language.spec.ts` (switch, persistence, failure, isolation), `quality.spec.ts` (accessibility at 1280 and 320 px in both languages, touch size, reduced motion). Helpers in `web/e2e/support/admin.ts` |
| `web/e2e/slice02/` | Updated with the acceptance tests where the placeholder's test ids moved (listed in the section 4b note). The coder does not touch them |

All the slice 01, 01b and 02 tests keep passing.

## Out of scope

- The profile, the logo, inviting and removing users, two-factor (slice 04).
- The real dashboard numbers (slice 14); the real elections, voters and audit pages
  (slices 05, 07, 17); the election card with a real election.
- The platform admin area and its shell (slice 18).
- A real tenant model: the probes of part 3 exist only in tests.
- Search over elections and voters.
- Absolute session lifetime (S9, slice 19).

## Done when

1. `make check` passes entirely, with the slice 01, 01b, 02 and 03 tests.
2. A person can do the whole path of the goal in a browser, in French and in English, at
   desktop and phone width.
3. The coverage tests fail when a route, a tenant model or a tenant table is added without
   a test (shown once by hand and noted in the summary).
4. `docs/api/openapi.json` and `schema.d.ts` are up to date with the code.
5. The CI workflow passes; no reviewer finding is left open. Review: `correctness-reviewer`,
   `security-reviewer`, `modernity-reviewer` and `id-leak-reviewer` (PLAN section 2).

## Decisions taken in this brief

All four confirmed by Jun on 2026-10-07.

- **The scope fails closed.** With no signed-in user it returns nothing, so the flows that
  run before sign-in must remove the scope on purpose, by name, with a comment. A little
  more ceremony in slice 02's code, in exchange for no tenant leak by forgetting.
- **Test-only tenant tables** prove the machinery now; the real tenant models of slice 05
  onwards join the suite and the coverage tests make that mandatory.
- **Menu entries of later slices open a "not available yet" page** inside the shell instead
  of being hidden, so the menu is the real one from day one and every entry is testable.
- **The language is stored on the user** (the `language` column slice 02 already has) and
  the admin follows it, not the browser.
- **A platform admin signing in to `/admin` is turned away** until slice 18 builds their
  own area.
