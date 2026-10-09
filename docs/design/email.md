# New Voting System — Emails

Version 1.1 · 2026-10-09 (Material periwinkle colours) · goes with [frontend.md](frontend.md) (Colour, Composition) and
[SPEC.md](../SPEC.md) NFR-UX-01.

Every email the system sends (slice 02: verification and password reset; later slices: invitation,
access codes, results notice) follows these rules. They are checked by
`api/tests/Acceptance/Slice02/MailDesignTest.php` and by the audit below.

## 1. Look

An email is the front-end design in a form every mail program can draw.

| Part | Rule |
|---|---|
| Frame | One centred column, 600 px at most, on the `canvas` colour. White text on indigo and periwinkle above, ink on white below |
| Header | A `deep` band with the product name in white, bold, 20 px. No logo image (the name is text, so nothing is blocked) |
| Hero | A band in `hero-to` with a title in white, 26 px, bold, and one line in white (`hero-ink-soft` is a transparent white, which a mail cannot rely on). The 135° gradient (`hero-from`, `hero-mid` at 45 %, `hero-to`) is added as a background image on top of the solid `hero-to`, which is what programs without gradients show |
| Body | A white card with `ink` text, 16 px, 1.5 line height; short paragraphs; the greeting never holds a name (security review S6) |
| Button | The one main action: `accent` background, `deep` text, bold, 16 px, 8 px radius, at least 48 px high, centred on a phone. Never white text. Built the "bulletproof" way: a table cell with the background and a link inside, plus a VML rounded rectangle for Outlook on Windows |
| Link fallback | Under the button: "if the button does not work…" and the full link, in `primary`, underlined, breaking anywhere |
| Small print | Validity and "ignore this message" in `ink-soft` 14 px; the footer in `ink-soft` 12 px (`ink-muted` is too pale for small text) |
| Language | The language of the user. `lang` on `<html>`; both languages have the same structure |

Colours are the tokens of `web/src/styles/tokens.css`, copied as hexadecimal values (a mail program
has no variables). **Every colour in a mail view is the value of a token.** Writing a new colour
means adding a token first.

## 2. Build

| Rule | Why |
|---|---|
| Table layout with `role="presentation"`; no `flex`, `grid`, `float`, `position`, `calc()`, `var()`, `@import`, `<link>`, `<script>`, `<form>`, `<video>`, `<svg>` | Outlook for Windows draws with Word's engine; Gmail drops many properties |
| All CSS inline; one `<style>` block only for the phone width and dark mode, with the same rules already inline for what must work without it | Gmail apps and some webmails drop or limit `<style>` |
| Colour as both `bgcolor="…"` and `background-color` | Outlook, Windows Mail |
| Fonts: `-apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif` | No web font loads reliably in a mail; the fonts of the web application are not sent |
| No image at all in slice 02. A later image has `alt`, a width and height, and the email still reads with images off | Many programs block images until the reader allows them |
| Nothing is loaded from another address: no tracking pixel, no remote style, no remote font. The only addresses are the link of the action and the link fallback, both from `APP_URL` | NFR-PERF-01, privacy |
| A hidden preheader line (the short text shown next to the subject), then spacing characters so the body text is not pulled into it | Inbox preview |
| `<meta name="color-scheme" content="light dark">` and `supported-color-schemes`; the light version is complete on its own and dark mode only swaps backgrounds and text through `@media (prefers-color-scheme: dark)` | Apple Mail and others invert unpredictably; solid colours with enough contrast survive it |
| Under 102 KB of HTML | Gmail cuts a longer message |
| A plain text part with the same content: the title, the sentences, the button label and the full link on its own line | Text-only readers; spam filters |
| Contrast: every text/background pair at least 4.5:1 (large text 3:1) | NFR-UX-03 |
| Touch: the button and the link fallback are easy to hit on a phone; the column is a single column at 480 px and below | NFR-UX-02 |

## 3. Compatibility target: 95 %

"Compatible" is measured against the [Can I email](https://www.caniemail.com) data
(`https://www.caniemail.com/api/data.json`), for these programs, in their current versions, on the
platforms that exist: Gmail (webmail, iOS, Android, other-account apps), Outlook (Windows, Mac,
Windows Mail, outlook.com, iOS, Android), Apple Mail (macOS, iOS), Yahoo Mail (webmail, iOS,
Android), Samsung Email, Thunderbird, Orange.fr.

**Score**: for each HTML element and CSS property an email uses, and for each program of the list,
the pair counts 1 when the data says it is supported, 0.5 when partly supported, 0 when not
supported, and 1 when the email carries a **declared fallback** that draws acceptably in that program
(for example `bgcolor` beside `background-color`, the solid colour beside the gradient, the VML button
beside the table-cell button). The score is the sum divided by the number of pairs.

Two readings of the data are fixed here: a "partly supported" status counts 1 when none of the limits the data names applies to how the email uses the feature (each case is written in the audit), and a pair the data marks **unknown** is left out of the count and reported separately (with it counted as 0 the score is also printed, so the reader can see both).

The target is **at least 95 %**, and **100 % for reading**: in every program, the title, the text, the
button (or the link under it) and the footer are visible and usable.

The audit is written in `docs/design/email-audit.md`: the features used, the programs, each pair's
status, the fallbacks, the score, and the date and the version of the data it was computed from.
It is recomputed when an email changes in a way that adds an element or a property.

## 4. How a new email is added

1. Use the shared layout view (`resources/views/mail/layout`), never a second structure.
2. Add the texts in `lang/fr/mail.php` and `lang/en/mail.php`.
3. Add the email to `MailDesignTest`'s list so the rules of this page apply to it.
4. If it uses a feature the audit does not list, add it to the audit and check the score.

## Free text in an email

Some emails carry a text typed by an owner: the institution's name in the invitation. It is
escaped (never markup), and the greeting and the subject never hold a person's name (slice 02
review, S6; slice 04 review). What remains is accepted and known: an owner could write a link or
a sentence in the institution's name and a mail program could make the link clickable inside our
email. Only an owner can set it, and the email goes to an address that owner chose. Revisit if
institutions can be created by anyone other than their own owner.
