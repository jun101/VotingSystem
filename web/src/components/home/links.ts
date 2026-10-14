/** Class strings of the home page's links that look like buttons. */
const BASE =
  'ui-control inline-flex min-h-11 items-center justify-center gap-2 rounded-full border px-5 text-md font-semibold';

/** On the dark footer the usual outline is too dark: a light one. */
const ON_DARK =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

const ON_LIGHT =
  'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

export const linkStyles = {
  /** The main action, on a light surface. */
  primary: `${BASE} ${ON_LIGHT} border-primary bg-primary text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover`,
  /** The second action: white with a line. */
  outline: `${BASE} ${ON_LIGHT} border-line bg-surface text-primary-hover hover:border-primary-line hover:bg-primary-soft`,
  /** A quiet action: text only. */
  text: `${BASE} ${ON_LIGHT} border-transparent bg-transparent text-primary-hover hover:bg-primary-soft`,
  /** A plain text link on the dark footer. */
  footer: `rounded-sm text-base text-hero-ink-soft hover:text-surface hover:underline ${ON_DARK}`,
};
