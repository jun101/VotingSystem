import { pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `0 poste`, `1 poste`, `4 postes` (the French plural counts zero as one). */
export function countText(count: number, locale: Locale, t: T): string {
  return t(`ballots.count.${pluralForm(locale, count)}`, { count });
}

/** `1 siège`, `3 sièges`. */
export function seatsText(seats: number, locale: Locale, t: T): string {
  return t(`ballots.seats.${pluralForm(locale, seats)}`, { count: seats });
}
