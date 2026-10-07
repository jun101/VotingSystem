import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type GlassTileProps = Omit<ComponentProps<'div'>, 'children'> & {
  /** The large figure. */
  figure: ReactNode;
  /** A short label under it. */
  label: ReactNode;
};

/** A translucent tile with a thin light border, for a key figure on a showcase surface. */
export function GlassTile({ figure, label, className, ...rest }: GlassTileProps) {
  return (
    <div
      className={cx('min-w-0 rounded-md border border-glass-line bg-glass p-3 sm:p-4', className)}
      {...rest}
    >
      <p className="font-display text-2xl font-bold break-words text-surface sm:text-3xl">
        {figure}
      </p>
      <p className="mt-1 text-xs break-words text-hero-ink-soft sm:text-sm">{label}</p>
    </div>
  );
}
