import Link from 'next/link';
import type { ReactNode } from 'react';
import { cx } from './cx';

/** The three areas of the product. Only the plain layout exists in this slice. */
export type PageShellVariant = 'plain' | 'voter' | 'public' | 'admin';

type PageShellProps = {
  /** Name of the product, from the message files. */
  productName: string;
  variant?: PageShellVariant;
  /** A `Hero`, shown across the whole width above the content. */
  hero?: ReactNode;
  /** With a hero: the first cards climb about 50 px onto it (the default). */
  overlap?: boolean;
  /** Room under the content for a fixed `ActionBar`. */
  withActionBar?: boolean;
  children: ReactNode;
};

const focusRing =
  'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

/** On the navy hero the usual outline is too dark: a light one, from the tokens. */
const heroFocus =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

export function PageShell({
  productName,
  variant = 'plain',
  hero,
  overlap = true,
  withActionBar = false,
  children,
}: PageShellProps) {
  const link = (
    <Link
      href="/"
      className={cx(
        'rounded-sm font-display text-lg font-bold',
        hero ? 'text-surface' : 'text-ink',
        hero ? heroFocus : focusRing,
      )}
    >
      {productName}
    </Link>
  );

  if (hero) {
    return (
      <div data-variant={variant} className="min-h-screen bg-canvas">
        <div className="relative">
          {/* The name of the product sits on the hero, over its top edge, outside <main>. */}
          <header className="absolute inset-x-0 top-0 z-10 mx-auto w-full max-w-6xl px-4 pt-6">
            {link}
          </header>
          <main>
            {hero}
            <div
              className={cx(
                'mx-auto flex w-full max-w-6xl flex-col gap-6 px-4',
                overlap ? 'relative z-10 -mt-12' : 'pt-6 md:pt-10',
                withActionBar ? 'pb-32' : 'pb-6 md:pb-10',
              )}
            >
              {children}
            </div>
          </main>
        </div>
      </div>
    );
  }

  return (
    <div data-variant={variant} className="min-h-screen bg-canvas">
      <div
        className={cx(
          'mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-6 md:pt-10',
          withActionBar ? 'pb-32' : 'pb-6 md:pb-10',
        )}
      >
        <header>{link}</header>
        <main className="flex flex-col gap-6">{children}</main>
      </div>
    </div>
  );
}
