/** Class names shared by the parts of the admin shell. Tokens only. */

export const focusRing =
  'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

/** On the periwinkle panel of the side menu the usual outline is too dark: a light one. */
export const panelFocus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

export const linkPrimary =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center rounded-full border border-primary bg-primary px-5 text-base font-semibold text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover ' +
  focusRing;

export const linkSecondary =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center rounded-full border border-line-strong bg-surface px-5 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft ' +
  focusRing;

/** The main action on a gradient surface, as a link: the accent button. Text is never white on this coral. */
export const linkAccent =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center rounded-full border border-transparent bg-accent-gradient px-5 text-base font-semibold text-deep shadow-button hover:border-accent-light ' +
  focusRing;
