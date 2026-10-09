# New Voting System — Front-end design

Version 1.3 · 2026-10-09 (new theme: Material periwinkle; composition and motion rules kept and re-themed) · goes with [SPEC.md](../SPEC.md) 1.3,
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

Theme "Material periwinkle", approved by Jun on 2026-10-09 from a reference picture
(rounded white card, periwinkle panel, pink-to-blue backdrop, flat illustration). It
replaces the navy and orange showcase theme of version 1.2; the composition and motion
rules below were kept and re-themed. Defined once as CSS variables and exposed as
Tailwind theme values; a component never writes a raw colour.

Mockups: [material-login.html](mockups/material-login.html),
[material-register.html](mockups/material-register.html),
[material-admin.html](mockups/material-admin.html). The mockups show the periwinkle a
little lighter than the tokens: the tokens are darkened so white text on them reaches
AA contrast.

### Colour

| Token | Value | Use |
|---|---|---|
| `ink` | `#14162B` | Text, headings |
| `ink-2` | `#34385A` | Strong secondary text |
| `ink-soft` | `#565B76` | Secondary text, help text |
| `ink-muted` | `#8A8FA8` | Placeholders, disabled |
| `canvas` | `#F1F2FC` | Page background |
| `surface` | `#FFFFFF` | Cards, inputs, headers |
| `surface-alt` | `#F8F9FE` | Hover on a surface |
| `line` | `#DDE0F2` | Borders |
| `line-soft` | `#E9EBF7` | Dividers inside a card |
| `line-strong` | `#B5BBDB` | Input underlines, unselected controls |
| `primary` | `#5468D4` | Main actions, selection, links, focus underline |
| `primary-hover` | `#4458C2` | Hover and pressed |
| `primary-soft` | `#E3E8FF` | Selected background, chips, focus ring |
| `primary-line` | `#B7C1F6` | Border on a soft primary surface |
| `panel-from`, `panel-to` | `#5468D4`, `#7A4FC4` | Side menu and sign-in side panel, vertical gradient |
| `warm` | `#C2410C` | Warnings, things to do |
| `warm-ink` | `#6B2408` | Text on a warm surface |
| `warm-soft` | `#FFE8D6` | Warning background, icon tile |
| `warm-softer` | `#FFF4EA` | Inline notice |
| `teal` | `#0F766E` | Open, live, done |
| `teal-ink` | `#0B5750` | Text on a teal surface |
| `teal-soft` | `#D9F2EE` | Success background and halo |
| `ok-ink` / `ok-soft` | `#0B4A2A` / `#DDEFE2` | Valid rows and matched columns in the import |
| `danger` | `#9A2A0A` | Destructive actions, required mark |
| `danger-line` | `#E9B8A8` | Border of a destructive button |
| `deep` | `#1E2A5A` | Darkest indigo: text on `accent`, drawings |
| `hero-from`, `hero-mid`, `hero-to` | `#A8349A`, `#7C5BD0`, `#5468D4` | Gradient of a surface that carries white text, at 135°, middle stop at 45 % |
| `backdrop-from`, `backdrop-mid`, `backdrop-to` | `#C944B6`, `#A66EDC`, `#7A92EE` | Page background behind the sign-in card and the public pages; never behind text |
| `accent` | `#F29A76` | The main call to action on a gradient surface, highlights, the badge |
| `accent-light` | `#F7D9A8` | One figure on a gradient surface, second stop of the accent |
| `status-open`, `status-scheduled`, `status-draft`, `status-published` | see below | Status chips |

Status chips are text on a soft surface, never colour alone:

| Status | Text | Surface |
|---|---|---|
| open | `#17623D` | `#DDF3E7` |
| scheduled | `#2E3FA8` | `#E3E8FF` |
| draft | `#444964` | `#ECEEF5` |
| published | `#6B2DA8` | `#F0E3FB` |
| closed, archived | `ink-soft` | `line-soft` |

**Gradient surfaces.** Three kinds, never mixed. (1) The **backdrop** (bright gradient,
slowly drifting) lies behind the sign-in and register card and behind the public pages'
header; nothing is written on it directly. (2) The **panel** (`panel-from` to `panel-to`)
is the side menu and the left panel of the sign-in card; text on it is white, at 15 px
or more and weight 500 or more. (3) The **hero** gradient carries white text, as the header of
the public results and as the cover of a card. Working surfaces (ballots, lists,
forms, records) stay on `canvas` and `surface`, so text and candidate photos stay easy to
read.

**The accent.** `accent` (coral) is the background of the one main action of a screen on
a gradient surface, and the badge of the side menu. Text on `accent` is always `deep`,
never white. On a light surface the main action is `primary` with white text. `accent`
is never used for text on a light background.

### Shape and depth (Material)

| Token | Value | Use |
|---|---|---|
| `radius-lg` | 20 px | Cards |
| `radius-xl` | 28 px | The sign-in card, the side menu's outer corners, pill buttons (full height) |
| `radius-full` | 999 px | Chips, buttons, search field, avatars |
| `shadow-1` | `0 1px 2px` ink at 12 % | A card at rest, a tile, an icon button |
| `shadow-2` | `0 6px 16px -10px` ink at 30 % | A card in a grid |
| `shadow-3` | `0 18px 30px -12px` primary at 55 % | A card or tile lifted by hover |
| `shadow-button` | `0 2px 4px` and `0 8px 18px -6px`, primary at 35 % and 60 % | The main button |

Depth is elevation, as in Material: surfaces at rest sit on `shadow-1` or `shadow-2`;
hover lifts them (`translateY(-2px)` for a control, `-5px` for a card) and raises the
shadow. Text fields are underlined, not boxed, with a floating label.

### Type

Display: Archivo, weight 800, tight tracking, for titles and figures. Body: Roboto, 400
and 500. Both are served from our own origin. Sizes as in slice 01.

### Composition

Reference mockups: the three Material mockups above. The two animated mockups of
version 1.2 ([motion-results.html](mockups/motion-results.html),
[motion-vote.html](mockups/motion-vote.html)) and the earlier ones keep their content and
screens; their look follows these rules.

1. **Sign-in and register are one white card on the backdrop.** The card is 1000 px
   wide at most, 28 px corners, `shadow-3`. Left, a `panel` with the logo, one short
   promise (display font, white) and a flat illustration that overhangs the card's
   middle (a ballot dropping into a box, a plant, speech bubbles; register shows the four
   steps instead); right, the form, with the language switch at the top right. On a phone
   the panel stacks above the form and the illustration no longer overhangs. The card
   rises in on load.
2. **Admin has no hero band.** The page title sits on the top bar with the account avatar;
   the "go to" search and the language switch stay at the top of the side menu (they are
   reached from the drawer on a phone). The verify banner sits inside the top bar. The first row of the content
   is the compact row: status tiles (they double as filters), year chips, and the main
   action. Nothing empty is left between the bar and the first card.
3. **Fill the width.** On a desktop a row is never one narrow column in an empty page.
   Compositions, in order of preference: a grid of cards (three columns at 1280 px,
   16 px gap, `auto-fill` from 300 px), a split card (panel and body), a pair of cards, a
   stack beside a taller card. A long list of bars becomes a grid of tiles. A creation
   tile (dashed border, round plus button) is the first cell of a list grid.
4. **Figures sit in tiles.** A white tile with `shadow-1`, a large figure in the display
   font and a short label (48 px high, figure then label, so tiles, year chips and the main
   button share one row), and a bottom line in the colour of its status that grows
   on load. The selected tile is `primary` with white text. On a gradient surface a
   figure sits in a translucent glass tile (`glass`, `glass-line`).
5. **One highlighted figure per gradient surface**, in `accent-light`.
6. **A card is led by its own cover.** An election card starts with a 120 px gradient
   cover (a slow light turning across it), then the status chip, the title, the dates, the
   one figure that matters (a progress bar for an open election), and a row of actions.
   The status keeps its chip colours; a tie or a warning uses `warm`.
7. **A page ends with something useful**, so the bottom of a page is never an empty
   margin: the next step, a "show more" button, or the closing band of the public pages.
8. **The main action is a `primary` pill button**, one per screen (the accent button on
   a gradient surface), at the bottom right of a form on a desktop and full width at the
   bottom on a phone. Other actions are text buttons.
9. **In the voting flow the hero is the voter's memory**: the institution, their name
   and class, the ballot's title, the step bar, and a "your choice" card that updates the
   moment a candidate is picked.
10. **On a ballot the candidates are the screen.** The hero shrinks to a compact header
    (the voter, the ballot's title, the step bar, one line for the current choice): no
    figures and no decorative cards there. Each candidate is a tall photo tile, the
    photo first (4:5) and the name and party under it; two tiles per row on a phone,
    one row of up to four on a desktop. A candidate without a photo gets the default
    avatar at the same size.
11. **Choosing goes through a focus sheet.** Touching a tile opens one candidate large:
    the photo, the name, the party, the slogan, a short presentation, and the accent
    button "Choose <first name>". It rises from the bottom on a phone and is centred on a
    desktop; it is a native `dialog`. Back on the ballot, the chosen tile is lifted, ringed
    in `primary` and marked with the accent check; the others are dimmed but stay
    usable. The blank vote is a wide dashed button under the tiles and uses the same
    sheet.
12. **The main action lives in a bar fixed to the bottom of the screen**, with a one-line
    reminder of the current choice on its left, so it is always reachable without
    scrolling.

### Large screens, icons and the date picker (added 2026-10-09)

Reference: [material-wide.html](mockups/material-wide.html) (1920 × 1080),
[material-picker.html](mockups/material-picker.html). Jun's screen is 1920 × 1080: every list is checked
at 1280 and at 1920.

1. **Cards grow with the screen.** The card grid is `auto-fill` from 300 px below 1600 px (three columns at
   1280) and from 380 px from 1600 px, where the rail takes 340 px: three columns of 400 px and more at 1920. An election card has a 120 px cover with a large faint
   icon of its status, the status badge on the cover, the title (20 px), the dates line, **three fact tiles** (positions,
   voters, ballots; each an icon, a figure, a label) and a row of actions. An open election shows a participation bar.
2. **From 1600 px a right rail** of 340 px sits beside the grid and the page never leaves its right side empty.
   It holds two panels: a **month calendar** (the month of the nearest upcoming election, else the current month;
   days with an election are marked, today is ringed) and a **to-do list** (drafts to finish, an election starting
   within seven days; later slices add voters to import, candidates to add, codes to print). With nothing to do the
   list invites the first election. Below 1600 px the rail is hidden, never squeezed.
3. **Icons make the interface alive.** One inline SVG set (24 px grid, stroke 2, rounded), always `aria-hidden`
   and always with its text beside it: the status tiles, the status badge and watermark on a cover, the fact tiles,
   every card action, the side menu. Colour comes from tokens.
4. **The date and time picker replaces the native field.** Two big tiles, "Début" and "Fin": a gradient day badge
   (month, day, weekday), the time in the display font and the full date in words. Touching a tile opens a popover
   (a bottom sheet on a phone) holding quick choices (tomorrow, next Monday for five days, in a week, one day 8:00
   to 17:00), a calendar of two months (one on a phone) with the voting days shaded between the start and the end,
   time chips and hour and minute steppers for the field being edited, the time zone's name and the duration. The
   start day and the end day are circles (primary and rose); a pick that would put the end before the start moves
   the other date so the previous duration is kept. A past day is allowed for a draft. The two native
   `datetime-local` fields stay in the page, visually hidden, as the keyboard and screen reader path and as the
   value the form reads; the tiles are the pointer interface and write to them. Escape closes the popover and returns
   focus to its tile.

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
| Shimmer | A light sweeps across | Loading placeholders; the main button on the sign-in card and the admin list |
| Float | Drifts up and down a few pixels over 5 to 9 seconds | The illustration of the sign-in card, decorative cards of the public hero only |
| Drift | The backdrop gradient slides slowly (14 s) | Behind the sign-in card and the public header only |
| Sweep | A light turns slowly across a card's cover (9 s) | Election card covers |
| Lift | A card or tile rises 5 px (a control 2 px) and its shadow deepens | Hover on cards, tiles, buttons |
| Float label | The label of a field rises and shrinks above the text | Every text field, on focus and when filled |
| Bob | The round plus button of a creation tile rises and falls 6 px | Creation tile only |
| Strength | The three segments of the password meter fill one after the other | Register |
| Slide | Enters from the side | Panels, toasts, the next ballot |
| Select | The ring springs out around the choice | Candidates, cards, fields |
| Check | The mark draws itself, with one ring pulse | Vote recorded, import done, saved |

Rules:

1. **Only movement and fading are animated** (`transform` and `opacity`), in CSS.
   Exceptions, all on small elements: the check mark that draws itself (its stroke), the
   drift of the backdrop gradient (its background position, sign-in only), the shadow of a
   card or button deepening on hover, the label of a field rising (its top and size), and the
   colour, border and ring of a control easing on hover, focus and selection. No
   animation library. This keeps the voting flow inside its weight budget and smooth on
   a low-end phone.
2. **Reduced motion is respected.** With the device's "reduce motion" setting, every
   effect above is replaced by an instant change; nothing is lost but the movement
   (NFR-UX-03).
3. **Nothing waits for an animation.** A control can be used the moment it is on screen;
   content is never hidden behind an effect that has not run.
4. **The voting flow moves only on purpose**: select, the step bar, the slide between
   ballots, the check at the end. Nothing loops and nothing floats on a ballot: a target
   that moves causes a wrong tap. The drifting light and the live dot run on the code
   entry and confirmation screens only; the sweep of light on the main button runs on
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
| `lg` | 1024 px | Admin: fixed side menu (272 px, gradient panel, rounded outer corners) plus content |
| `xl` | 1280 px | Admin: list and detail side by side; this is the first mockup width |
| `2xl` | 1600 px | Admin lists gain the 340 px right rail; 1920 × 1080 is checked as well |

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
