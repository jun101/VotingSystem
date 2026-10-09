import type { ReactNode } from 'react';

/*
 * One inline SVG set on a 24 x 24 grid, drawn with the text colour (stroke 2, rounded ends), the
 * symbols of the large-screen mockup. Decoration only: always `aria-hidden`, and the text it
 * stands for is always written beside it.
 */

type Glyph = { stroke?: number; shapes: ReactNode };

const SYMBOLS = {
  grid: {
    shapes: (
      <>
        <rect x="3" y="3" width="7" height="7" rx="2" />
        <rect x="14" y="3" width="7" height="7" rx="2" />
        <rect x="3" y="14" width="7" height="7" rx="2" />
        <rect x="14" y="14" width="7" height="7" rx="2" />
      </>
    ),
  },
  ballot: {
    shapes: (
      <>
        <rect x="3" y="12" width="18" height="9" rx="2" />
        <path d="M8 12V6l4-3 4 3v6" />
        <path d="M9 7.5l2 2 4-4" />
      </>
    ),
  },
  people: {
    shapes: (
      <>
        <circle cx="9" cy="8" r="3.5" />
        <path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5" />
        <path d="M16 5a3.5 3.5 0 010 6.5M18 14.8c2 .6 3.2 2.4 3.5 5.2" />
      </>
    ),
  },
  log: {
    shapes: (
      <>
        <path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z" />
        <path d="M9 12l2 2 4-4" />
      </>
    ),
  },
  school: { shapes: <path d="M3 10l9-6 9 6M5 10v10h14V10M10 20v-5h4v5" /> },
  plus: { stroke: 2.5, shapes: <path d="M12 5v14M5 12h14" /> },
  minus: { stroke: 2.5, shapes: <path d="M5 12h14" /> },
  copy: {
    shapes: (
      <>
        <rect x="9" y="9" width="11" height="11" rx="2" />
        <path d="M5 15V6a2 2 0 012-2h9" />
      </>
    ),
  },
  edit: { shapes: <path d="M4 20h4L19 9l-4-4L4 16z" /> },
  open: { shapes: <path d="M5 12h14M13 6l6 6-6 6" /> },
  calendar: {
    shapes: (
      <>
        <rect x="3" y="5" width="18" height="16" rx="3" />
        <path d="M3 10h18M8 3v4M16 3v4" />
      </>
    ),
  },
  clock: {
    shapes: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </>
    ),
  },
  trash: { shapes: <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /> },
  chart: { shapes: <path d="M4 20V10M10 20V4M16 20v-8M22 20H2" /> },
  flag: { shapes: <path d="M5 21V4M5 4h11l-2 4 2 4H5" /> },
  draft: {
    shapes: (
      <>
        <path d="M6 3h9l4 4v14H6z" />
        <path d="M9 13h6M9 17h4" />
      </>
    ),
  },
  list: { shapes: <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" /> },
  qr: {
    shapes: (
      <>
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <path d="M14 14h3v3h-3zM20 14v.01M14 20h3M20 17v4" />
      </>
    ),
  },
  sun: {
    shapes: (
      <>
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
      </>
    ),
  },
  moon: { shapes: <path d="M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z" /> },
  globe: {
    shapes: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" />
      </>
    ),
  },
  check: { stroke: 2.6, shapes: <path d="M5 12l5 5 9-10" /> },
  left: { stroke: 2.4, shapes: <path d="M15 6l-6 6 6 6" /> },
  right: { stroke: 2.4, shapes: <path d="M9 6l6 6-6 6" /> },
  pulse: { shapes: <path d="M3 12h4l3-8 4 16 3-8h4" /> },
  mail: {
    shapes: (
      <>
        <rect x="3" y="5" width="18" height="14" rx="3" />
        <path d="M3 8l9 6 9-6" />
      </>
    ),
  },
  rocket: {
    shapes: (
      <>
        <path d="M5 15c-1 1-2 4-2 6 2 0 5-1 6-2M14 5c3-2 6-2 7-2 0 1 0 4-2 7l-6 6-5-5z" />
        <circle cx="15" cy="9" r="1.5" />
      </>
    ),
  },
  archive: { shapes: <path d="M3 5h18v4H3zM5 9v10h14V9M10 13h4" /> },
} satisfies Record<string, Glyph>;

export type IconName = keyof typeof SYMBOLS;

type IconProps = { size?: number } & (
  { name: IconName; path?: never } | { path: string; name?: never }
);

/** A 24 x 24 line icon, by name or by the path of a single stroke; decoration only. */
export function Icon({ name, path, size = 18 }: IconProps) {
  const symbol: Glyph | null = name ? SYMBOLS[name] : null;

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={symbol?.stroke ?? 2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      className="shrink-0"
    >
      {symbol ? symbol.shapes : <path d={path} />}
    </svg>
  );
}
