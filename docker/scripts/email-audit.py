#!/usr/bin/env python3
"""Compatibility audit of the emails (docs/design/email.md section 3; result in
docs/design/email-audit.md). Plain python3, no dependencies.

    python3 docker/scripts/email-audit.py DATA HTML [HTML ...]

    DATA  the Can I email data: a path to a saved copy or the address itself
          (https://www.caniemail.com/api/data.json)
    HTML  rendered emails: .html files or folders holding them (for example the
          messages of Mailpit, saved with
          `curl -s http://localhost:8025/api/v1/message/<ID>`'s HTML part, or
          the `html` field of the API tests)

It lists the HTML elements, attributes and CSS properties found (the style block and the
conditional comments for Outlook included), looks each up in the data for the programs
below, applies the declared fallbacks (FALLBACKS), and prints the tables and the score in
Markdown. A feature the table FEATURES does not know stops the script: add it there first,
with its Can I email slug (or the reason it has none).

Score (section 3): per (feature, program/platform) pair: supported 1, partly supported 0.5,
not supported 0, and 1 when a declared fallback draws acceptably there or when the limits the
data names for a partial status do not apply to how the email uses the feature. Pairs the
data marks unknown are left out (the score with them counted 0 is printed too). Score =
sum / scored pairs. Percentages are cut (never rounded up) to two decimals.
"""
import json
import re
import sys
import urllib.request
from fractions import Fraction
from html.parser import HTMLParser
from pathlib import Path

# Programs (family -> platforms listed by the data), current version = last version listed.
PROGRAMS = {
    'gmail': 'Gmail', 'outlook': 'Outlook', 'apple-mail': 'Apple Mail', 'yahoo': 'Yahoo Mail',
    'samsung-email': 'Samsung Email', 'thunderbird': 'Thunderbird', 'orange': 'Orange.fr',
}

# What the email uses -> Can I email slug. None = the data has no entry (reason given).
# key: ('el', tag) | ('attr', name) | ('css', property) | ('val', 'property:value') | ('at', rule)
#      | ('sel', kind) | ('misc', name)
FEATURES = {
    ('misc', 'doctype'): ('html-doctype', ''),
    ('el', 'html'): (None, 'root element, no entry'),
    ('el', 'head'): (None, 'no entry'),
    ('el', 'meta'): (None, 'no entry (charset, viewport)'),
    ('el', 'title'): (None, 'no entry'),
    ('el', 'style'): ('html-style', ''),
    ('el', 'body'): ('html-body', ''),
    ('el', 'table'): ('html-table', ''),
    ('el', 'tr'): ('html-table', 'same entry as table'),
    ('el', 'td'): ('html-table', 'same entry as table'),
    ('el', 'div'): ('html-div', ''),
    ('el', 'h1'): ('html-h1-h6', ''),
    ('el', 'a'): (None, 'no entry for a link to a web address'),
    ('attr', 'lang'): ('html-lang', ''),
    ('attr', 'role'): ('html-role', ''),
    ('attr', 'width'): ('html-width', ''),
    ('attr', 'align'): ('html-align', ''),
    ('attr', 'cellpadding'): ('html-cellpadding', ''),
    ('attr', 'cellspacing'): ('html-cellspacing', ''),
    ('attr', 'bgcolor'): (None, 'no entry (legacy attribute, read by every engine)'),
    ('attr', 'border'): (None, 'no entry'),
    ('attr', 'class'): ('css-selector-class', 'a class is only useful with the style block'),
    ('attr', 'style'): (None, 'inline style: each property is listed below'),
    ('attr', 'href'): (None, 'no entry'),
    ('attr', 'charset'): (None, 'no entry'),
    ('attr', 'name'): (None, 'no entry'),
    ('attr', 'content'): (None, 'no entry'),
    ('attr', 'xmlns:v'): (None, 'Outlook (VML) namespace, ignored elsewhere'),
    ('attr', 'xmlns:o'): (None, 'Outlook (Office) namespace, ignored elsewhere'),
    ('misc', 'meta color-scheme'): ('html-meta-color-scheme', 'color-scheme and supported-color-schemes'),
    ('misc', 'comments'): ('html-comments', 'the Outlook conditional comments'),
    ('css', 'background-color'): ('css-background-color', ''),
    ('css', 'background-image'): ('css-background-image', ''),
    ('misc', 'linear-gradient'): ('css-linear-gradient', ''),
    ('css', 'border-radius'): ('css-border-radius', ''),
    ('css', 'color'): (None, 'no entry (every engine)'),
    ('css', 'font-family'): (None, 'no entry for a fixed stack (-apple-system is only a name in the list)'),
    ('css', 'font-size'): ('css-font-size', ''),
    ('css', 'font-weight'): ('css-font-weight', ''),
    ('css', 'line-height'): ('css-line-height', ''),
    ('css', 'text-align'): ('css-text-align', ''),
    ('css', 'text-decoration'): ('css-text-decoration', ''),
    ('css', 'padding'): ('css-padding', ''),
    ('css', 'padding-bottom'): ('css-padding', 'same entry as padding'),
    ('css', 'padding-left'): ('css-padding', 'same entry as padding'),
    ('css', 'padding-right'): ('css-padding', 'same entry as padding'),
    ('css', 'margin'): ('css-margin', ''),
    ('css', 'width'): ('css-width', ''),
    ('css', 'max-width'): ('css-max-width', ''),
    ('css', 'word-break'): ('css-word-break', ''),
    ('css', 'mso-line-height-rule'): (None, 'Outlook-only property (the fix the data gives for line-height), ignored elsewhere'),
    ('css', 'mso-hide'): (None, 'Outlook-only property, ignored elsewhere'),
    ('css', 'display'): ('css-display', 'the display property (block)'),
    ('val', 'display:none'): ('css-display-none', ''),
    ('at', 'media'): ('css-at-media', ''),
    ('at', 'prefers-color-scheme'): ('css-at-media-prefers-color-scheme', ''),
    ('misc', '!important'): ('css-important', ''),
    ('misc', 'px'): ('css-unit-px', ''),
    ('misc', '%'): ('css-unit-percent', ''),
}

# Declared fallbacks: slug -> (description, where). where: 'all' or a set of (family, platform).
# A fallback makes the pair count 1 where the email still draws acceptably without the feature.
FALLBACKS = {
    'html-style': ('every rule that matters is also inline; the block only adds the phone width and dark mode', 'all'),
    'css-selector-class': ('classes only select the style block rules; inline styles carry the look', 'all'),
    'css-at-media': ('fluid column (width 100 %, max-width 600 px); Outlook for Windows gets a fixed 600 px ghost table', 'all'),
    'css-at-media-prefers-color-scheme': ('the light version is complete on its own', 'all'),
    'css-important': ('only inside the optional @media rules', 'all'),
    'html-meta-color-scheme': ('the light version is complete on its own', 'all'),
    'css-background-color': ('bgcolor attribute beside every background-color', 'all'),
    'css-background-image': ('solid hero-mid colour (bgcolor) under the gradient', 'all'),
    'css-linear-gradient': ('solid hero-mid colour (bgcolor) under the gradient', 'all'),
    'css-border-radius': ('square corners are acceptable; Outlook for Windows gets a VML rounded rectangle', 'all'),
    'css-display-none': ('the preheader text is canvas coloured, 1 px high, mso-hide: harmless if shown', 'all'),
    'css-display': ('display:block on the button link only; without it the link is inline in a 48 px line of the same cell, and Outlook for Windows gets the VML button', {('outlook', 'windows'), ('outlook', 'windows-mail')}),
    'css-text-decoration': ('a link is underlined by default; an underlined button label is still legible', 'all'),
    'html-doctype': ('table layout with explicit widths draws the same in quirks mode', 'all'),
    'html-body': ('the outer table carries the canvas bgcolor and every cell its own colours and fonts; the body only resets the margin', 'all'),
    'html-comments': ('a conditional comment is plain comment text to every other program', 'all'),
    'html-role': ('semantics only: layout and reading do not change', 'all'),
    'html-lang': ('semantics only: reading does not change', 'all'),
    'html-width': ('the same width is in the style (width, max-width) and the ghost table', 'all'),
}

# Limits the data names (note numbers) that do not touch how the email uses the feature, so
# the status counts as supported ("a*"). Every entry says why.
UNAFFECTED = {
    'css-font-size': ({1, 2}, 'sizes are in px only: no rem, relative or percentage size'),
    'css-font-weight': ({1, 2}, 'only the keyword bold, never a number'),
    'css-line-height': ({1, 2}, 'px values with mso-line-height-rule:exactly (the fix the note gives); never `normal`'),
    'css-padding': ({1, 2}, 'padding only on table cells (td); one cell per row where padding differs'),
    'css-margin': ({1, 2, 3, 4}, 'margin:0 0 8px on the title and margin:0 on the body only: no negative or auto value, no background on the margin'),
    'css-width': ({1, 2}, 'width only on tables'),
    'css-max-width': ({1}, 'max-width only on a table'),
    'css-display': ({1, 2, 3, 4, 6, 7}, 'only block and none; the preheader holds no table'),
    'css-border-radius': ({2}, 'one plain radius, no slash notation'),
    'css-word-break': ({1, 2, 3, 4}, 'only break-all; a forced break in the link is what is wanted'),
    'css-overflow-wrap': (set(), ''),
    'html-lang': ({1}, 'lang on the html element, not on a td'),
    'html-role': ({1, 2}, 'role only on table elements'),
    'html-align': ({1, 2, 3}, 'align only centres cells: no left/right on a table or an image'),
    'html-width': ({1, 3}, 'no image'),
    'html-doctype': ({2}, 'the data says the doctype has no impact there'),
    'html-style': ({1, 6}, 'the style block is in the head and is far under 16 KB'),
    'css-text-decoration': ({2, 3}, 'one value (underline or none), no overline'),
}

# Features without which the title, the text, the button, the link or the footer is lost.
READING = {'html-body', 'html-table', 'html-div', 'html-h1-h6', 'css-background-color', 'css-font-size', 'css-padding', 'css-line-height', 'css-display', 'css-display-none'}

STATUS = {'y': Fraction(1), 'a': Fraction(1, 2), 'n': Fraction(0), 'u': Fraction(0)}
STATUS_NAME = {'y': 'supported', 'a': 'partly', 'n': 'not supported', 'u': 'unknown'}


def load(source):
    if re.match(r'https?://', source):
        with urllib.request.urlopen(source, timeout=60) as r:
            return json.load(r)
    return json.loads(Path(source).read_text())


class Scan(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.found = {}
        self.comments = []

    def add(self, key, where):
        self.found.setdefault(key, set()).add(where)

    def handle_decl(self, decl):
        if decl.lower().startswith('doctype'):
            self.add(('misc', 'doctype'), 'html')

    def handle_comment(self, data):
        self.add(('misc', 'comments'), 'comment')
        if data.lstrip().startswith('[if') and 'mso' in data:
            self.comments.append(data)

    def handle_starttag(self, tag, attrs):
        self.add(('el', tag), 'html')
        names = {n for n, _ in attrs}
        for n, v in attrs:
            self.add(('attr', n), tag)
            if n == 'style':
                css_declarations(v or '', self.add, 'inline')
            if tag == 'meta' and n == 'name' and v in ('color-scheme', 'supported-color-schemes'):
                self.add(('misc', 'meta color-scheme'), 'meta')
        if tag == 'style':
            self.in_style = True

    def handle_data(self, data):
        if getattr(self, 'in_style', False):
            style_block(data, self.add)

    def handle_endtag(self, tag):
        if tag == 'style':
            self.in_style = False


def css_declarations(text, add, where):
    for decl in text.split(';'):
        if ':' not in decl:
            continue
        prop, value = (x.strip() for x in decl.split(':', 1))
        prop = prop.lower()
        if prop.startswith('v-'):
            continue
        add(('css', prop), where)
        if f'{prop}:{value.lower().replace("!important", "").strip()}' in ('display:none',):
            add(('val', f'{prop}:{value.lower().replace("!important", "").strip()}'), where)
        if 'linear-gradient' in value:
            add(('misc', 'linear-gradient'), where)
        if '!important' in value:
            add(('misc', '!important'), where)
        if re.search(r'\d+px', value):
            add(('misc', 'px'), where)
        if re.search(r'\d+%', value):
            add(('misc', '%'), where)


def style_block(text, add):
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    for at in re.findall(r'@([a-z-]+)', text):
        add(('at', at), 'style block')
    if 'prefers-color-scheme' in text:
        add(('at', 'prefers-color-scheme'), 'style block')
    for selector in re.findall(r'(?:^|[{}])\s*([^{}@]+?)\s*\{', text):
        if re.search(r'\.[a-z]', selector):
            add(('attr', 'class'), 'style block')
    for body in re.findall(r'\{([^{}]*)\}', text):
        css_declarations(body, add, 'style block')


def scan(files):
    merged = {}
    mso_tags = set()
    for f in files:
        p = Scan()
        text = Path(f).read_text()
        p.feed(text)
        for k, w in p.found.items():
            merged.setdefault(k, set()).update(w)
        for c in p.comments:
            mso_tags.update(t.lower() for t in re.findall(r'<([a-zA-Z][\w:]*)', c))
    return merged, sorted(mso_tags)


def current_status(feature, family, platform):
    versions = feature['stats'].get(family, {}).get(platform)
    if not versions:
        return None
    version, value = list(versions.items())[-1]
    parts = value.split()
    return version, parts[0], {int(n[1:]) for n in parts[1:] if n.startswith('#') and n[1:].isdigit()}


def pct(fr):
    return f'{int(fr * 10000) / 100:.2f} %'


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    data = load(sys.argv[1])
    files = []
    for a in sys.argv[2:]:
        p = Path(a)
        files += sorted(p.glob('*.html')) if p.is_dir() else [p]
    if not files:
        sys.exit('no HTML file')
    found, mso = scan(files)
    bySlug = {f['slug']: f for f in data['data']}

    unknown = [k for k in found if k not in FEATURES]
    if unknown:
        sys.exit('Unknown features, add them to FEATURES: ' + ', '.join(f'{a}:{b}' for a, b in sorted(unknown)))

    # The targets: every platform the data lists for the programs.
    targets = sorted({(fam, plat) for f in data['data'] for fam in PROGRAMS for plat in f['stats'].get(fam, {})})
    slugs = sorted({FEATURES[k][0] for k in found if FEATURES[k][0]})
    missing = [s for s in slugs if s not in bySlug]
    if missing:
        sys.exit('Slug not in the data: ' + ', '.join(missing))

    print(f'# Audit input\n')
    print(f"- Data: Can I email, api_version {data['api_version']}, last_update_date {data['last_update_date']}")
    print(f'- Emails scanned: {len(files)} file(s): ' + ', '.join(Path(f).name for f in files))
    print(f'- Programs/platforms ({len(targets)}): ' + ', '.join(f'{PROGRAMS[a]} {b}' for a, b in targets))
    print(f'- Rule: supported 1, partly 0.5, not supported 0, declared fallback or inapplicable limit 1, unknown left out\n')

    print('## Features found\n')
    print('| Kind | Name | Where | Can I email entry |')
    print('|---|---|---|---|')
    for k in sorted(found):
        slug, note = FEATURES[k]
        print(f'| {k[0]} | `{k[1]}` | {", ".join(sorted(found[k]))} | {("`"+slug+"`") if slug else "none: " + note} |')
    print('\nOnly inside `<!--[if mso]>` (read by Outlook for Windows, comment text to everyone else; no entry in the data, not scored): '
          + ', '.join(f'`{t}`' for t in mso) + '.\n')

    # One value per pair. Labels: y, a, n, a* (limits do not apply), +fb (declared fallback),
    # u (unknown: left out of the score, counted in the "unknown as 0" line).
    total = Fraction(0)
    pairs = 0
    unknown_pairs = 0
    raw_total = Fraction(0)        # the data alone
    read_total = Fraction(0)
    read_pairs = 0
    read_fail = []
    rows = []
    per_program = {t: [Fraction(0), 0] for t in targets}
    for slug in slugs:
        feature = bySlug[slug]
        cells = []
        for t in targets:
            cur = current_status(feature, *t)
            code, notes = (cur[1], cur[2]) if cur else ('u', set())
            base = STATUS.get(code, Fraction(0))
            value, label = base, code
            if code == 'a' and slug in UNAFFECTED and notes and notes <= UNAFFECTED[slug][0]:
                value, label = Fraction(1), 'a*'
            fb = FALLBACKS.get(slug)
            if value < 1 and fb and (fb[1] == 'all' or t in fb[1]):
                value, label = Fraction(1), code + '+fb'
            if code == 'u':
                unknown_pairs += 1
                cells.append('u')
                continue
            raw_total += base
            total += value
            pairs += 1
            per_program[t][0] += value
            per_program[t][1] += 1
            if slug in READING:
                read_pairs += 1
                read_total += value
                if value < 1:
                    read_fail.append((slug, t))
            cells.append(label)
        rows.append((slug, cells))
    allpairs = pairs + unknown_pairs

    print('## Status per feature and program\n')
    print('Codes: y supported, a partly, n not supported, u unknown (left out of the score); `a*` partly, but the limits the data names do not touch how the email uses it; `+fb` counts 1 thanks to the declared fallback.\n')
    print('| Feature | ' + ' | '.join(f'{PROGRAMS[a]} {b}' for a, b in targets) + ' |')
    print('|---|' + '---|' * len(targets))
    for slug, cells in rows:
        print(f'| `{slug}` | ' + ' | '.join(cells) + ' |')

    print('\n## Limits that do not apply (`a*`)\n')
    print('| Feature | Notes of the data | Why they do not touch this email |')
    print('|---|---|---|')
    for slug in slugs:
        if slug in UNAFFECTED:
            n, why = UNAFFECTED[slug]
            notes_text = '; '.join(f'#{k}: {bySlug[slug].get("notes_by_num", {}).get(str(k), "")}' for k in sorted(n))
            print(f'| `{slug}` | {notes_text} | {why} |')

    print('\n## Declared fallbacks\n')
    print('| Feature | Fallback | Programs |')
    print('|---|---|---|')
    for slug in slugs:
        if slug in FALLBACKS:
            d, w = FALLBACKS[slug]
            print(f'| `{slug}` | {d} | {w} |')

    print('## Score per program\n')
    print('| Program | Sum | Pairs | Score |')
    print('|---|---|---|---|')
    for t in targets:
        s, n = per_program[t]
        print(f'| {PROGRAMS[t[0]]} {t[1]} | {s} | {n} | {pct(s / n)} |')

    print('\n## Score\n')
    print(f'- Features with an entry: {len(slugs)}; programs/platforms: {len(targets)}; pairs: {allpairs}, of which unknown in the data: {unknown_pairs}; scored pairs: {pairs}')
    print(f'- Data alone, no fallback (unknown left out): {raw_total} / {pairs} = {pct(raw_total / pairs)}')
    print(f'- With the declared fallbacks and the limits that do not apply: {total} / {pairs} = **{pct(total / pairs)}** (target 95 %)')
    print(f'- Same, unknown pairs counted 0: {total} / {allpairs} = {pct(total / allpairs)}')
    print(f'- Reading features ({", ".join(sorted(READING & set(slugs)))}): {read_total} / {read_pairs} = **{pct(read_total / read_pairs) if read_pairs else "n/a"}** (target 100 %)')
    for slug, t in read_fail:
        print(f'  - reading feature below 1: `{slug}` in {PROGRAMS[t[0]]} {t[1]}')
    sys.exit(0 if total / pairs >= Fraction(95, 100) and not read_fail else 1)


if __name__ == '__main__':
    main()
