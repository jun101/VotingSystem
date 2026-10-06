# New Voting System — Front-end design

Version 1.0 · 2026-10-06 · goes with [SPEC.md](../SPEC.md) 1.3,
[architecture.md](architecture.md) and the approved mockups in [mockups/](mockups/).

Stack: Next.js (App Router), TypeScript in strict mode, Tailwind CSS.

## 1. Three areas, three budgets

| Area | Who | Designed for | Rendering | JavaScript |
|---|---|---|---|---|
| Voting flow | Voter | Phone first, from 320 px (NFR-UX-02) | Server, with small interactive parts | Under 150 KB for the first screen, all included (NFR-PERF-01) |
| Public pages | Anyone | Phone first | Server only, cached | Share buttons only |
| Admin area | Institution user, platform admin | Desktop first, usable on a phone (FR-NAV-03) | Browser, after sign-in | No fixed budget; loaded per page |

The three areas are separate route groups with separate layouts, so nothing from the
admin area is ever downloaded by a voter.

## 2. Routes

A UUID is the only kind of identifier in an address (NFR-SEC-08).

### Voter and public

| Address | Screen | Mockup |
|---|---|---|
| `/vote/{election}` | Code entry; countdown before the start; closed message after the end | M1 |
| `/vote/{election}/identity` | "Is this you?" | M2 |
| `/vote/{election}/ballot/{n}` | One ballot; `n` is the position among the voter's ballots | M3 |
| `/vote/{election}/review` | Review before confirming | M4 |
| `/vote/{election}/done` | Vote recorded, then back to code entry | M5 |
| `/results/{election}` | Public results | M-results |
| `/institutions/{institution}` | Public profile and published elections | |

### Sign-in

| Address | Screen | Mockup |
|---|---|---|
| `/register` | Register an institution | A01 |
| `/login`, `/forgot-password`, `/reset-password`, `/verify-email`, `/two-factor`, `/invitation/{token}` | | A01 layout |

### Admin

| Address | Screen | Mockup |
|---|---|---|
| `/admin` | Dashboard | A02 |
| `/admin/elections` | Elections and history | A03 |
| `/admin/elections/new` | New election | A04 |
| `/admin/elections/{election}` | Overview and checklist | A05 |
| `/admin/elections/{election}/ballots` | Ballots, parties, candidates | A06 |
| `/admin/elections/{election}/ballots/{ballot}/candidates/{candidate}` | Candidate record | A07 |
| `/admin/elections/{election}/voters` | Voters, list and record | A08 |
| `/admin/elections/{election}/voters/import` | Import: template and file | A09 |
| `/admin/elections/{election}/voters/import/{import}` | Import: check before importing | A10 |
| `/admin/elections/{election}/codes` | Access codes | A11 |
| `/admin/elections/{election}/turnout` | Live turnout | A12 |
| `/admin/elections/{election}/results` | Results and publication | A13 |
| `/admin/institution` | Profile and users | A14 |
| `/admin/audit` | Audit log | A15 |
| `/platform` | Platform administration | A16 |

The side menu (mockup "Menu latéral") is one component shared by every admin screen. It
holds the institution's sections, the selected election with its own sections and
counters, and the "go to" search (FR-NAV-02).

## 3. Folder structure

```
web/src/
  app/
    (vote)/vote/[election]/...        voting flow
    (public)/results/[election]/      public results
    (public)/institutions/[institution]/
    (auth)/register, login, ...
    (admin)/admin/...                 admin area, one layout with the side menu
    (platform)/platform/
  components/
    ui/                               base components (section 6)
    vote/  admin/  public/            parts used by one area only
  lib/
    api/                              typed client; schema.d.ts is generated
    i18n/                             messages/fr.json, messages/en.json
    format/                           dates in the election's timezone, numbers, percentages
  styles/                             tokens (section 4)
```

Rules:

- A component in `ui/` knows nothing about elections or voters.
- A page fetches; components receive data. No component calls the API on its own, except
  through the shared hooks in `lib/api`.
- No text in a component: every label comes from the message files (NFR-UX-01).

## 4. Design tokens

Taken from the mockups. Defined once as CSS variables and exposed as Tailwind theme
values; a component never writes a raw colour.

### Colour

| Token | Value | Use |
|---|---|---|
| `ink` | `#111B33` | Text, headings |
| `ink-2` | `#36425F` | Strong secondary text |
| `ink-soft` | `#4A556B` | Secondary text, help text |
| `ink-muted` | `#8E99AE` | Placeholders, disabled |
| `canvas` | `#F3F5F9` | Page background |
| `surface` | `#FFFFFF` | Cards, inputs, headers |
| `surface-alt` | `#FAFBFD` | Zebra and hover on a surface |
| `line` | `#D8DEE9` | Borders |
| `line-soft` | `#E7EBF2` | Dividers inside a card |
| `line-strong` | `#B9C2D3` | Input borders, unselected controls |
| `primary` | `#1E3A8A` | Main actions, selection, links |
| `primary-hover` | `#172B66` | Hover and pressed |
| `primary-soft` | `#E6ECFA` | Selected background, focus ring |
| `primary-line` | `#C9D3EA` | Border on a soft primary surface |
| `navy` | `#111B33` | Side menu background |
| `navy-raised` | `#1B2747` | Selected item in the side menu |
| `warm` | `#C2410C` | Warnings, things to do, second avatar colour |
| `warm-ink` | `#6B2408` | Text on a warm surface |
| `warm-soft` | `#FFE8D6` | Warning background, icon tile |
| `warm-softer` | `#FFF4EA` | Inline notice |
| `teal` | `#0F766E` | Open, live, done |
| `teal-ink` | `#0B5750` | Text on a teal surface |
| `teal-soft` | `#D9F2EE` | Success background and halo |
| `ok-ink` / `ok-soft` | `#0B4A2A` / `#DDEFE2` | Valid rows and matched columns in the import |
| `danger` | `#9A2A0A` | Destructive actions, required mark |
| `danger-line` | `#E9B8A8` | Border of a destructive button |

Every text and background pair used must reach WCAG AA contrast (NFR-UX-03); the pairs
are checked by a test in slice 01.

A party's own colour (FR-CAND-01) is data, not a token: it is used only as a small
swatch next to a name, never as a text or background colour, so contrast does not depend
on what an institution picks.

### Type

| Token | Value |
|---|---|
| Display font | Bricolage Grotesque, weights 600 and 700: headings, large figures |
| Text font | Public Sans, weights 400, 500, 600, 700: everything else |
| Code font | The system monospace: access codes, identifiers |
| Hosting | Both fonts are served from our own origin, subset to Latin. No request to a font service (privacy, CSP, weight) |

| Step | Size | Use |
|---|---|---|
| `xs` | 12 px | Labels on pills, table captions |
| `sm` | 13 px | Secondary text |
| `base` | 14 px | Admin body text |
| `md` | 15 px | Voter body text |
| `lg` | 17 px | Card titles |
| `xl` | 18 px | Candidate names |
| `2xl` | 24 px | Admin page titles |
| `3xl` | 30–34 px | Voter screen titles, key figures |
| `4xl` | 40–48 px | The big figure on the turnout and done screens |

Text in a form field is never under 16 px on a phone, so the browser does not zoom.

### Shape and space

| Token | Value | Use |
|---|---|---|
| `radius-sm` | 6 px | Small tags, swatches |
| `radius` | 10 px | Buttons, inputs, tiles |
| `radius-md` | 12 px | Small cards |
| `radius-lg` | 16 px | Cards, candidate rows |
| `radius-full` | 999 px | Pills, avatars |
| Space | A 4 px scale: 4, 8, 12, 16, 20, 24, 32, 40 | |
| Touch target | 44 px high at least; 48 to 56 px for the main action of a voter screen | |
| Selection | 2 px `primary` border plus a 4 px `primary-soft` ring | Selected candidate, focused field |
| Shadows | None. Depth comes from borders and background, as in the mockups | |

### Breakpoints

| Name | From | Layout |
|---|---|---|
| base | 320 px | One column. Admin: the side menu becomes a drawer |
| `md` | 768 px | Voter flow moves to the centred desktop layout |
| `lg` | 1024 px | Admin: fixed side menu (272 px) plus content |
| `xl` | 1280 px | Admin: list and detail side by side; this is the mockup width |

## 5. Admin layout rules

From the approved mockups and what was asked of them:

- Cards rather than tables wherever the content allows (FR-NAV-04).
- List and detail on the same screen at desktop width: choosing an item does not leave
  the page.
- Summary cards at the top of a list double as its filters.
- The content fills the width; no narrow column in an empty page.
- Any screen is reachable in three clicks at most (FR-NAV-02).

## 6. Base components

Built in slice 01 (first five) and as slices need them. Each has its variants, its
states (hover, focus, disabled, loading, error) and a test.

| Component | First used | Notes |
|---|---|---|
| Button | 01 | Variants: primary, secondary, danger, quiet. Sizes: admin, voter |
| Input, Textarea, Select | 01 | Label, help, error message tied to the field for screen readers |
| Card | 01 | |
| Pill | 01 | Status and counters |
| Page shell | 01 | Voter shell, public shell, admin shell |
| Side menu | 03 | |
| Dialog, Menu, Tabs, Tooltip | 03–05 | From an unstyled accessible library (below) |
| Stat card, Filter card | 05 | |
| List-detail | 07 | |
| Stepper | 08 | Import |
| Progress bar, Step bar | 08, 12 | |
| Avatar | 06 | Photo, or the default by sex (FR-CAND-03) |
| Choice card | 12 | A candidate in the voting flow: a real radio or checkbox underneath |
| Empty state, Notice, Toast | 03 | |

**Unstyled library.** The admin area takes its dialogs, menus, tabs and tooltips from
Radix Primitives, styled with the tokens. The voting flow and the public pages use native
HTML controls only (radio, checkbox, button, `dialog`), which costs no JavaScript and is
accessible by default.

## 7. Data

- **Types.** `lib/api/schema.d.ts` is generated from the API's OpenAPI file. No response
  type is written by hand.
- **Admin.** A query library with a cache reads the API from the browser; lists refetch
  on focus; a background job is followed by polling its status endpoint.
- **Voter and public.** Fetched on the server. The voter's choices are kept in memory in
  the page until the final confirmation; nothing is stored in the browser's storage, so a
  shared device keeps no trace (FR-VOTE-06).
- **Errors.** The API's `error.code` is mapped to a message in the message files. An
  unknown code shows a generic message and the request id.

## 8. Language

French by default, English available (NFR-UX-01). The admin area follows the user's
language; the voting flow and public pages follow the election's language, and the voter
can switch. Dates are shown in the election's timezone, with the timezone named when it
differs from the browser's.

## 9. Accessibility (NFR-UX-03)

WCAG 2.1 AA for the voting flow and the public results:

- Every control is reachable and usable with the keyboard, with a visible focus ring.
- A candidate list is a `fieldset` with a `legend`; each candidate is a labelled radio or
  checkbox.
- Status is never given by colour alone: a pill always has text.
- Screen changes in the voting flow move focus to the new heading.
- The browser tests run an automated accessibility check on each voter and public
  screen.

## 10. Performance (NFR-PERF-01)

- Candidate photos are served at the displayed size, in WebP, lazy-loaded below the
  first screen.
- The voting flow loads no chart, date or component library.
- A test in slice 12 measures the transferred size of the first voting screen and fails
  above 150 KB.
