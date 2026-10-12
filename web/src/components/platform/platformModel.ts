import type { PlatformFilters, PlatformInstitution } from '@/lib/api/platform';
import type { Locale } from '@/lib/i18n/locale';

export const ELECTION_STATUSES = [
  'draft',
  'scheduled',
  'open',
  'closed',
  'published',
  'archived',
] as const;

export type ElectionStatus = (typeof ELECTION_STATUSES)[number];

/** The tone classes of each election status chip (tokens only). */
export const STATUS_TONES: Record<ElectionStatus, string> = {
  draft: 'bg-status-draft-soft text-status-draft',
  scheduled: 'bg-status-scheduled-soft text-status-scheduled',
  open: 'bg-status-open-soft text-status-open',
  closed: 'bg-line-soft text-ink-2',
  published: 'bg-status-published-soft text-status-published',
  archived: 'bg-canvas text-ink-soft',
};

/** The statuses that have at least one election, in the usual order, with their number. */
export function electionChips(
  counts: PlatformInstitution['counts']['elections'],
): { status: ElectionStatus; count: number }[] {
  return ELECTION_STATUSES.filter((status) => counts[status] > 0).map((status) => ({
    status,
    count: counts[status],
  }));
}

export function electionTotal(counts: PlatformInstitution['counts']['elections']): number {
  return ELECTION_STATUSES.reduce((sum, status) => sum + counts[status], 0);
}

export function isFiltering(filters: PlatformFilters): boolean {
  return filters.q.trim() !== '' || filters.status !== 'all';
}

export function hasMore(shown: number, total: number): boolean {
  return shown < total;
}

/** A date written in the language, in UTC so the server and the browser agree; null when there is none. */
export function dayOf(instant: string | null, locale: Locale): string | null {
  if (!instant) return null;

  const date = new Date(instant);

  if (Number.isNaN(date.getTime())) return null;

  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(date);
}
