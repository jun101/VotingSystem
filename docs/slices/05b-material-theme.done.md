# Slice 05b — Material periwinkle theme: what was done

2026-10-09 · branch `slice/05b-material-theme`

## What changed

- **Design rules 1.3** (`docs/design/frontend.md` section 4, `email.md` 1.1): new colours, shapes and shadows, fonts
  (Archivo and Roboto, from our own origin), composition and motion rules re-themed; the rules Jun wanted kept
  (as little free space as possible, cards, animation) are unchanged in substance. Three mockups in
  `docs/design/mockups/material-*.html`.
- **Sign-in card** on the drifting backdrop for login, register (four steps), forgot and reset password, verify email and
  accept invitation; underlined fields with floating labels; pill buttons.
- **Admin**: gradient side menu with a white pill for the current page, top bar, elections list with tiles, year chips and
  the main button on one row, a creation tile and cards with a 76 px gradient cover, plus dashboard, institution, users,
  account and base components.
- **Emails** on the new colours.
- **Tests**: `web/e2e/theme/material.spec.ts` (27 tests per project) and the colour and font values updated in the older
  token and composition tests.

## Differences from the mockups (kept because older tests require them)

Side menu 272 px; "go to" search and language switch stay at the top of the side menu; no notifications bell, no menu badge,
no progress bar on cards (no data yet); election card actions are text only; status tiles are 48 px high, figure then label.

## Check

`make check` passes: 922 API, 175 web, 486 browser tests. No review round yet (see the pull request).
