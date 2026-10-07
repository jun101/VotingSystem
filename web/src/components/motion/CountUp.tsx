'use client';

import { useCallback, useRef, useState, type ComponentProps } from 'react';
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
 * from the start, so it is right without JavaScript and for screen readers.
 */
export function CountUp({ value, locale, suffix = '', duration = 1200, ...rest }: CountUpProps) {
  const ref = useRef<HTMLSpanElement>(null);
  const [shown, setShown] = useState(value);

  const run = useCallback(() => {
    const start = performance.now();
    const step = (now: number) => {
      const progress = Math.min(1, (now - start) / duration);
      // Ease out: quick at first, then settles.
      setShown(Math.round(value * (1 - Math.pow(1 - progress, 3))));
      if (progress < 1) requestAnimationFrame(step);
    };

    setShown(0);
    requestAnimationFrame(step);
  }, [value, duration]);

  useEnter(ref, run);

  return (
    <span ref={ref} {...rest}>
      {new Intl.NumberFormat(locale).format(shown)}
      {suffix}
    </span>
  );
}
