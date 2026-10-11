/*
 * The colours of the preview image (`/results/{election}/opengraph-image`). ImageResponse cannot
 * read CSS variables, so the VALUES of the design tokens are written here, in this one file, and
 * nowhere else outside `src/styles/tokens.css`. `previewColors.test.ts` fails when one differs
 * from the token it stands for.
 */
export const PREVIEW_COLORS = {
  'hero-from': '#a8349a',
  'hero-mid': '#7c5bd0',
  'hero-to': '#5468d4',
  'accent-light': '#f7d9a8',
  surface: '#ffffff',
  deep: '#1e2a5a',
} as const;
