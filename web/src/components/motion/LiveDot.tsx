import type { ComponentProps } from 'react';
import { cx } from '../ui/cx';

/** A dot that sends out a ring, forever. It takes the colour of the text around it. */
export function LiveDot({ className, ...rest }: ComponentProps<'span'>) {
  return <span aria-hidden="true" className={cx('live-dot', className)} {...rest} />;
}
