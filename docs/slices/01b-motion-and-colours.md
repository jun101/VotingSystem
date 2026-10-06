# Slice 01b — Motion and colours

Brief · 2026-10-06 · branch `slice/01b-motion-and-colours`

## Goal

The look approved on the two reference mockups becomes real building blocks, before any
real screen is built: the showcase gradient, the bright accent, the composition pieces
and every animation of the front-end design. A demo page shows each of them, and the
home page uses them.

At the end, a person opens `http://localhost:8080/dev/motion`, sees every effect run,
switches "reduce motion" on in their system and sees the same page with no movement.

## Requirements covered

NFR-UX-02 (tokens and layout from 320 px), NFR-UX-03 (reduced motion, contrast, keyboard),
NFR-PERF-01 (no animation library; nothing loaded from another origin).

## Read before coding

[frontend.md](../design/frontend.md) sections 4 (Colour, Composition, Motion) and 6 ·
the two references [motion-results.html](../design/mockups/motion-results.html) and
[motion-vote.html](../design/mockups/motion-vote.html): open them in a browser; they are
the visual target, their code is not to be copied as is.

## What to build

### 1. Tokens

Added to the existing token file, exposed to Tailwind like the others.

| Kind | Tokens |
|---|---|
| Colour | `--color-navy-deep`, `--color-hero-from`, `--color-hero-mid`, `--color-hero-to`, `--color-accent`, `--color-accent-light`, with the values of frontend.md |
| Duration | `--duration-fast` 150 ms, `--duration-base` 250 ms, `--duration-reveal` 750 ms |
| Easing | `--ease-out-soft`, `--ease-spring` |

### 2. Composition components (`components/ui`)

| Component | Requirement |
|---|---|
| `Hero` | Full-width showcase surface: the 135° gradient with its three stops, white text, two soft lights that drift (only when `live`). Slots: pill, label, title (with an optional accent word), lede, figures, and a stage for up to two tilted cards shown from the `lg` breakpoint. A `compact` variant with the title and one line only |
| `GlassTile` | A translucent tile with a thin light border: a large figure in the display font and a short label. Used three per row |
| `Band` | A strip of the gradient with an avatar slot, a small label, a name and one highlighted figure in `accent-light`. Tones: `default` (gradient) and `warm` |
| `ShowcaseCard` | A card split in a showcase panel and a light body: side by side from `md`, stacked under it |
| `ClosingBand` | The band that ends a page: a title, a line and the main action |
| `Button` | New variant `accent`: the accent gradient as background, `navy-deep` text, never white. It is the one main action of a screen |
| `ActionBar` | A bar fixed to the bottom of the screen: a one-line reminder on the left, the main action on the right; full-width action on a phone; respects the phone's bottom safe area |

Content cards placed right after a `Hero` overlap its bottom edge (frontend.md,
Composition rule 1): the page shell offers this as an option.

### 3. Motion components (`components/motion`)

Each is a small component or CSS utility. All obey the Motion rules of frontend.md.

| Component | Behaviour |
|---|---|
| `Reveal` | Fades in and rises 16 px, once, when it enters the screen. Children can follow each other 60 ms apart, eight at most. Visible without JavaScript |
| `CountUp` | Counts from zero to its value when it enters the screen; formats in the current language; the final value is in the HTML from the start, so it is right without JavaScript and for screen readers |
| `GrowBar` | A bar that grows from zero to a value between 0 and 1 when it enters the screen |
| `LiveDot` | A dot that sends out a ring, forever |
| `Shimmer` | A light sweeping across: a loading placeholder, and an option of the accent button |
| `Float` | Drifts a few pixels over 5 to 6 seconds, slightly tilted; decoration only |
| `Check` | A check mark that draws itself, with one ring pulse |
| Select ring | The selection style: 2 px `primary` border and a 4 px `primary-soft` ring that springs out. A utility used by cards and fields |
| Slide | An entrance from the left or the right in 300 ms, for panels and steps |

### 4. Pages

| Page | Requirement |
|---|---|
| `/dev/motion` | Shows every component of sections 2 and 3 with the `data-testid` values below, the first ones in a `Hero`, the others below it so that some start under the first screen. Answers 404 in production mode, like `/dev/components` |
| `/` | The home page opens with a `Hero` (title "New Voting System", the status as three `GlassTile`s or in a card that overlaps the hero) and its content appears with `Reveal`. Everything slice 01 tests on this page stays true: the `h1`, the `h2`, the four `data-testid` rows and their `data-state` |
| `/dev/components` | Gains the `accent` button as `demo-button-accent` |

**`data-testid` values on `/dev/motion`**

`demo-hero`, `demo-hero-title`, `demo-glass`, `demo-band`, `demo-band-figure`,
`demo-showcase-card`, `demo-closing-band`, `demo-action-bar`, `demo-button-accent`,
`demo-reveal` (placed below the first screen), `demo-countup` (value 87, suffix " %"),
`demo-growbar` (value 0.87; the attribute is on the element that is scaled),
`demo-livedot`, `demo-shimmer`, `demo-float`, `demo-check`, `demo-select` (a selectable
card; `aria-pressed` or a checked radio inside tells its state).

## Rules checked by tests

1. No animation library is added to `web/package.json`.
2. Every running animation and transition animates only `transform`, `opacity`,
   `stroke-dashoffset` or `background-position`.
3. With "reduce motion", no animation runs and every element is in its final state.
4. With JavaScript off, every element of the page is visible.
5. Nothing is loaded from another origin; no sideways scroll at 320 px; the automated
   accessibility check passes, contrast included.

## Acceptance tests

Written before the code; **not edited during coding**. If one seems wrong, stop and say
why.

| File | Checks |
|---|---|
| `web/e2e/slice01b/tokens.spec.ts` | The new tokens |
| `web/e2e/slice01b/composition.spec.ts` | Hero, glass tile, band, showcase card, closing band, accent button, action bar |
| `web/e2e/slice01b/motion.spec.ts` | Each effect, reduced motion, JavaScript off, the animated properties, no library |
| `web/e2e/slice01b/home.spec.ts` | The home page uses the hero and stays complete |

All the slice 01 tests keep passing.

## Out of scope

- The candidate tile and the focus sheet (slice 12), the side menu (slice 03), charts.
- Redrawing any earlier mockup.
- Any change to the API.

## Done when

1. `make check` passes entirely, with the slice 01 tests and these.
2. `/dev/motion` shows every effect; with "reduce motion" nothing moves and nothing is
   missing.
3. The home page opens with the hero, in French and English, at desktop and phone width.
4. The first load of the home page transfers no more JavaScript than before this slice
   plus 5 KB.
5. The CI workflow passes; no reviewer finding is left open.
