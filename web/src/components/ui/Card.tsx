import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type CardProps = Omit<ComponentProps<'section'>, 'title'> & {
  title?: ReactNode;
  /** A button or a link, at the right of the title. */
  actions?: ReactNode;
  /** No shadow and smaller corners: a card inside another surface. */
  flat?: boolean;
};

export function Card({ title, actions, flat = false, className, children, ...rest }: CardProps) {
  return (
    <section
      className={cx(
        'border border-line bg-surface p-5',
        flat ? 'rounded-md' : 'rounded-lg shadow-1',
        className,
      )}
      {...rest}
    >
      {title || actions ? (
        <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
          {title ? <h2 className="text-lg font-extrabold text-ink">{title}</h2> : <span />}
          {actions}
        </header>
      ) : null}
      {children}
    </section>
  );
}
