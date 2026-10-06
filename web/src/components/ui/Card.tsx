import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type CardProps = Omit<ComponentProps<'section'>, 'title'> & {
  title?: ReactNode;
  /** A button or a link, at the right of the title. */
  actions?: ReactNode;
};

export function Card({ title, actions, className, children, ...rest }: CardProps) {
  return (
    <section className={cx('rounded-lg border border-line bg-surface p-5', className)} {...rest}>
      {title || actions ? (
        <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
          {title ? <h2 className="text-lg font-bold text-ink">{title}</h2> : <span />}
          {actions}
        </header>
      ) : null}
      {children}
    </section>
  );
}
