import Link from 'next/link';
import type { ReactNode } from 'react';
import { cx } from '@/components/ui/cx';
import { focusRing } from './classes';
import { Icon, type IconName } from './Icon';

/** The key of each card: its test id, and the colours of its header. */
export type CardKey =
  'open-election' | 'todo' | 'figures' | 'activity' | 'latest' | 'getting-started' | 'institution';

/** The colours of a round icon: a token pair, text on its soft surface. */
export const TONES = {
  open: 'bg-status-open-soft text-status-open',
  scheduled: 'bg-status-scheduled-soft text-status-scheduled',
  draft: 'bg-status-draft-soft text-status-draft',
  published: 'bg-status-published-soft text-status-published',
  quiet: 'bg-line-soft text-ink-soft',
  warm: 'bg-warm-soft text-warm',
  teal: 'bg-teal-soft text-teal',
  primary: 'bg-primary-soft text-primary',
} as const;

export type Tone = keyof typeof TONES;

/**
 * One card of the dashboard: a 68 px header band in a gradient (its colours follow the card),
 * with a round icon, the title and, on the right, a counter or a link; then the body, which
 * fills the card (at least 300 px tall). It lifts on hover.
 */
export function DashCard({
  card,
  icon,
  title,
  aside,
  children,
  className,
}: {
  card: CardKey;
  icon: IconName;
  title: string;
  aside?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section
      data-testid={`dashboard-card-${card}`}
      className="lift flex min-h-75 min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-2"
    >
      <header
        data-testid={`dashboard-card-header-${card}`}
        data-head={card}
        className="dash-head relative flex h-17 shrink-0 items-center gap-3 overflow-hidden px-5"
      >
        <span
          aria-hidden="true"
          className="flex size-10 shrink-0 items-center justify-center rounded-full bg-glass-line"
        >
          <Icon name={icon} size={22} />
        </span>
        <h3 className="min-w-0 flex-1 truncate font-display text-xl font-extrabold">{title}</h3>
        {aside}
      </header>
      <div className={cx('flex flex-1 flex-col gap-3.5 px-5 pt-4.5 pb-5', className)}>
        {children}
      </div>
    </section>
  );
}

/** The chip on the right of a header: a counter, a state or a link. */
export const ASIDE =
  'inline-flex h-7.5 items-center gap-1.5 rounded-full bg-glass-line px-3 text-sm font-bold whitespace-nowrap';

/** An empty card: a large floating icon circle, one sentence and, where it helps, a button. */
export function Empty({
  icon,
  tone,
  text,
  children,
}: {
  icon: IconName;
  tone: Tone;
  text: string;
  children?: ReactNode;
}) {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-3 py-2 text-center text-md text-ink-soft">
      <span
        aria-hidden="true"
        data-testid="empty-art"
        className={cx(
          'float-art flex size-21 items-center justify-center rounded-full',
          TONES[tone],
        )}
      >
        <Icon name={icon} size={40} />
      </span>
      <p className="max-w-64">{text}</p>
      {children}
    </div>
  );
}

const ROW =
  'ui-control flex min-w-0 items-center gap-3 rounded-md px-3 py-2.5 text-md font-medium text-ink hover:bg-canvas';

/** A row of a list: a round icon, a title with a small line under it, and something at the end. */
export function Row({
  icon,
  tone,
  title,
  detail,
  end,
  href,
  struck = false,
  ...rest
}: {
  icon: IconName;
  tone: Tone;
  title: string;
  detail?: string;
  end?: ReactNode;
  href?: string;
  /** The title is struck through: a step that is done. */
  struck?: boolean;
  'data-testid'?: string;
  'data-done'?: string;
}) {
  const body = (
    <>
      <span
        aria-hidden="true"
        className={cx('flex size-9 shrink-0 items-center justify-center rounded-full', TONES[tone])}
      >
        <Icon name={icon} size={19} />
      </span>
      <span className="min-w-0 flex-1">
        <span className={cx('block break-words', struck && 'text-ink-soft line-through')}>
          {title}
        </span>
        {detail ? (
          <small className="block text-sm font-normal break-words text-ink-soft">{detail}</small>
        ) : null}
      </span>
      {end}
    </>
  );

  return href ? (
    <Link href={href} className={cx(ROW, focusRing)} {...rest}>
      {body}
    </Link>
  ) : (
    <div className={ROW} {...rest}>
      {body}
    </div>
  );
}
