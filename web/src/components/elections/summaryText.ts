import type { Locale } from '@/lib/i18n/locale';

/*
 * Small date texts of the election page, in the election's own time zone. A zone this browser
 * does not know falls back to the device's.
 */

function format(
  instant: Date,
  locale: Locale,
  zone: string,
  options: Intl.DateTimeFormatOptions,
): string {
  try {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: zone }).format(instant);
  } catch {
    return new Intl.DateTimeFormat(locale, options).format(instant);
  }
}

/** `lun. 2 nov.`, with the year when asked: `lun. 2 nov. 2026`. */
export function shortDay(instant: Date, locale: Locale, zone: string, withYear: boolean): string {
  return format(instant, locale, zone, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    ...(withYear ? { year: 'numeric' } : {}),
  });
}

/** The three lines of a day badge: the month, the day number and the weekday, all short. */
export function dayBadge(
  instant: Date,
  locale: Locale,
  zone: string,
): { month: string; day: string; weekday: string } {
  return {
    month: format(instant, locale, zone, { month: 'short' }),
    day: format(instant, locale, zone, { day: 'numeric' }),
    weekday: format(instant, locale, zone, { weekday: 'short' }),
  };
}

/** True when the two instants fall in the same year in the zone. */
export function sameYear(a: Date, b: Date, zone: string): boolean {
  return format(a, 'en', zone, { year: 'numeric' }) === format(b, 'en', zone, { year: 'numeric' });
}
