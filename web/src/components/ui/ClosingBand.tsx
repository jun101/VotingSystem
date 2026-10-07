import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type ClosingBandProps = Omit<ComponentProps<'section'>, 'title'> & {
  title: ReactNode;
  line?: ReactNode;
  /** The main action: an accent button. */
  action: ReactNode;
};

/** The band that ends a page: a title, a line and the main action. */
export function ClosingBand({ title, line, action, className, ...rest }: ClosingBandProps) {
  return (
    <section
      className={cx(
        'bg-hero flex flex-col items-start gap-4 rounded-lg p-6 text-surface md:flex-row md:items-center md:justify-between md:p-8',
        className,
      )}
      {...rest}
    >
      <div className="min-w-0">
        <h2 className="text-xl text-surface md:text-2xl">{title}</h2>
        {line ? <p className="mt-1 text-hero-ink-soft">{line}</p> : null}
      </div>
      <div className="shrink-0">{action}</div>
    </section>
  );
}
