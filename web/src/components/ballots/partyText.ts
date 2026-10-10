import { pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `0 parti`, `1 parti`, `3 partis` (the French plural counts zero as one). */
export function partiesText(count: number, locale: Locale, t: T): string {
  return t(`parties.count.${pluralForm(locale, count)}`, { count });
}

/** `Aucun candidat`, `1 candidat`, `3 candidats`. */
export function candidatesText(count: number, locale: Locale, t: T): string {
  if (count === 0) return t('parties.row.none');

  return t(`parties.row.candidates.${pluralForm(locale, count)}`, { count });
}
