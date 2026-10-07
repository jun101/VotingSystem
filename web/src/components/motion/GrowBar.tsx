'use client';

import { useRef, type ComponentProps, type CSSProperties } from 'react';
import { cx } from '../ui/cx';
import { useEnter } from './useEnter';

type GrowBarProps = Omit<ComponentProps<'div'>, 'children'> & {
  /** Between 0 and 1. */
  value: number;
  /** Classes of the track (the bar takes its own from `className`). */
  trackClassName?: string;
};

/** A bar that grows from zero to its value when it enters the screen. Decorative. */
export function GrowBar({ value, className, trackClassName, style, ...rest }: GrowBarProps) {
  const ref = useRef<HTMLDivElement>(null);
  const clamped = Math.min(1, Math.max(0, value));

  useEnter(ref);

  return (
    <div
      aria-hidden="true"
      className={cx('h-2 overflow-hidden rounded-full bg-line-soft', trackClassName)}
    >
      <div
        ref={ref}
        data-grow=""
        className={cx('h-full w-full rounded-full bg-primary', className)}
        style={{ '--grow': clamped, ...style } as CSSProperties}
        {...rest}
      />
    </div>
  );
}
