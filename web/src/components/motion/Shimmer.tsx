import type { ComponentProps } from 'react';
import { cx } from '../ui/cx';

/** A loading placeholder with a light sweeping across. Give it a size with `className`. */
export function Shimmer({ className, ...rest }: ComponentProps<'div'>) {
  return <div aria-hidden="true" className={cx('shimmer rounded', className)} {...rest} />;
}
