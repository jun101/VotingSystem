import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ImageResponse } from 'next/og';
import { fetchPublicResults } from '@/lib/api/public';
import { isLocale } from '@/lib/i18n/locale';
import { getMessages, translate } from '@/lib/i18n/messages';
import { PREVIEW_COLORS } from '@/lib/public/previewColors';
import { electedNames } from '@/lib/public/share';

/*
 * The preview image of a published election, 1200 x 630: institution, title and the elected. It is
 * the picture a chat or a social network shows next to the link, and the "download the image" file.
 * Its colours are the design tokens' values, kept in `lib/public/previewColors.ts` (ImageResponse
 * cannot read CSS variables). No request leaves the server: the font is read from the installed
 * package, and without it the default font is used.
 */
const HERO = [
  PREVIEW_COLORS['hero-from'],
  PREVIEW_COLORS['hero-mid'],
  PREVIEW_COLORS['hero-to'],
] as const;
const ACCENT_LIGHT = PREVIEW_COLORS['accent-light'];
const SURFACE = PREVIEW_COLORS.surface;
const DEEP = PREVIEW_COLORS.deep;

const FONT_DIR = ['node_modules', '@fontsource', 'archivo', 'files'];

async function archivo(weight: 700 | 800): Promise<ArrayBuffer | null> {
  try {
    const file = await readFile(
      path.join(process.cwd(), ...FONT_DIR, `archivo-latin-${weight}-normal.woff`),
    );

    return file.buffer.slice(file.byteOffset, file.byteOffset + file.byteLength) as ArrayBuffer;
  } catch {
    return null;
  }
}

/** Keeps a long text inside the picture: cut at a word, with an ellipsis. */
function clip(text: string, max: number): string {
  if (Array.from(text).length <= max) return text;

  return `${Array.from(text)
    .slice(0, max - 1)
    .join('')
    .trimEnd()}…`;
}

function initials(name: string): string {
  const words = name.split(/[\s-]+/).filter((word) => word !== '');
  const letters =
    words.length > 1
      ? [Array.from(words[0]!)[0]!, Array.from(words[words.length - 1]!)[0]!]
      : Array.from(words[0] ?? '?').slice(0, 2);

  return letters.join('').toLocaleUpperCase();
}

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ election: string }> },
): Promise<Response> {
  const { election } = await params;
  const results = await fetchPublicResults(election);

  if (!results) {
    return new Response(null, { status: 404, headers: { 'Cache-Control': 'no-store' } });
  }

  const locale = isLocale(results.language) ? results.language : 'fr';
  const messages = getMessages(locale);
  const names = electedNames(results.ballots);
  const label = translate(
    messages,
    names.length > 0 ? 'public.image.elected' : 'public.image.results',
  );
  const line =
    names.length > 0
      ? clip(new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(names), 110)
      : clip(results.title, 80);

  const [bold, heavy] = await Promise.all([archivo(700), archivo(800)]);
  const fonts = [
    ...(bold
      ? [{ name: 'Archivo', data: bold, weight: 700 as const, style: 'normal' as const }]
      : []),
    ...(heavy
      ? [{ name: 'Archivo', data: heavy, weight: 800 as const, style: 'normal' as const }]
      : []),
  ];

  return new ImageResponse(
    <div
      style={{
        width: '100%',
        height: '100%',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        padding: 64,
        color: SURFACE,
        fontFamily: fonts.length > 0 ? 'Archivo' : 'sans-serif',
        backgroundImage: `linear-gradient(135deg, ${HERO[0]} 0%, ${HERO[1]} 45%, ${HERO[2]} 100%)`,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 24 }}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            width: 84,
            height: 84,
            borderRadius: 42,
            background: SURFACE,
            color: DEEP,
            fontSize: 34,
            fontWeight: 800,
          }}
        >
          {initials(results.institution.name)}
        </div>
        <div style={{ display: 'flex', fontSize: 38, fontWeight: 700 }}>
          {clip(results.institution.name, 48)}
        </div>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
        <div style={{ display: 'flex', fontSize: 70, fontWeight: 800, lineHeight: 1.05 }}>
          {clip(results.title, 70)}
        </div>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        <div style={{ display: 'flex', fontSize: 30, fontWeight: 700 }}>{label}</div>
        <div style={{ display: 'flex', fontSize: 52, fontWeight: 800, color: ACCENT_LIGHT }}>
          {line}
        </div>
      </div>
    </div>,
    {
      width: 1200,
      height: 630,
      ...(fonts.length > 0 ? { fonts } : {}),
      headers: { 'Cache-Control': 'public, max-age=60, s-maxage=60' },
    },
  );
}
