import type { Election } from '@/lib/api/elections';
import { dateRange } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `12 au 16 oct. 2026`: the dates of an election in its own time zone, the words from the message files. */
export function rangeText(
  election: Pick<Election, 'starts_at' | 'ends_at' | 'timezone'>,
  locale: Locale,
  t: T,
): string {
  const range = dateRange(election.starts_at, election.ends_at, locale, election.timezone);

  if (!range) return '';

  switch (range.kind) {
    case 'sameDay':
      return t('elections.dates.sameDay', range.values);
    case 'sameMonth':
      return t('elections.dates.sameMonth', range.values);
    case 'sameYear':
      return t('elections.dates.sameYear', range.values);
    default:
      return t('elections.dates.otherYears', range.values);
  }
}
