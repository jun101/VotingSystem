import type { ComponentProps } from 'react';
import { cx } from '../ui/cx';

type FloatProps = ComponentProps<'div'> & {
  /** Tilted to the left (a) or to the right (b). */
  tilt?: 'a' | 'b';
};

/** Drifts a few pixels over 5 to 6 seconds, slightly tilted. Decoration only. */
export function Float({ tilt = 'a', className, ...rest }: FloatProps) {
  return <div className={cx(tilt === 'a' ? 'float-a' : 'float-b', className)} {...rest} />;
}
