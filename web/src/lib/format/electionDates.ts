import type { Locale } from '@/lib/i18n/locale';
import { wallClock } from './zonedTime';

/*
 * How the dates of an election are written: in the election's own time zone (the API sends UTC),
 * in the language of the page. The words around the dates come from the message files; these
 * functions only produce the pieces.
 */

/** An unknown zone is shown in UTC rather than crashing the page. */
function safeZone(zone: string): string {
  try {
    new Intl.DateTimeFormat('en', { timeZone: zone });

    return zone;
  } catch {
    return 'UTC';
  }
}

function format(
  instant: Date,
  locale: Locale,
  zone: string,
  options: Intl.DateTimeFormatOptions,
): string {
  return new Intl.DateTimeFormat(locale, { ...options, timeZone: safeZone(zone) }).format(instant);
}

const capital = (text: string) => text.charAt(0).toLocaleUpperCase() + text.slice(1);

/** `Lundi 12 octobre 2026`, or without the year. */
export function longDay(instant: Date, locale: Locale, zone: string, withYear = true): string {
  return capital(
    format(instant, locale, zone, {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      ...(withYear ? { year: 'numeric' } : {}),
    }),
  );
}

/** `08:00` (`8:00 AM` in English). */
export function clock(instant: Date, locale: Locale, zone: string): string {
  return format(instant, locale, zone, { hour: '2-digit', minute: '2-digit' });
}

/** The year of an instant in a zone. */
export function yearIn(instant: Date, zone: string): number {
  return wallClock(instant, safeZone(zone)).year;
}

/** Whether two instants fall on the same calendar day, month, or year in the zone. */
function same(a: Date, b: Date, zone: string): { day: boolean; month: boolean; year: boolean } {
  const x = wallClock(a, safeZone(zone));
  const y = wallClock(b, safeZone(zone));
  const year = x.year === y.year;
  const month = year && x.month === y.month;

  return { year, month, day: month && x.day === y.day };
}

export type DateRange =
  | { kind: 'sameDay'; values: { date: string } }
  | { kind: 'sameMonth'; values: { from: string; to: string; month: string; year: string } }
  | { kind: 'sameYear'; values: { from: string; to: string; year: string } }
  | { kind: 'otherYears'; values: { from: string; to: string } };

/**
 * The pieces of `12 au 16 oct. 2026`: the kind of range and the values the message for that kind
 * needs. The words ("au", "to") are the message's.
 */
export function dateRange(
  startIso: string,
  endIso: string,
  locale: Locale,
  zone: string,
): DateRange | null {
  const start = new Date(startIso);
  const end = new Date(endIso);

  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;

  const shared = same(start, end, zone);
  const dayOnly = (instant: Date) => format(instant, locale, zone, { day: 'numeric' });
  const monthShort = (instant: Date) => format(instant, locale, zone, { month: 'short' });
  const year = (instant: Date) => format(instant, locale, zone, { year: 'numeric' });
  const dayAndMonth = (instant: Date) =>
    format(instant, locale, zone, { day: 'numeric', month: 'short' });
  const full = (instant: Date) =>
    format(instant, locale, zone, { day: 'numeric', month: 'short', year: 'numeric' });

  if (shared.day) return { kind: 'sameDay', values: { date: full(start) } };

  if (shared.month) {
    return {
      kind: 'sameMonth',
      values: {
        from: dayOnly(start),
        to: dayOnly(end),
        month: monthShort(start),
        year: year(start),
      },
    };
  }

  if (shared.year) {
    return {
      kind: 'sameYear',
      values: { from: dayAndMonth(start), to: dayAndMonth(end), year: year(start) },
    };
  }

  return { kind: 'otherYears', values: { from: full(start), to: full(end) } };
}

export type Duration = { days: number; hours: number; minutes: number };

/** Whole days, hours and minutes between two instants; null when the end is not after the start. */
export function durationBetween(start: Date, end: Date): Duration | null {
  const total = Math.floor((end.getTime() - start.getTime()) / 60_000);

  if (!Number.isFinite(total) || total <= 0) return null;

  return {
    days: Math.floor(total / 1440),
    hours: Math.floor((total % 1440) / 60),
    minutes: total % 60,
  };
}

/** `one` or `other`, the two forms the message files hold. */
export function pluralForm(locale: Locale, count: number): 'one' | 'other' {
  return new Intl.PluralRules(locale).select(count) === 'one' ? 'one' : 'other';
}

/** `4 jours et 7 heures`, joined by the language's own "and". */
export function joinList(locale: Locale, items: string[]): string {
  return new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(items);
}
