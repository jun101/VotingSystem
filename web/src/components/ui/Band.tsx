import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

export type BandTone = 'default' | 'warm';

type BandProps = Omit<ComponentProps<'div'>, 'children'> & {
  tone?: BandTone;
  /** An avatar or an icon. */
  avatar?: ReactNode;
  label?: ReactNode;
  name: ReactNode;
  /** The one highlighted figure, in the light accent. */
  figure?: ReactNode;
  'data-testid'?: string;
};

/** A strip of the showcase gradient: avatar, small label, name and one figure. */
export function Band({
  tone = 'default',
  avatar,
  label,
  name,
  figure,
  className,
  'data-testid': testId,
  ...rest
}: BandProps) {
  return (
    <div
      data-testid={testId}
      data-tone={tone}
      className={cx(
        'flex items-center gap-4 rounded-lg px-4 py-3 text-surface sm:px-5',
        tone === 'warm' ? 'bg-hero-warm' : 'bg-hero',
        className,
      )}
      {...rest}
    >
      {avatar ? <div className="shrink-0">{avatar}</div> : null}
      <div className="min-w-0 flex-1">
        {label ? (
          <p className="text-xs font-semibold tracking-wide text-hero-ink-soft uppercase">
            {label}
          </p>
        ) : null}
        <p className="truncate font-display text-lg font-bold">{name}</p>
      </div>
      {figure ? (
        <p
          data-testid={testId ? `${testId}-figure` : undefined}
          className="shrink-0 font-display text-2xl font-bold text-accent-light"
        >
          {figure}
        </p>
      ) : null}
    </div>
  );
}
