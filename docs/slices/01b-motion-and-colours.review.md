# Slice 01b — Review

Two reviewers, 2026-10-06, on commit b8b796b.

## Security

No finding. Checked: `/dev/motion` answers 404 in production (code and Dockerfile; the
image was not run); nothing is loaded from another origin; no raw HTML injection; no
secret, numeric id or identifier in markup or messages; no logging; no request-scoped
state in module scope.

## Correctness

| # | Severity | Where | Problem | Status |
|---|---|---|---|---|
| 1 | Blocking | `web/e2e/slice01b/motion.spec.ts:177` | The test read `package.json` with `__dirname`, which does not exist in an ES module package; the coder stopped, the test author replaced it with a path built from `import.meta.url` (independent of the working directory) | Fixed |
| 2 | Should fix | `motion.css` (reveal, grow, check), `useEnter.ts` | The transition is always declared, so after hydration `data-state='pending'` makes visible content fade out and back in; the hook does not skip elements already in view | Fixed |
| 3 | Should fix | `CountUp.tsx` | Counting digits are the only text: a screen reader can announce 0 % or a partial value | Fixed |
| 4 | Should fix | `CountUp.tsx` | The animation frame loop is never cancelled; a changed `value` replays from 0 and two loops can run | Fixed |
| 5 | Should fix | `PageShell.tsx` | With a hero, the page `h1` is outside `<main>` | Fixed |
| 6 | Should fix | `app/dev/motion/page.tsx` | Figures "1 240", "87 %", "1 078" are literal text with French separators (rule 9) | Fixed |
| 7 | Note | `fr.json` (`dev.motion.*`) | Ordinary space before "%" and "votes"; use a no-break space | Fixed |
| 8 | Note | `PageShell.tsx` | `focus-visible:ring-hero-mid` has no effect; the focus mark on the hero depends on CSS order | Fixed |
| 9 | Note | `app/dev/motion/page.tsx` | Several loops in view together at desktop width (Motion rule 6): accept as a demo-only exception or space them | Accepted: demo-only exception |
| 10 | Note | `components/ui/composition.test.tsx` | Tests check implementation details (`-mt-12`, `data-live`) | Fixed |

No database id leak, no raw colour in a component, no animation library, no request
state on the server.
