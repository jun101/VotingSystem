import type { ComponentProps } from 'react';
import { cx } from './cx';

/** A small translucent pill for the top of a `Hero`: a state, a category. */
export function HeroPill({ className, ...rest }: ComponentProps<'span'>) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-2 rounded-full border border-glass-line bg-glass px-3 py-1 text-xs font-semibold text-surface',
        className,
      )}
      {...rest}
    />
  );
}
