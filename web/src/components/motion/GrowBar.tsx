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

/**
 * A bar that grows from zero to its value when it enters the screen. Decorative.
 * The track is what is watched: the bar itself has no width at the start, and a browser
 * does not report an element with no area as entering the screen.
 */
export function GrowBar({ value, className, trackClassName, style, ...rest }: GrowBarProps) {
  const track = useRef<HTMLDivElement>(null);
  const clamped = Math.min(1, Math.max(0, value));

  useEnter(track);

  return (
    <div
      ref={track}
      data-grow-track=""
      aria-hidden="true"
      className={cx('h-2 overflow-hidden rounded-full bg-line-soft', trackClassName)}
    >
      <div
        data-grow=""
        className={cx('h-full w-full rounded-full bg-primary', className)}
        style={{ '--grow': clamped, ...style } as CSSProperties}
        {...rest}
      />
    </div>
  );
}
