# New Voting System: email compatibility audit

Audit of the two emails of slice 02 (verification, password reset; French and English) against
[Can I email](https://www.caniemail.com), by the rules of [email.md](email.md) section 3.
It is recomputed when an email gains an element or a property.

- Date of the audit: 2026-10-07
- Data: `https://www.caniemail.com/api/data.json`, fetched once with curl; `api_version` 1.0.4,
  `last_update_date` 2026-09-16 13:05:00 +0000 (HTTP `last-modified`: Wed, 16 Sep 2026 13:06:17 GMT)
- Emails: the four rendered messages (verify and reset, fr and en) as Mailpit received them

## How to rerun it

```sh
# 1. Send the emails (the API test suite or a registration on http://localhost:8080 does it), then
#    save each message from Mailpit as an HTML file (one folder, one file per message):
mkdir -p /tmp/mails && curl -s http://localhost:8025/view/latest.html > /tmp/mails/latest.html
#    (or /view/<message id>.html for the others; the ids are in http://localhost:8025/api/v1/messages)
# 2. Fetch the data and run the audit (plain python3, no dependency; the first argument may also be
#    the address https://www.caniemail.com/api/data.json):
curl -s -o /tmp/data.json https://www.caniemail.com/api/data.json
python3 docker/scripts/email-audit.py /tmp/data.json /tmp/mails > audit-output.md
```

The script exits 1 when the score is under 95 % or a reading feature is under 100 %. It stops, naming
the feature, when an email uses an element or property its `FEATURES` table does not know: add it
there first (and to this audit). The tables below are its output, unchanged.

## Method and decisions

- One pair is a feature (HTML element or attribute, CSS property or at-rule) and a program/platform.
  The programs are those of email.md section 3: every platform the data lists for Gmail, Outlook,
  Apple Mail, Yahoo Mail, Samsung Email, Thunderbird and Orange.fr: 21 program/platform columns. The
  current version is the last one the data lists for the platform.
- Values: supported 1, partly supported 0.5, not supported 0, as in section 3. A pair counts 1 when a
  declared fallback draws acceptably in that program (table "Declared fallbacks").
- **Two decisions that section 3 does not state**, made for this audit and open to review:
  1. *Limits that do not apply* (`a*`). Many "partly supported" entries come with notes naming what is
     not supported (rem sizes, flex, negative margins...). When none of the notes of an entry touches
     how the email uses the feature, the pair counts 1. The table "Limits that do not apply" gives the
     notes and the reason for each feature. Without these two kinds of declaration the score is the
     "data alone" line below (80.81 %).
  2. *Unknown* (`u`). The data marks 47 pairs unknown (Thunderbird for Windows, mostly, which has data
     for three features only; Orange.fr for a few). They are left out of the sum and of the number of
     pairs, and the score with them counted 0 is printed too (92.78 %).
- Elements with no entry in the data (`html`, `head`, `meta`, `title`, `a`, `color`, `font-family`...) are
  listed with the reason and are not scored. The Outlook-only parts inside `<!--[if mso]>` (VML button,
  document settings, ghost table) are read by Outlook for Windows only and are comment text for every
  other program; the data has no entry for them, so they are listed and not scored. The `mso-hide` and
  `mso-line-height-rule` properties are Outlook-only too.
- A *reading feature* is one without which the title, the text, the button or the footer would be lost
  (body, table, div, h1, background colour, font size, padding, line height, display, preheader hiding).
  They must be 1 in every program with data.
- **Known limit, not counted as a reading failure**: the link under the button is long (the address
  plus 64 characters of token). `word-break:break-all` wraps it everywhere except in Orange.fr (Android,
  iOS), Outlook Windows Mail and Yahoo Mail (webmail, iOS, Android), where the data says it is not
  supported (6 of the 21 programs). There the
  line may be wider than the screen, but the button above it, which is the first way to act, is not
  affected, and the plain text part carries the link on its own line.

## Result

Score **99.12 %** (682 / 688 scored pairs), reading **100.00 %** (197 / 197), computed on 2026-10-07 from
data version 1.0.4 (2026-09-16). Every program is at 96.66 % or more. The six pairs that are not 1 are
all `css-word-break` (above); every other pair is supported, a limit that does not apply, or a declared fallback.

The email was reduced to reach this: no `<p>` (cells carry the text), no `max-height` or `overflow` for the
preheader, no padding on the link (the 48 px height comes from `line-height` and the cell carries the
side padding), no `text-align`, no `position`, no flex or grid, no image.

## Audit output

### Input

- Data: Can I email, api_version 1.0.4, last_update_date 2026-09-16 13:05:00 +0000
- Emails scanned: 4 file(s): 1.html, 2.html, 3.html, 4.html
- Programs/platforms (21): Apple Mail ios, Apple Mail macos, Gmail android, Gmail desktop-webmail, Gmail ios, Gmail mobile-webmail, Orange.fr android, Orange.fr desktop-webmail, Orange.fr ios, Outlook android, Outlook ios, Outlook macos, Outlook outlook-com, Outlook windows, Outlook windows-mail, Samsung Email android, Thunderbird macos, Thunderbird windows, Yahoo Mail android, Yahoo Mail desktop-webmail, Yahoo Mail ios
- Rule: supported 1, partly 0.5, not supported 0, declared fallback or inapplicable limit 1, unknown left out

### Features found

| Kind | Name | Where | Can I email entry |
|---|---|---|---|
| at | `media` | style block | `css-at-media` |
| at | `prefers-color-scheme` | style block | `css-at-media-prefers-color-scheme` |
| attr | `align` | td | `html-align` |
| attr | `bgcolor` | body, table, td | none: no entry (legacy attribute, read by every engine) |
| attr | `border` | table | none: no entry |
| attr | `cellpadding` | table | `html-cellpadding` |
| attr | `cellspacing` | table | `html-cellspacing` |
| attr | `charset` | meta | none: no entry |
| attr | `class` | a, body, style block, table, td | `css-selector-class` |
| attr | `content` | meta | none: no entry |
| attr | `href` | a | none: no entry |
| attr | `lang` | html | `html-lang` |
| attr | `name` | meta | none: no entry |
| attr | `role` | table | `html-role` |
| attr | `style` | a, body, div, h1, table, td | none: inline style: each property is listed below |
| attr | `width` | table | `html-width` |
| attr | `xmlns:o` | html | none: Outlook (Office) namespace, ignored elsewhere |
| attr | `xmlns:v` | html | none: Outlook (VML) namespace, ignored elsewhere |
| css | `background-color` | inline, style block | `css-background-color` |
| css | `background-image` | inline | `css-background-image` |
| css | `border-radius` | inline | `css-border-radius` |
| css | `color` | inline, style block | none: no entry (every engine) |
| css | `display` | inline | `css-display` |
| css | `font-family` | inline | none: no entry for a fixed stack (-apple-system is only a name in the list) |
| css | `font-size` | inline | `css-font-size` |
| css | `font-weight` | inline | `css-font-weight` |
| css | `line-height` | inline | `css-line-height` |
| css | `margin` | inline | `css-margin` |
| css | `max-width` | inline | `css-max-width` |
| css | `mso-hide` | inline | none: Outlook-only property, ignored elsewhere |
| css | `mso-line-height-rule` | inline | none: Outlook-only property (the fix the data gives for line-height), ignored elsewhere |
| css | `padding` | inline | `css-padding` |
| css | `padding-bottom` | inline | `css-padding` |
| css | `padding-left` | style block | `css-padding` |
| css | `padding-right` | style block | `css-padding` |
| css | `text-decoration` | inline | `css-text-decoration` |
| css | `width` | inline, style block | `css-width` |
| css | `word-break` | inline | `css-word-break` |
| el | `a` | html | none: no entry for a link to a web address |
| el | `body` | html | `html-body` |
| el | `div` | html | `html-div` |
| el | `h1` | html | `html-h1-h6` |
| el | `head` | html | none: no entry |
| el | `html` | html | none: root element, no entry |
| el | `meta` | html | none: no entry (charset, viewport) |
| el | `style` | html | `html-style` |
| el | `table` | html | `html-table` |
| el | `td` | html | `html-table` |
| el | `title` | html | none: no entry |
| el | `tr` | html | `html-table` |
| misc | `!important` | style block | `css-important` |
| misc | `%` | inline, style block | `css-unit-percent` |
| misc | `comments` | comment | `html-comments` |
| misc | `doctype` | html | `html-doctype` |
| misc | `linear-gradient` | inline | `css-linear-gradient` |
| misc | `meta color-scheme` | meta | `html-meta-color-scheme` |
| misc | `px` | inline, style block | `css-unit-px` |
| val | `display:none` | inline | `css-display-none` |

Only inside `<!--[if mso]>` (read by Outlook for Windows, comment text to everyone else; no entry in the data, not scored): `center`, `o:officedocumentsettings`, `o:pixelsperinch`, `table`, `td`, `tr`, `v:roundrect`, `w:anchorlock`, `xml`.

### Status per feature and program

Codes: y supported, a partly, n not supported, u unknown (left out of the score); `a*` partly, but the limits the data names do not touch how the email uses it; `+fb` counts 1 thanks to the declared fallback.

| Feature | Apple Mail ios | Apple Mail macos | Gmail android | Gmail desktop-webmail | Gmail ios | Gmail mobile-webmail | Orange.fr android | Orange.fr desktop-webmail | Orange.fr ios | Outlook android | Outlook ios | Outlook macos | Outlook outlook-com | Outlook windows | Outlook windows-mail | Samsung Email android | Thunderbird macos | Thunderbird windows | Yahoo Mail android | Yahoo Mail desktop-webmail | Yahoo Mail ios |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `css-at-media` | y | y | a+fb | a+fb | a+fb | n+fb | y | y | y | a+fb | a+fb | a+fb | a+fb | n+fb | n+fb | a+fb | n+fb | u | a+fb | a+fb | a+fb |
| `css-at-media-prefers-color-scheme` | y | y | n+fb | n+fb | n+fb | n+fb | y | y | y | y | y | y | y | n+fb | n+fb | y | n+fb | u | n+fb | n+fb | n+fb |
| `css-background-color` | y | y | y | y | y | y | a+fb | a+fb | a+fb | y | y | y | y | y | y | y | y | u | y | y | y |
| `css-background-image` | y | y | y | y | y | y | y | y | y | y | y | y | y | n+fb | n+fb | y | y | u | a+fb | a+fb | a+fb |
| `css-border-radius` | y | y | y | y | y | y | n+fb | n+fb | n+fb | y | y | y | y | n+fb | n+fb | y | y | u | a* | a* | a* |
| `css-display` | y | y | a* | y | a* | y | a* | a* | a* | y | y | y | y | a+fb | a+fb | y | y | u | a* | a* | a* |
| `css-display-none` | y | y | y | y | y | n+fb | y | y | y | y | y | y | y | a+fb | a+fb | y | y | u | y | y | y |
| `css-font-size` | y | y | y | y | y | y | u | u | u | y | y | y | y | a* | a* | a* | y | u | a* | a* | a* |
| `css-font-weight` | y | y | y | y | y | y | y | a* | y | y | y | y | y | a* | a* | y | y | u | a* | a* | a* |
| `css-important` | y | y | a+fb | a+fb | a+fb | a+fb | n+fb | n+fb | n+fb | y | y | y | y | a+fb | a+fb | y | y | u | a+fb | a+fb | a+fb |
| `css-line-height` | y | y | y | y | y | y | a* | y | a* | y | y | y | y | a* | a* | y | y | u | y | y | y |
| `css-linear-gradient` | y | y | a+fb | y | y | y | n+fb | n+fb | n+fb | n+fb | n+fb | n+fb | n+fb | n+fb | n+fb | a+fb | y | u | n+fb | n+fb | n+fb |
| `css-margin` | y | y | a* | a* | a* | a* | y | y | y | a* | a* | a* | a* | a* | a* | y | y | u | a* | a* | a* |
| `css-max-width` | y | y | y | y | y | y | y | y | y | y | y | y | y | a* | a* | y | y | u | y | y | y |
| `css-padding` | y | y | y | y | y | y | y | y | y | y | y | y | y | a* | a* | y | y | u | y | y | y |
| `css-selector-class` | y | y | a+fb | y | a+fb | n+fb | y | y | y | y | y | y | y | y | y | y | y | u | y | y | y |
| `css-text-decoration` | y | y | a+fb | y | a+fb | y | y | y | n+fb | y | y | y | y | a* | a* | y | y | u | y | y | y |
| `css-unit-percent` | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y |
| `css-unit-px` | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y |
| `css-width` | y | y | y | y | y | y | y | y | y | y | y | y | y | a* | y | y | y | u | y | y | y |
| `css-word-break` | a* | a* | a* | y | a* | a* | n | a* | n | a* | a* | a* | a* | y | n | a* | y | u | n | n | n |
| `html-align` | y | y | y | y | y | y | y | y | y | y | y | a* | y | y | y | y | y | u | y | y | y |
| `html-body` | y | y | a+fb | a+fb | a+fb | a+fb | a+fb | a+fb | a+fb | a+fb | a+fb | n+fb | a+fb | y | y | y | y | u | n+fb | a+fb | n+fb |
| `html-cellpadding` | y | y | y | y | y | y | u | u | u | y | y | y | y | y | y | y | y | u | y | y | y |
| `html-cellspacing` | y | y | y | y | y | y | u | u | u | y | y | y | y | y | y | y | y | u | y | y | y |
| `html-comments` | y | y | y | y | y | y | u | u | u | y | y | y | y | y | y | y | y | u | y | y | y |
| `html-div` | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | u | y | y | y |
| `html-doctype` | y | y | y | y | y | y | y | y | n+fb | y | y | y | y | n+fb | y | n+fb | n+fb | u | n+fb | y | y |
| `html-h1-h6` | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | u | y | y | y |
| `html-lang` | y | y | y | y | y | y | y | n+fb | y | y | y | y | y | y | y | y | y | u | a* | a* | a* |
| `html-meta-color-scheme` | n+fb | y | a+fb | n+fb | a+fb | n+fb | u | u | u | n+fb | n+fb | n+fb | n+fb | n+fb | n+fb | n+fb | y | u | n+fb | n+fb | n+fb |
| `html-role` | y | y | y | y | y | y | n+fb | n+fb | n+fb | y | y | y | y | y | n+fb | y | y | u | a* | a* | a* |
| `html-style` | y | y | a+fb | a* | a+fb | n+fb | y | y | y | y | y | y | y | a+fb | a+fb | y | y | y | y | y | y |
| `html-table` | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | y | u | y | y | y |
| `html-width` | y | y | y | y | y | y | y | y | y | y | y | y | y | a+fb | y | y | y | u | y | y | y |

### Limits that do not apply (`a*`)

| Feature | Notes of the data | Why they do not touch this email |
|---|---|---|
| `css-border-radius` | #2: Partial support. Shorthand for setting elliptical borders with the slash `/` notation is not supported e.g. `border-radius: 27% 73% 70% 30% / 30% 34% 66% 70%;`. | one plain radius, no slash notation |
| `css-display` | #1: Partial. `flex`, `grid`, `flow-root`, `contents`, `inline flow-root`, `inline flex`, `inline grid`, `initial`, `revert`, `unset` are not supported with non Google accounts.; #2: Partial. `inline-flex`, `inline-grid`, `flex`, `grid`, `flow-root`, `contents`, `inline flow-root`, `inline flex`, `inline grid`, `initial`, `revert`, `unset` values are not supported.; #3: Buggy. Only the first value is kept with the two-value syntax.; #4: Buggy. `display:none` does not inherit to inner tables.; #6: Partial. `flow-root`, `inline-flex`, `inline-grid`, `inline flow`, `contents`, `revert` are not supported.; #7: Partial. Two-value syntax are combined into a single one with a dash. | only block and none; the preheader holds no table |
| `css-font-size` | #1: Partial support. `rem` values are not supported.; #2: Partial support. `relative` and `percentage` size values not supported. | sizes are in px only: no rem, relative or percentage size |
| `css-font-weight` | #1: Partial support. `<number>` values are not supported as per CSS Fonts Level 4 where any `<number>` value between 1 and 1000 (inclusive) is a valid value. Only the following numeric values are supported: 100, 200, 300, 400, 500, 600, 700, 800, and 900.; #2: Partial support. `<number>` values between 0 and 599 are set as normal font weight. `<number>` values between 600 and 1000 are set as bold font weight. | only the keyword bold, never a number |
| `css-line-height` | #1: Buggy. `em` and `px` units behave weirdly. Use `mso-line-height-rule:exactly`.; #2: Partial. `normal` value is not supported. | px values with mso-line-height-rule:exactly (the fix the note gives); never `normal` |
| `css-margin` | #1: Partial. Negative values are not supported.; #2: Partial. Not supported on `<span>` and `<body>` elements.; #3: Buggy. `background-color` is included inside the `margin`.; #4: Partial. `auto` value is not supported. | margin:0 0 8px on the title and margin:0 on the body only: no negative or auto value, no background on the margin |
| `css-max-width` | #1: Partial. Only works on `<table>` elements. | max-width only on a table |
| `css-padding` | #1: Partial. Only supported on table cells.; #2: Buggy. Vertical padding will be the same for all cells of a same row, adopting the biggest value. | padding only on table cells (td); one cell per row where padding differs |
| `css-text-decoration` | #2: Partial. Not supported with multiple values.; #3: Partial. `overline` is not supported. | one value (underline or none), no overline |
| `css-width` | #1: Partial. Not supported on `<body>`, `<span>`, `<div>`, `<p>` or `<img>` elements.; #2: Buggy. The webmail has a generic style that sets `table { width:inherit; }`. | width only on tables |
| `css-word-break` | #1: Supported. But Gmail adds `<wbr>` every 30 characters.; #2: Buggy. Supported but a `word-wrap:break-word` is applied, making it look like `break-all`.; #3: Partially supported. Only `word-break:break-all` works.; #4: Buggy. Every value is replaced by `break-word`. | only break-all; a forced break in the link is what is wanted |
| `html-align` | #1: Partial. `<img>` elements are wrapped in a `<span>` so `left` and `right` values have no effect.; #2: Partial. Not supported on `<img>` elements.; #3: Partial. `left` and `right` do not work on `<table>` elements. Use `float:left` or `float:right` styles instead. | align only centres cells: no left/right on a table or an image |
| `html-doctype` | #2: Not supported. The HTML5 doctype has no impact here. | the data says the doctype has no impact there |
| `html-lang` | #1: Not supported on `<td>` elements. | lang on the html element, not on a td |
| `html-role` | #1: Not live tested, only looked at processed code on Litmus.; #2: Partial. Only works on the `<table>` tag. | role only on table elements |
| `html-style` | #1: Partial. Not supported inside the `<body>`.; #6: The size of the `<style>` tag [is limited to 16 KB](https://github.com/hteumeuleu/email-bugs/issues/90) | the style block is in the head and is far under 16 KB |
| `html-width` | #1: Buggy. Percentage width on `<img>` elements are based on the physical file's width, not on the parent element's width.; #3: The `width` attribute is ignored on CID embedded images. See [#171](https://github.com/hteumeuleu/caniemail/issues/171). | no image |

### Declared fallbacks

| Feature | Fallback | Programs |
|---|---|---|
| `css-at-media` | fluid column (width 100 %, max-width 600 px); Outlook for Windows gets a fixed 600 px ghost table | all |
| `css-at-media-prefers-color-scheme` | the light version is complete on its own | all |
| `css-background-color` | bgcolor attribute beside every background-color | all |
| `css-background-image` | solid hero-mid colour (bgcolor) under the gradient | all |
| `css-border-radius` | square corners are acceptable; Outlook for Windows gets a VML rounded rectangle | all |
| `css-display` | display:block on the button link only; without it the link is inline in a 48 px line of the same cell, and Outlook for Windows gets the VML button | {('outlook', 'windows'), ('outlook', 'windows-mail')} |
| `css-display-none` | the preheader text is canvas coloured, 1 px high, mso-hide: harmless if shown | all |
| `css-important` | only inside the optional @media rules | all |
| `css-linear-gradient` | solid hero-mid colour (bgcolor) under the gradient | all |
| `css-selector-class` | classes only select the style block rules; inline styles carry the look | all |
| `css-text-decoration` | a link is underlined by default; an underlined button label is still legible | all |
| `html-body` | the outer table carries the canvas bgcolor and every cell its own colours and fonts; the body only resets the margin | all |
| `html-comments` | a conditional comment is plain comment text to every other program | all |
| `html-doctype` | table layout with explicit widths draws the same in quirks mode | all |
| `html-lang` | semantics only: reading does not change | all |
| `html-meta-color-scheme` | the light version is complete on its own | all |
| `html-role` | semantics only: layout and reading do not change | all |
| `html-style` | every rule that matters is also inline; the block only adds the phone width and dark mode | all |
| `html-width` | the same width is in the style (width, max-width) and the ghost table | all |
### Score per program

| Program | Sum | Pairs | Score |
|---|---|---|---|
| Apple Mail ios | 35 | 35 | 100.00 % |
| Apple Mail macos | 35 | 35 | 100.00 % |
| Gmail android | 35 | 35 | 100.00 % |
| Gmail desktop-webmail | 35 | 35 | 100.00 % |
| Gmail ios | 35 | 35 | 100.00 % |
| Gmail mobile-webmail | 35 | 35 | 100.00 % |
| Orange.fr android | 29 | 30 | 96.66 % |
| Orange.fr desktop-webmail | 30 | 30 | 100.00 % |
| Orange.fr ios | 29 | 30 | 96.66 % |
| Outlook android | 35 | 35 | 100.00 % |
| Outlook ios | 35 | 35 | 100.00 % |
| Outlook macos | 35 | 35 | 100.00 % |
| Outlook outlook-com | 35 | 35 | 100.00 % |
| Outlook windows | 35 | 35 | 100.00 % |
| Outlook windows-mail | 34 | 35 | 97.14 % |
| Samsung Email android | 35 | 35 | 100.00 % |
| Thunderbird macos | 35 | 35 | 100.00 % |
| Thunderbird windows | 3 | 3 | 100.00 % |
| Yahoo Mail android | 34 | 35 | 97.14 % |
| Yahoo Mail desktop-webmail | 34 | 35 | 97.14 % |
| Yahoo Mail ios | 34 | 35 | 97.14 % |

### Score

- Features with an entry: 35; programs/platforms: 21; pairs: 735, of which unknown in the data: 47; scored pairs: 688
- Data alone, no fallback (unknown left out): 556 / 688 = 80.81 %
- With the declared fallbacks and the limits that do not apply: 682 / 688 = **99.12 %** (target 95 %)
- Same, unknown pairs counted 0: 682 / 735 = 92.78 %
- Reading features (css-background-color, css-display, css-display-none, css-font-size, css-line-height, css-padding, html-body, html-div, html-h1-h6, html-table): 197 / 197 = **100.00 %** (target 100 %)
