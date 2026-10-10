import type { IconName } from '@/components/admin/Icon';
import type { ElectionStatus } from '@/lib/api/elections';

/** The icon of each status: on the status tiles, the badge and the faint mark of a card's cover. */
export const STATUS_ICONS: Record<ElectionStatus, IconName> = {
  draft: 'draft',
  scheduled: 'clock',
  open: 'flag',
  closed: 'check',
  published: 'chart',
  archived: 'archive',
};

/** The large faint mark of a cover: an open election shows the ballot box. */
export const COVER_ICONS: Record<ElectionStatus, IconName> = {
  ...STATUS_ICONS,
  open: 'ballot',
};
