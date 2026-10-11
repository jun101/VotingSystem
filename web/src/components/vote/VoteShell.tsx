'use client';

import type { ReactNode } from 'react';
import { LogoMark } from '@/components/ui/LogoMark';
import { cx } from '@/components/ui/cx';
import { LanguageToggle } from './LanguageToggle';
import { useVote } from './VoteProvider';

/**
 * The frame of every voting screen: the gradient band with the institution and the language
 * switch, the screen's own header (`hero`) under them, and the content climbing onto the band.
 * `bar` is the fixed bottom bar of a ballot or the review.
 */
export function VoteShell({
  hero,
  children,
  bar,
  live = false,
  testId,
}: {
  hero: ReactNode;
  children: ReactNode;
  bar?: ReactNode;
  /** The lights drift: code entry and confirmation only. */
  live?: boolean;
  testId?: string;
}) {
  const { info } = useVote();

  return (
    <div data-testid={testId} className="flex min-h-screen flex-col bg-canvas">
      <header
        data-live={live ? 'true' : 'false'}
        className="bg-hero relative overflow-hidden text-surface"
      >
        <div aria-hidden="true" className="hero-light hero-light-a -top-24 -right-16 size-96" />
        <div aria-hidden="true" className="hero-light hero-light-b -bottom-32 left-1/4 size-80" />
        <div className="relative mx-auto w-full max-w-6xl px-4 pt-4 pb-16 md:pt-5 md:pb-20">
          <div className="flex items-center justify-between gap-3">
            <p className="flex min-w-0 items-center gap-2.5 font-display text-lg font-extrabold">
              <LogoMark size={32} />
              <span className="truncate">{info.institution}</span>
            </p>
            <LanguageToggle />
          </div>
          <div className="mt-5 md:mt-6">{hero}</div>
        </div>
      </header>
      <main
        className={cx(
          'relative z-10 mx-auto -mt-10 w-full max-w-6xl flex-1 px-4 md:-mt-12',
          bar ? 'pb-6' : 'pb-10',
        )}
      >
        {children}
      </main>
      {bar ? (
        // Sticky, not fixed: a fixed bar is as wide as the layout viewport, which a phone makes wider
        // than the page by the width of the scroll bar.
        <footer className="sticky bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)]">
          <div className="mx-auto w-full max-w-6xl px-4 py-3">{bar}</div>
        </footer>
      ) : null}
    </div>
  );
}
