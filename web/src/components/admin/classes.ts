/** Class names shared by the parts of the admin shell. Tokens only. */

export const focusRing =
  'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

/** On the navy of the side menu the usual outline is too dark: a light one. */
export const navyFocus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

export const linkPrimary =
  'ui-control inline-flex min-h-11 items-center justify-center rounded border border-primary bg-primary px-4 text-base font-semibold text-surface hover:border-primary-hover hover:bg-primary-hover ' +
  focusRing;

export const linkSecondary =
  'ui-control inline-flex min-h-11 items-center justify-center rounded border border-line-strong bg-surface px-4 text-base font-semibold text-ink hover:bg-surface-alt ' +
  focusRing;

/** The one main action of a screen, as a link: the accent button. Text is never white on this orange. */
export const linkAccent =
  'ui-control inline-flex min-h-11 items-center justify-center rounded border border-transparent bg-accent-gradient px-4 text-base font-semibold text-navy-deep hover:border-accent-light ' +
  focusRing;
