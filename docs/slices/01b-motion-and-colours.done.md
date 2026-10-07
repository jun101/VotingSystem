# Slice 01b — Motion and colours: what was done

2026-10-07 · branch `slice/01b-motion-and-colours`

## What you can do now

1. `make up`, then open http://localhost:8080/dev/motion: every building block of the
   showcase look, each with its animation. The first ones sit in a hero; scroll down to see
   the reveals, the bars and the count-up run when they enter the screen.
2. Turn on "reduce motion" in your system and reload: the same page, nothing moves,
   nothing is missing.
3. Open http://localhost:8080: the home page now opens with the hero; the status card
   overlaps its bottom edge.
4. http://localhost:8080/dev/components also shows the new accent button.
5. `/dev/motion` answers 404 in production mode, like `/dev/components`.

## What was built

| Part | Content |
|---|---|
| Tokens | Showcase colours, three durations, two easings, and four translucent tokens for glass and hero text (`web/src/styles/tokens.css`) |
| Composition (`components/ui`) | `Hero` (with `HeroPill`), `GlassTile`, `Band`, `ShowcaseCard`, `ClosingBand`, `ActionBar`; `Button` gains the `accent` variant and a shimmer option; `PageShell` gains `hero`, `overlap` and `withActionBar` |
| Motion (`components/motion`) | `Reveal`, `CountUp`, `GrowBar`, `LiveDot`, `Shimmer`, `Float`, `Check`, `Slide`, and the select ring; all in `web/src/styles/motion.css` plus one hook, `useEnter`. No animation library |
| Pages | `/dev/motion` (new), `/` with the hero, `/dev/components` with the accent button |
| Messages | French and English entries for all new text |

## Decisions along the way

- Only `transform`, `opacity`, `stroke-dashoffset` and `background-position` are animated;
  Tailwind's `translate-*`, `scale-*` and `rotate-*` utilities are not used on moving
  elements because they animate other properties.
- The entrance state is written by script only once the page runs, so nothing is hidden
  without JavaScript or with reduced motion; elements already in view are not animated.
- The count-up keeps its final value in the HTML for screen readers; the counting digits
  are hidden from them.
- With a hero, the page `h1` is inside `<main>`; the product name stays above it.
- The select card is a label around a native radio, so it works before the page hydrates.

## Changed along the way

- One acceptance test was wrong when first run: the "no animation library" test used
  `__dirname`, which does not exist in this ES module package. It now reads `package.json`
  from its own location. Its intent did not change.
- The vote mockup (photo-first tiles with a focus sheet) was approved by Jun on 2026-10-06
  as the design for slice 12; nothing of it is built here.

## Review

Two reviews. Security: no finding. Correctness: 10 findings (1 blocking, 5 to fix,
4 notes); 9 fixed, 1 accepted (several loops in view together on the demo page, a
demo-only exception). See `01b-motion-and-colours.review.md`.

## Checks

- `make check` passes: 91 API tests, 63 web unit tests, 108 end-to-end tests (2 skipped,
  each for the other screen size), build and audit.
- Compared with `motion-results.html` at 1280 and 390 px: same gradient, accent word,
  glass tiles, tilted floating cards, bands (default and warm, accent-light figure),
  showcase card split, action bar and accent button. Differences are intended: the
  demo uses neutral demo text, not the mockup's content.
- **Not measured:** the first-load JavaScript of the home page. The first measurement
  (after the coding step) was +977 B against the 5 KB limit; the review fixes changed the
  `useEnter` hook slightly and the build prints no sizes, so the final figure is an
  estimate of a few hundred bytes more.
- The Next.js development badge overlaps the bottom-left of the action bar in development
  only; it does not exist in the production build.
- The built-in browser pane blocks the app's stylesheet (`ERR_BLOCKED_BY_CLIENT`), so the
  screenshots were taken with Playwright. In your own browser the page loads normally.

## Next

Slice 02. Before it: the checkpoint, the pull request and the merge, after you try the
pages above.
