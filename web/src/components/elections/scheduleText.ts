import {
  clock,
  durationBetween,
  joinList,
  longDay,
  pluralForm,
  yearIn,
} from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `Lundi 12 octobre 2026 à 08:00`, in the zone of the election. */
export function momentText(
  instant: Date,
  locale: Locale,
  zone: string,
  t: T,
  withYear = true,
): string {
  return t('elections.schedule.at', {
    date: longDay(instant, locale, zone, withYear),
    time: clock(instant, locale, zone),
  });
}

/** `4 jours et 7 heures`: the length of the vote, or null when the end is not after the start. */
export function durationText(start: Date, end: Date, locale: Locale, t: T): string | null {
  const length = durationBetween(start, end);

  if (!length) return null;

  const parts: string[] = [];

  if (length.days > 0) {
    parts.push(
      t(`elections.schedule.unit.day.${pluralForm(locale, length.days)}`, { count: length.days }),
    );
  }
  if (length.hours > 0) {
    parts.push(
      t(`elections.schedule.unit.hour.${pluralForm(locale, length.hours)}`, {
        count: length.hours,
      }),
    );
  }
  if (length.minutes > 0) {
    parts.push(
      t(`elections.schedule.unit.minute.${pluralForm(locale, length.minutes)}`, {
        count: length.minutes,
      }),
    );
  }

  return joinList(locale, parts);
}

/** The line `Lundi 12 octobre 2026 à 08:00 → Vendredi 16 octobre à 15:00`: the year is written once when it is the same. */
export function scheduleLine(start: Date, end: Date, locale: Locale, zone: string, t: T): string {
  const sameYear = yearIn(start, zone) === yearIn(end, zone);

  return t('elections.schedule.line', {
    start: momentText(start, locale, zone, t),
    end: momentText(end, locale, zone, t, !sameYear),
  });
}
