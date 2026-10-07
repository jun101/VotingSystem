import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type ActionBarProps = Omit<ComponentProps<'div'>, 'children'> & {
  /** A one-line reminder, on the left. */
  reminder?: ReactNode;
  /** The main action: an accent button. Full width on a phone. */
  children: ReactNode;
};

/** A bar fixed to the bottom of the screen: a reminder on the left, the main action on the right. */
export function ActionBar({ reminder, children, className, ...rest }: ActionBarProps) {
  return (
    <div
      className={cx(
        'fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface',
        'pb-[env(safe-area-inset-bottom)]',
        className,
      )}
      {...rest}
    >
      <div className="mx-auto flex w-full max-w-6xl flex-col gap-2 px-4 py-3 md:flex-row md:items-center md:justify-between md:gap-4">
        {reminder ? <p className="min-w-0 truncate text-ink-soft">{reminder}</p> : <span />}
        <div className="flex shrink-0 flex-col md:block [&>button]:w-full md:[&>button]:w-auto">
          {children}
        </div>
      </div>
    </div>
  );
}
