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
        hero ? 'text-surface focus-visible:ring-hero-mid' : 'text-ink',
        focusRing,
      )}
    >
      {productName}
    </Link>
  );

  return (
    <div data-variant={variant} className="min-h-screen bg-canvas">
      {hero ? (
        <div className="relative">
          {/* The name of the product sits on the hero, over its top edge. */}
          <header className="absolute inset-x-0 top-0 z-10 mx-auto w-full max-w-6xl px-4 pt-6">
            {link}
          </header>
          {hero}
        </div>
      ) : null}
      <div
        className={cx(
          'mx-auto flex w-full flex-col gap-6 px-4',
          hero ? 'max-w-6xl' : 'max-w-3xl',
          hero && overlap ? 'relative z-10 -mt-12' : 'pt-6 md:pt-10',
          withActionBar ? 'pb-32' : 'pb-6 md:pb-10',
        )}
      >
        {hero ? null : <header>{link}</header>}
        <main className="flex flex-col gap-6">{children}</main>
      </div>
    </div>
  );
}
