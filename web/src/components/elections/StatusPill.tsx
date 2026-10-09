'use client';

import type { ComponentProps } from 'react';
import { LiveDot } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { ElectionStatus } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';

/** The chip pairs of the design: text on a soft surface. Closed and archived are quiet. */
const CHIPS: Record<ElectionStatus, string> = {
  draft: 'bg-status-draft-soft text-status-draft',
  scheduled: 'bg-status-scheduled-soft text-status-scheduled',
  open: 'bg-status-open-soft text-status-open',
  closed: 'bg-line-soft text-ink-soft',
  published: 'bg-status-published-soft text-status-published',
  archived: 'bg-line-soft text-ink-soft',
};

/** The status of an election, always in words (never by colour alone). An open one has a live dot. */
export function StatusPill({
  status,
  className,
  ...rest
}: { status: ElectionStatus } & Omit<ComponentProps<'span'>, 'children'>) {
  const { t } = useI18n();

  return (
    <span
      data-status={status}
      className={cx(
        'inline-flex h-6 items-center gap-1.5 rounded-full px-2.5 text-xs font-bold tracking-wide',
        CHIPS[status],
        className,
      )}
      {...rest}
    >
      {status === 'open' ? <LiveDot /> : null}
      {t(`elections.status.${status}`)}
    </span>
  );
}
