'use client';

import { useRef, type ComponentProps } from 'react';
import { useEnter } from '@/components/motion/useEnter';

/**
 * A list whose items come in one after the other (the reveal of the motion kit, staggered):
 * the same effect as `Reveal stagger`, on a real `ul`, so the grid of cards is a list for
 * assistive technology. Off under "reduce motion" and when already in view.
 */
export function RevealList({ children, ...rest }: ComponentProps<'ul'>) {
  const ref = useRef<HTMLUListElement>(null);

  useEnter(ref);

  return (
    <ul ref={ref} data-reveal="stagger" {...rest}>
      {children}
    </ul>
  );
}
