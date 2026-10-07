'use client';

import { useCallback, useEffect, useRef, useState, type ComponentProps } from 'react';
import type { Locale } from '@/lib/i18n/locale';
import { useEnter } from './useEnter';

type CountUpProps = Omit<ComponentProps<'span'>, 'children'> & {
  value: number;
  locale: Locale;
  /** Written after the figure, with its own space if it needs one: " %". */
  suffix?: string;
  /** In milliseconds. */
  duration?: number;
};

/**
 * Counts from zero to its value when it enters the screen. The final value is in the HTML
 * from the start (it is right without JavaScript) and a screen reader reads that value
 * only: the counting digits are hidden from it.
 */
export function CountUp({ value, locale, suffix = '', duration = 1200, ...rest }: CountUpProps) {
  const ref = useRef<HTMLSpanElement>(null);
  const [shown, setShown] = useState(value);
  const frame = useRef(0);
  const current = useRef(value);
  const target = useRef(value);
  const mounted = useRef(false);

  const animate = useCallback(
    (from: number, to: number, length = duration) => {
      cancelAnimationFrame(frame.current);

      const start = performance.now();
      const step = (now: number) => {
        const progress = Math.min(1, (now - start) / length);
        // Ease out: quick at first, then settles.
        const next = Math.round(from + (to - from) * (1 - Math.pow(1 - progress, 3)));
        current.current = next;
        setShown(next);
        if (progress < 1) frame.current = requestAnimationFrame(step);
      };

      frame.current = requestAnimationFrame(step);
    },
    [duration],
  );

  // The first entrance counts up from zero.
  useEnter(ref, () => animate(0, target.current));

  // A later change of the value moves on from what is shown, never from zero.
  useEffect(() => {
    target.current = value;

    if (!mounted.current) {
      mounted.current = true;
      return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      // No movement: jump to the value on the next frame.
      animate(current.current, value, 1);
    } else if (current.current !== value) {
      animate(current.current, value);
    }
  }, [value, animate]);

  useEffect(() => () => cancelAnimationFrame(frame.current), []);

  const format = new Intl.NumberFormat(locale);

  return (
    <>
      <span ref={ref} aria-hidden="true" {...rest}>
        {format.format(shown)}
        {suffix}
      </span>
      <span className="sr-only">
        {format.format(value)}
        {suffix}
      </span>
    </>
  );
}
