/** Class strings of the home page's links that look like buttons. */
const BASE =
  'ui-control inline-flex min-h-11 items-center justify-center gap-2 rounded-full border px-5 text-md font-semibold';

/** On the gradient the usual outline is too dark: a light one. */
const ON_GRADIENT =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

const ON_LIGHT =
  'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

export const linkStyles = {
  /** White, on the gradient or the dark band. */
  white: `${BASE} ${ON_GRADIENT} border-surface bg-surface text-primary-hover shadow-2 hover:bg-primary-soft`,
  /** Glass, on the gradient. */
  glass: `${BASE} ${ON_GRADIENT} border-glass-line bg-glass text-surface hover:bg-glass-line`,
  /** The main action, on a white card. */
  primary: `${BASE} ${ON_LIGHT} border-primary bg-primary text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover`,
  /** The second action, on a white card. */
  soft: `${BASE} ${ON_LIGHT} border-transparent bg-primary-soft text-primary-hover hover:border-primary-line`,
  /** A plain text link on the dark footer. */
  footer: `rounded-sm text-base text-hero-ink-soft hover:text-surface hover:underline ${ON_GRADIENT}`,
};
