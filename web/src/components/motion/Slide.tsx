import type { ComponentProps } from 'react';
import { cx } from '../ui/cx';

type SlideProps = ComponentProps<'div'> & {
  from?: 'left' | 'right';
};

/** An entrance from the left or the right in 300 ms, for panels and steps. */
export function Slide({ from = 'right', className, ...rest }: SlideProps) {
  return (
    <div
      className={cx(from === 'left' ? 'slide-in-left' : 'slide-in-right', className)}
      {...rest}
    />
  );
}
