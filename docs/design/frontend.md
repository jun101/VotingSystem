# New Voting System — Front-end design

Version 1.2 · 2026-10-06 (adds the showcase gradient, the bright accent, motion and the composition rules) · goes with [SPEC.md](../SPEC.md) 1.3,
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
| `navy-deep` | `#061A3D` | Darkest surfaces on a showcase background; text on `accent` |
| `hero-from`, `hero-mid`, `hero-to` | `#09295B`, `#0D3A7A`, `#1A4A8A` | The showcase gradient, at 135°, with the middle stop at 60 % |
| `accent` | `#FF8603` | Highlights and the main call to action on a showcase surface |
| `accent-light` | `#FFB45A` | Second stop of the accent gradient, shimmer |

**Showcase surfaces.** The gradient is the background of the surfaces that present the
product, and only of those: the voter's code entry screen, the sign-in and register
pages, the header of the public results, the admin side menu. Working surfaces (ballots,
lists, forms, records) stay on `canvas` and `surface`, so text and candidate photos stay
easy to read. On a showcase surface, text is white and cards are `surface`.

**The bright accent.** `accent` is the background of the one main action of a screen, on
any surface, and of highlights on a showcase surface. Text on `accent` is always
`navy-deep`, never white: white on this orange does not reach AA contrast. `warm` stays
the colour of warnings and of text on a light background; `accent` is never used for
text on a light background.

### Composition

Approved on two animated reference mockups, kept with the others:
[motion-results.html](mockups/motion-results.html) (public results) and
[motion-vote.html](mockups/motion-vote.html) (voting flow). The earlier mockups keep
their content and their screens; their look follows these rules.

1. **Every screen opens with a full-width showcase hero, and its content climbs onto
   it.** The hero (gradient, white text) answers "where am I and what matters here": a
   pill, a short label, a large title, the state, three key figures. On a desktop it has
   two tilted cards on its right that restate the essential (the winner, the current
   choice). The content below is light; its first row of cards overlaps the bottom of the
   hero by about 50 px. A hero-on-the-left, task-on-the-right split of the whole screen
   was tried for the voting flow and rejected.
2. **A section opens with its own showcase element**, never with a bare title on white:
   a full panel for the main item of the page, a band (a strip of the same gradient with
   an avatar, a name and one figure) for the others.
3. **Fill the width.** On a desktop a row is never one narrow column in an empty page.
   Compositions, in order of preference: a split card (panel and body), a pair of cards,
   a stack beside a taller card, a grid of small tiles. A long list of bars becomes a grid
   of tiles.
4. **Key figures sit in glass tiles** on the showcase surface: a translucent white tile
   with a thin light border, a large figure in the display font, a short label under it.
   Three per row.
5. **One figure per band, in `accent-light`**: the lead, the score, the count that
   matters. Everything else on the panel is white or the soft white.
6. **Status has a colour of its own on a band.** The gradient is the normal state; a tie
   or a warning uses the warm gradient; success uses teal on the working area.
7. **A page ends with a showcase band** that carries the next action (share, continue,
   create), so the bottom of a page is never an empty margin.
8. **The main action is the accent button**, one per screen, at the bottom right on a
   desktop and full width at the bottom on a phone. Other actions are plain.
9. **In the voting flow the hero is the voter's memory**: the institution, their name
   and class, the ballot's title, the step bar, and a "your choice" card that updates the
   moment a candidate is picked.
10. **A candidate is a small showcase card**: a gradient band with the photo or avatar,
    then the name, the party and the slogan on white, and a "choose" pill. Candidates sit
    in a grid that fills the row on a desktop and stack as horizontal cards on a phone.
    The blank vote is a full-width card under them, in a grey band.
11. **The main action lives in a bar fixed to the bottom of the screen**, with a one-line
    reminder of the current choice on its left, so it is always reachable without
    scrolling.

### Motion

The interface moves: it should feel alive, never slow.

| Token | Value | Use |
|---|---|---|
| `duration-fast` | 150 ms | Hover, press, colour changes |
| `duration-base` | 250 ms | Selection, panels, toasts, moving between steps |
| `duration-reveal` | 750 ms | Content appearing on load or on scroll |
| `ease-out-soft` | `cubic-bezier(0.16, 1, 0.3, 1)` | Everything that arrives |
| `ease-spring` | A short overshoot | The selected candidate, the check mark |

| Effect | What it does | Where |
|---|---|---|
| Reveal | Fades in and rises 16 px, once, when it enters the screen; siblings follow each other 60 ms apart, eight at most | Admin, public pages, sign-in |
| Count up | A figure counts from zero to its value | Dashboard, turnout, results |
| Grow | A bar grows from zero to its value | Turnout, results |
| Live dot | A dot that pulses, or sends out a ring | An open election, live turnout |
| Shimmer | A light sweeps across | Loading placeholders; the accent button on a showcase surface |
| Float | Drifts up and down a few pixels over 5 to 6 seconds, slightly tilted | Decorative cards of the sign-in and public hero only |
| Slide | Enters from the side | Panels, toasts, the next ballot |
| Select | The ring springs out around the choice | Candidates, cards, fields |
| Check | The mark draws itself, with one ring pulse | Vote recorded, import done, saved |

Rules:

1. **Only movement and fading are animated** (`transform` and `opacity`), in CSS. No
   animation library. This keeps the voting flow inside its weight budget and smooth on a
   low-end phone.
2. **Reduced motion is respected.** With the device's "reduce motion" setting, every
   effect above is replaced by an instant change; nothing is lost but the movement
   (NFR-UX-03).
3. **Nothing waits for an animation.** A control can be used the moment it is on screen;
   content is never hidden behind an effect that has not run.
4. **The voting flow moves only on purpose**: select, the step bar, the slide between
   ballots, the check at the end. Nothing loops and nothing floats on a ballot: a target
   that moves causes a wrong tap. The drifting light and the live dot run on the code
   entry and confirmation screens only; the sweep of light on the accent button runs on
   the code entry screen only.
7. **Feedback is immediate and small**: a control answers a press within 150 ms (a
   slight shrink), the main action gives one short nudge when it becomes available, and
   only the element that changed moves.
5. **A reveal runs once.** Scrolling back does not replay it.
6. **Loops are few**: at most one looping effect in view at a time outside the hero.

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
