'use client';

import type { ComponentProps } from 'react';
import { Pill, type PillTone } from '@/components/ui';
import type { ElectionStatus } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';

/** Draft and archived are quiet, scheduled and open take the accent colour, closed and published are teal. */
const TONES: Record<ElectionStatus, PillTone> = {
  draft: 'neutral',
  scheduled: 'primary',
  open: 'primary',
  closed: 'teal',
  published: 'teal',
  archived: 'neutral',
};

/** The status of an election, always in words (never by colour alone). */
export function StatusPill({
  status,
  ...rest
}: { status: ElectionStatus } & Omit<ComponentProps<typeof Pill>, 'tone' | 'children'>) {
  const { t } = useI18n();

  return (
    <Pill tone={TONES[status]} {...rest}>
      {t(`elections.status.${status}`)}
    </Pill>
  );
}
