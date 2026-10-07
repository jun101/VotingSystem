import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

export type NoticeTone = 'info' | 'warm' | 'teal' | 'danger';

type NoticeProps = Omit<ComponentProps<'div'>, 'title'> & {
  tone?: NoticeTone;
  title?: ReactNode;
  /** A button or a link, under the text. */
  actions?: ReactNode;
};

const tones: Record<NoticeTone, string> = {
  info: 'border-primary-line bg-primary-soft text-ink',
  warm: 'border-warm bg-warm-softer text-warm-ink',
  teal: 'border-teal bg-teal-soft text-teal-ink',
  danger: 'border-danger-line bg-warm-softer text-danger',
};

/**
 * A message in the page: a state to know about, a thing to do, an error that belongs to
 * no field. Give it `role="alert"` for an error that appears after an action, and
 * `role="status"` for a confirmation.
 */
export function Notice({
  tone = 'info',
  title,
  actions,
  className,
  children,
  ...rest
}: NoticeProps) {
  return (
    <div
      data-tone={tone}
      className={cx('flex flex-col gap-2 rounded border p-4', tones[tone], className)}
      {...rest}
    >
      {title ? <p className="font-semibold">{title}</p> : null}
      {children ? <div className="text-base">{children}</div> : null}
      {actions ? <div className="mt-1 flex flex-wrap items-center gap-3">{actions}</div> : null}
    </div>
  );
}
