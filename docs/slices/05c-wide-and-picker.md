# Slice 05c — Large screens, icons and the date picker

Written 2026-10-09 · rules: [frontend.md](../design/frontend.md) "Large screens, icons and the date picker" ·
mockups [material-wide](../design/mockups/material-wide.html), [material-picker](../design/mockups/material-picker.html).
Same branch and pull request as 05b (the theme).

## Why

Jun's screen is 1920 x 1080 and the list looked small with its right side empty. He asked for icons, bigger cards for
big screens, and a better date and time picker; he approved the right rail.

## Do

1. **Tokens**: add the `2xl` breakpoint, 1600 px (`--breakpoint-2xl`).
2. **Icons**: extend `components/admin/Icon.tsx` with the symbols of the mockup (flag, people, ballot, clock, calendar,
   draft, chart, qr, list, trash, copy, edit, open, sun, moon, globe, check, left, right, plus, minus). Use them in the
   status tiles, the side menu, the card badge and watermark, the facts, the card actions. `aria-hidden`, text always
   beside them.
3. **Election card** (`ElectionCard`): 120 px cover (`election-band-N`), large faint status icon on it, badge on the cover
   (`election-badge-N`, icon + status text), title, dates line with icon, three fact tiles `election-fact-positions-N`,
   `election-fact-voters-N`, `election-fact-ballots-N` (icon, figure in a child with test id ending `-value`, label;
   figures are 0 for now), open elections also a participation bar (no data yet: not shown), action row with icons
   (`election-open-N`, `election-duplicate-N`, `election-delete-N` keep their ids). Grid `auto-fill` from 300 px below
   1600 px, from 380 px from 1600 px (`2xl`). The one-column phone layout and the three columns at 1280 are unchanged.
4. **Rail** (`elections-rail`, `2xl` and up only, `hidden` below, 340 px): panel `rail-calendar` (the month of the nearest
   upcoming election in its time zone, else the current month; a day cell has `rail-day-YYYY-MM-DD`,
   `data-event="true"` when an election is running that day, `aria-current="date"` for today) and panel with the to-do
   list: `rail-todo-N` links (to the election's edit page for a draft; to its summary for an election starting within
   7 days), each with an icon, or `rail-todo-empty` (invitation to create the first election) when the list is empty.
   Texts in the message files. The rail sits beside the grid in one row, and the page fills the width (no empty column
   at 1920).
5. **Date and time picker** (`components/elections/DateRangePicker`) replacing the visible native fields of
   `ElectionForm`: tiles `election-starts-tile`, `election-ends-tile` (day badge, time, date in words); popover
   `date-picker` (role dialog, bottom sheet on a phone) with `picker-preset-one-day|tomorrow|next-monday|in-a-week`,
   months `picker-month-0` and `picker-month-1` (second only from `md`), day buttons `picker-day-YYYY-MM-DD` with
   `data-range="start|in|end"` (absent or other otherwise), `picker-time-chip-HH:MM` (08:00, 12:00, 17:00, 19:00),
   `picker-hour-plus|minus`, `picker-minute-plus|minus` (5 minutes), `picker-summary` (same duration text as the side
   panel plus the time zone), `picker-apply`. Rules as in frontend.md point 4: a pick that would put the end before the
   start moves the other date to keep the previous duration; a past day is allowed. The native `datetime-local` fields
   (`election-starts`, `election-ends`) stay in the DOM, visually hidden (not `display:none`, so a screen reader and the
   existing tests still reach them), are the form's value, and stay in sync both ways. Escape closes and returns focus
   to the tile. Calendar maths use `lib/format/zonedTime.ts` (the election's time zone), never the browser's. No date
   library, no new dependency. Motion: the popover rises and fades in, the day circles pop, the range band grows.

## Acceptance tests

`web/e2e/theme/wide.spec.ts`, `web/e2e/theme/picker.spec.ts`. Every other browser test passes untouched, desktop and
phone; the slice 05 form tests keep filling the hidden native fields.

## Done when

`make check` passes; the list at 1920 x 1080 and the picker look like the two mockups.
