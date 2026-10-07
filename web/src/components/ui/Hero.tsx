import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type HeroProps = Omit<ComponentProps<'section'>, 'title'> & {
  /** A small pill above the label, for a state ("Open"). */
  pill?: ReactNode;
  /** A short line above the title. */
  label?: ReactNode;
  /** The page's title: the one `h1`. */
  title: string;
  /** A word of the title (as written in it) shown in the accent colour. */
  accent?: string;
  lede?: ReactNode;
  /** Key figures: up to three `GlassTile`s. */
  figures?: ReactNode;
  /** Up to two tilted cards, shown from the `lg` breakpoint. */
  stage?: ReactNode;
  /** Title and one line only. */
  compact?: boolean;
  /** The two soft lights drift. Not on a ballot. */
  live?: boolean;
  'data-testid'?: string;
};

/** The showcase surface that opens a screen: the gradient, white text, content on top. */
export function Hero({
  pill,
  label,
  title,
  accent,
  lede,
  figures,
  stage,
  compact = false,
  live = false,
  className,
  'data-testid': testId,
  ...rest
}: HeroProps) {
  const at = accent ? title.indexOf(accent) : -1;
  const heading =
    accent && at >= 0 ? (
      <>
        {title.slice(0, at)}
        <span className="accent-word">{accent}</span>
        {title.slice(at + accent.length)}
      </>
    ) : (
      title
    );

  return (
    <section
      data-testid={testId}
      data-live={live ? 'true' : 'false'}
      data-compact={compact ? 'true' : 'false'}
      className={cx(
        'bg-hero relative w-full overflow-hidden text-surface',
        compact ? 'pt-20 pb-14' : 'pt-24 pb-24 md:pt-28 md:pb-28',
        className,
      )}
      {...rest}
    >
      <div aria-hidden="true" className="hero-light hero-light-a -top-24 -right-16 size-96" />
      <div aria-hidden="true" className="hero-light hero-light-b -bottom-32 left-1/4 size-80" />

      <div
        className={cx(
          'relative mx-auto grid w-full max-w-6xl items-center gap-8 px-4',
          stage && !compact ? 'lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]' : undefined,
        )}
      >
        <div className="flex min-w-0 flex-col gap-4">
          {!compact && pill ? <div className="flex">{pill}</div> : null}
          {!compact && label ? (
            <p className="text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
              {label}
            </p>
          ) : null}
          <h1
            data-testid={testId ? `${testId}-title` : undefined}
            className={cx(
              'text-surface',
              compact ? 'text-2xl md:text-3xl' : 'text-3xl md:text-4xl',
            )}
          >
            {heading}
          </h1>
          {lede ? (
            <p className={cx('max-w-xl text-hero-ink-soft', compact ? 'text-md' : 'text-lg')}>
              {lede}
            </p>
          ) : null}
          {!compact && figures ? (
            <div className="mt-2 grid grid-cols-3 gap-3 sm:gap-4">{figures}</div>
          ) : null}
        </div>

        {!compact && stage ? (
          <div className="hidden min-w-0 flex-col gap-5 pl-6 lg:flex">{stage}</div>
        ) : null}
      </div>
    </section>
  );
}
