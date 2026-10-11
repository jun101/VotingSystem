import Link from 'next/link';
import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { LogoMark } from '@/components/ui/LogoMark';

/**
 * The frame of the public pages: the backdrop band (the gradient lies behind, nothing is written
 * on it), the product's name in a white pill on it, the gradient hero card with the page's own
 * header, and the content climbing onto the band. Server rendered, no script.
 */
export function PublicShell({
  productName,
  kicker,
  kickerIcon = 'flag',
  title,
  titleTestId,
  hero,
  skip,
  children,
  testId,
  footer,
}: {
  productName: string;
  kicker: string;
  kickerIcon?: IconName;
  title: string;
  titleTestId?: string;
  /** Under the title, inside the hero card: chips, an institution row. */
  hero?: ReactNode;
  /** The label of the "skip to the content" link. */
  skip: string;
  children: ReactNode;
  testId?: string;
  footer?: ReactNode;
}) {
  return (
    <div data-testid={testId} className="flex min-h-screen flex-col bg-canvas">
      <a
        href="#content"
        className="sr-only z-50 rounded-full bg-surface px-4 py-2 font-semibold text-ink focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
      >
        {skip}
      </a>
      <header className="bg-backdrop relative overflow-hidden">
        <div className="relative mx-auto w-full max-w-6xl px-4 pt-4 pb-16 md:pt-5 md:pb-20 min-[1600px]:max-w-7xl">
          <Link
            href="/"
            className="inline-flex min-h-11 w-fit items-center gap-2.5 rounded-full bg-surface px-4 font-display text-lg font-extrabold text-ink shadow-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-surface"
          >
            <LogoMark size={28} />
            <span className="truncate">{productName}</span>
          </Link>
          <section
            aria-labelledby="public-title"
            className="bg-hero relative mt-4 flex flex-col gap-4 overflow-hidden rounded-xl px-5 py-6 text-surface shadow-3 md:mt-5 md:px-8 md:py-8"
          >
            <p className="flex items-center gap-2 text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
              <Icon name={kickerIcon} size={18} />
              {kicker}
            </p>
            <h1
              id="public-title"
              data-testid={titleTestId}
              className="font-display text-3xl leading-tight font-extrabold break-words text-surface md:text-4xl"
            >
              {title}
            </h1>
            {hero}
          </section>
        </div>
      </header>
      <main
        id="content"
        className="relative z-10 mx-auto -mt-10 w-full max-w-6xl flex-1 px-4 pb-10 md:-mt-12 min-[1600px]:max-w-7xl"
      >
        {children}
      </main>
      {footer}
    </div>
  );
}
