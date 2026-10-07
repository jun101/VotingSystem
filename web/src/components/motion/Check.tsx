'use client';

import { useRef, type ComponentProps } from 'react';
import { cx } from '../ui/cx';
import { useEnter } from './useEnter';

type CheckProps = Omit<ComponentProps<'span'>, 'children'> & {
  /** What the mark says, for screen readers. */
  label: string;
};

/** A check mark that draws itself, with one ring pulse. */
export function Check({ label, className, ...rest }: CheckProps) {
  const ref = useRef<HTMLSpanElement>(null);

  useEnter(ref);

  return (
    <span
      ref={ref}
      data-check=""
      role="img"
      aria-label={label}
      className={cx('inline-flex size-12 text-surface', className)}
      {...rest}
    >
      <svg viewBox="0 0 48 48" aria-hidden="true" className="size-full overflow-visible">
        <circle className="check-halo fill-teal" cx="24" cy="24" r="20" />
        <circle className="fill-teal" cx="24" cy="24" r="20" />
        <path
          className="check-mark"
          d="M14 25l7 7 13-15"
          pathLength="1"
          fill="none"
          strokeWidth="4"
          strokeLinecap="round"
          strokeLinejoin="round"
          stroke="currentColor"
        />
      </svg>
    </span>
  );
}
