import type { ComponentProps } from 'react';
import { cx } from './cx';

export type PillTone = 'neutral' | 'primary' | 'teal' | 'warm' | 'danger';

type PillProps = ComponentProps<'span'> & {
  tone?: PillTone;
};

const tones: Record<PillTone, string> = {
  neutral: 'bg-line-soft text-ink-2',
  primary: 'bg-primary-soft text-primary-hover',
  teal: 'bg-teal-soft text-teal-ink',
  warm: 'bg-warm-soft text-warm-ink',
  danger: 'bg-warm-soft text-danger',
};

/** Status is never given by colour alone: a pill always holds text. */
export function Pill({ tone = 'neutral', className, children, ...rest }: PillProps) {
  return (
    <span
      className={cx(
        'inline-flex items-center rounded-full px-3 py-1 text-xs font-bold',
        tones[tone],
        className,
      )}
      {...rest}
    >
      {children}
    </span>
  );
}
