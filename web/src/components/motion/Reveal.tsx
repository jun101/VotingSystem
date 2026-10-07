'use client';

import { useRef, type ComponentProps } from 'react';
import { useEnter } from './useEnter';

type RevealProps = ComponentProps<'div'> & {
  /** Children follow each other 60 ms apart, eight at most, instead of the block alone. */
  stagger?: boolean;
};

/** Fades in and rises 16 px, once, when it enters the screen. Visible without JavaScript. */
export function Reveal({ stagger = false, children, ...rest }: RevealProps) {
  const ref = useRef<HTMLDivElement>(null);

  useEnter(ref);

  return (
    <div ref={ref} data-reveal={stagger ? 'stagger' : ''} {...rest}>
      {children}
    </div>
  );
}
