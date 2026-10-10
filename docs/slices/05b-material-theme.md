# Slice 05b — Material periwinkle theme on the pages already built

Written 2026-10-09 · design rules: [frontend.md](../design/frontend.md) section 4 (version 1.3) · mockups:
[material-login](../design/mockups/material-login.html), [material-register](../design/mockups/material-register.html),
[material-admin](../design/mockups/material-admin.html).

## Goal

Jun asked for a new look from a reference picture, and for the pages already done to be redone: same screens, same
behaviour, same test ids, new theme. The rules he wants kept: **as little free space as possible, cards, animation**.

## What changes

1. **Tokens** (`web/src/styles/tokens.css`), names and values as in frontend.md section 4:
   - values change: `ink*`, `canvas`, `surface-alt`, `line*`, `primary*`, `hero-from/mid/to`, `accent`, `accent-light`;
   - renamed: `navy` -> `panel-from` (`#5468D4`) and a new `panel-to` (`#7A4FC4`); `navy-raised` is removed (the
     selected menu item is a white pill); `navy-deep` -> `deep` (`#1E2A5A`);
   - new: `backdrop-from/mid/to`, the four status chip pairs, `shadow-1/2/3/button`, `radius-xl` 28 px, `radius-lg` 20 px.
   Remove the "no shadows" reset; shadows are now tokens. No raw colour in a component.
2. **Fonts**: Archivo (800 and 700, display) and Roboto (400, 500, 700, body), from `@fontsource`, served by our own origin.
   Remove Bricolage Grotesque and Public Sans packages.
3. **Pages and components to redo**, all of them: `/login`, `/register`, `/forgot-password`, `/reset-password`,
   `/verify-email`, `/invitation/{token}` (the sign-in card, below); the admin shell (side menu panel, top bar, user menu,
   verify banner, turned-away page); dashboard; elections list, form, summary, delete dialog; institution page and
   users; account page (two-factor); `ui/` base components (Button pill, Input underlined with floating label, Card,
   Pill, ConfirmDialog, PageShell); the home page; `/dev` showcase pages (they keep their test ids; they now show the
   new look).
4. **Sign-in card** (`AuthLayout`): one white card, 28 px corners, `shadow-3`, on the backdrop gradient (135 deg, drifting);
   left `panel` (logo, promise in display font, illustration that overhangs the card; on register the four steps);
   right the form. Stacks on a phone, illustration no longer overhangs. The illustration is a decorative inline SVG
   (ballot dropping into a ballot box, plant, bubbles; see material-login.html), `aria-hidden`.
5. **Fields** are underlined with a floating label (CSS only, `placeholder=" "`), a focus bar growing from the left.
6. **Admin**: side menu 256 px, gradient panel, rounded outer corners, white pill for the current item, coral badge;
   top bar with the title, the "go to" search, notifications, language, avatar; no hero band; elections list as in
   material-admin.html (tiles + year chips + main button on one row, creation tile first, 3-column grid at 1280 px, cards
   with a 120 px gradient cover, status chip, one key figure, an action row).
7. **Motion** per frontend.md Motion (drift, float, sweep, lift, float label, bob, strength, reveal, grow, live dot), all
   stopped by "reduce motion"; no animation library.

## Test ids to add (the acceptance tests read them)

`auth-backdrop` (the element with the backdrop gradient), `auth-card`, `auth-panel`, `auth-promise`, `auth-form-side`,
`auth-illustration`, `register-step-1` to `register-step-4` (the current one has `aria-current="step"`),
`login-email-label`, `election-band-N` (the 76 px cover band of card N; N as in `election-card-N`).
All existing test ids stay.

## Not changed

Behaviour, routes, API, messages (add a message only where a new text appears: the four register steps, the
promise line), layout and density rules measured by the slice quality specs (3 columns at 1280 px, 16 px gap, one
column on a phone, two-column form with sticky side panel, no empty bands).

## Acceptance tests

`web/e2e/theme/material.spec.ts` (new), and the colour and font values updated in `slice01/home.spec.ts`,
`slice01/components.spec.ts`, `slice01b/tokens.spec.ts`, `composition.spec.ts`, `home.spec.ts`, `support/checks.ts`.
Every other browser test must still pass untouched, desktop and phone.

## Done when

`make check` passes; the pages look like the mockups; accessibility checks pass with the new colours (white text on
`panel`, `hero` and `primary` reaches 4.5:1; large display text on `backdrop` is never used).
