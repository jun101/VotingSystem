import { pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `0 candidat`, `1 candidat`, `3 candidats` (the French plural counts zero as one). */
export function candidateCountText(count: number, locale: Locale, t: T): string {
  return t(`candidates.count.${pluralForm(locale, count)}`, { count });
}

/** `25 sur 80`: the live counter under a field. */
export function counterText(count: number, max: number, t: T): string {
  return t('candidates.form.counter', { count, max });
}
