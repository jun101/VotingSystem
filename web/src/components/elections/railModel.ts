import type { Election } from '@/lib/api/elections';
import { wallClock } from '@/lib/format/zonedTime';
import { addDays, dayText, monthGrid, type Month } from './pickerLogic';

/*
 * What the right rail of the elections list shows: a month calendar and the things left to do.
 * Days are the text `YYYY-MM-DD` of the election's own time zone.
 */

type Dated = Pick<Election, 'starts_at' | 'ends_at' | 'timezone'>;

const DAY_MS = 86_400_000;

function dayIn(iso: string, zone: string): string | null {
  const instant = new Date(iso);

  if (Number.isNaN(instant.getTime())) return null;

  const p = wallClock(instant, zone);

  return dayText(p.year, p.month, p.day);
}

/** The first and the last day of an election, in its own zone. */
export function daysOf(election: Dated): { first: string; last: string } | null {
  const first = dayIn(election.starts_at, election.timezone);
  const last = dayIn(election.ends_at, election.timezone);

  return first && last && last >= first ? { first, last } : null;
}

/** The election the calendar looks at: the one that starts next, or the one now running. */
export function nearestUpcoming<T extends Dated>(elections: readonly T[], now: Date): T | null {
  const open = elections
    .filter((election) => new Date(election.ends_at).getTime() >= now.getTime())
    .sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());

  return open[0] ?? null;
}

export type CalendarDay = {
  day: string;
  /** The day number to write. */
  number: number;
  /** A day of the neighbouring month that fills the week. */
  outside: boolean;
  event: boolean;
  today: boolean;
};

export type CalendarMonth = { month: Month; zone: string; weeks: CalendarDay[][] };

/**
 * The month of the nearest upcoming election in its zone (from the day it starts, or today when
 * it is already running), else the current month in `fallbackZone`. Every day is marked when an
 * election is running that day, and today is marked. The days of the weeks that spill over the
 * month are given too, marked in the same way.
 */
export function calendarFor(
  elections: readonly Election[],
  now: Date,
  fallbackZone: string,
): CalendarMonth {
  const next = nearestUpcoming(elections, now);
  const zone = next?.timezone ?? fallbackZone;
  const clock = wallClock(now, zone);
  const today = dayText(clock.year, clock.month, clock.day);
  const startDay = next ? dayIn(next.starts_at, zone) : null;
  const shown = startDay && startDay > today ? startDay : today;
  const month: Month = { year: Number(shown.slice(0, 4)), month: Number(shown.slice(5, 7)) };
  const spans = elections.map(daysOf).filter((span) => span !== null);
  const cells = monthGrid(month);
  const monthStart = dayText(month.year, month.month, 1);
  const lead = cells.indexOf(monthStart);
  const days: CalendarDay[] = cells.map((_, index) => {
    const day = addDays(monthStart, index - lead);

    return {
      day,
      number: Number(day.slice(8, 10)),
      outside: day.slice(0, 7) !== monthStart.slice(0, 7),
      event: spans.some((span) => day >= span.first && day <= span.last),
      today: day === today,
    };
  });
  const weeks: CalendarDay[][] = [];

  for (let index = 0; index < days.length; index += 7) weeks.push(days.slice(index, index + 7));

  return { month, zone, weeks };
}

export type TodoItem = {
  election: Election;
  kind: 'draft' | 'soon';
  /** For `soon`: whole days until it starts (0 today). */
  inDays: number;
};

const SOON_DAYS = 7;
const LIMIT = 6;

/**
 * What is left to do: a draft to finish, and an election that starts within seven days. The
 * ones about to start come first, then the drafts, the latest start first as in the list.
 */
export function todoFor(elections: readonly Election[], now: Date): TodoItem[] {
  const soon: TodoItem[] = [];
  const drafts: TodoItem[] = [];

  for (const election of elections) {
    if (election.status === 'draft') {
      drafts.push({ election, kind: 'draft', inDays: 0 });

      continue;
    }

    const wait = new Date(election.starts_at).getTime() - now.getTime();

    if (election.status !== 'archived' && wait > 0 && wait <= SOON_DAYS * DAY_MS) {
      soon.push({ election, kind: 'soon', inDays: daysUntil(election, now) });
    }
  }

  soon.sort((a, b) => a.election.starts_at.localeCompare(b.election.starts_at));

  return [...soon, ...drafts].slice(0, LIMIT);
}

/** Whole calendar days from today to the day the election starts, in its zone. */
export function daysUntil(election: Dated, now: Date): number {
  const first = dayIn(election.starts_at, election.timezone);
  const today = dayIn(now.toISOString(), election.timezone);

  if (!first || !today) return 0;

  return Math.round((Date.parse(`${first}T12:00:00Z`) - Date.parse(`${today}T12:00:00Z`)) / DAY_MS);
}
