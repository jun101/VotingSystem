import { clock, longDay } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `Lundi 12 octobre à 08:00`, in the election's own time zone. */
export function whenText(iso: string, locale: Locale, zone: string, t: T): string {
  const instant = new Date(iso);

  if (Number.isNaN(instant.getTime())) return iso;

  return t('vote.period.dayAt', {
    day: longDay(instant, locale, zone, false),
    time: clock(instant, locale, zone),
  });
}

export type Remaining = { days: number; hours: number; minutes: number; seconds: number };

/** Whole days, hours, minutes and seconds until `target`; all zero when it has passed. */
export function remaining(target: string, now: number): Remaining {
  const total = Math.max(0, Math.floor((new Date(target).getTime() - now) / 1000));

  return {
    days: Math.floor(total / 86_400),
    hours: Math.floor((total % 86_400) / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}

export function isOver(left: Remaining): boolean {
  return left.days + left.hours + left.minutes + left.seconds === 0;
}

/** `Lundi 12 octobre 2026 à 12:07`: the moment the vote was recorded, in the election's time zone. */
export function recordedText(iso: string, locale: Locale, zone: string, t: T): string {
  const instant = new Date(iso);

  if (Number.isNaN(instant.getTime())) return iso;

  return t('vote.period.dayAt', {
    day: longDay(instant, locale, zone, true),
    time: clock(instant, locale, zone),
  });
}
