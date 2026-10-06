import Link from 'next/link';
import type { ReactNode } from 'react';
import { cx } from './cx';

/** The three areas of the product. Only the plain layout exists in this slice. */
export type PageShellVariant = 'plain' | 'voter' | 'public' | 'admin';

type PageShellProps = {
  /** Name of the product, from the message files. */
  productName: string;
  variant?: PageShellVariant;
  children: ReactNode;
};

export function PageShell({ productName, variant = 'plain', children }: PageShellProps) {
  return (
    <div data-variant={variant} className="min-h-screen bg-canvas">
      <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 md:py-10">
        <header>
          <Link
            href="/"
            className={cx(
              'font-display text-lg font-bold text-ink',
              'rounded-sm focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary',
            )}
          >
            {productName}
          </Link>
        </header>
        <main className="flex flex-col gap-6">{children}</main>
      </div>
    </div>
  );
}
