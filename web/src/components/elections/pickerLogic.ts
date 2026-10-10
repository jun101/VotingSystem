import { wallClock } from '@/lib/format/zonedTime';

/*
 * The calendar maths of the date and time picker. Days are the text `YYYY-MM-DD` and local
 * date-times the text `YYYY-MM-DDTHH:mm` of a `datetime-local` field: civil dates of the
 * election's time zone, added and compared in UTC so the browser's own zone never enters. Only
 * "today" needs the zone, and it comes from `wallClock` of `zonedTime`.
 */

export type Range = { starts: string; ends: string };
export type Which = 'starts' | 'ends';
export type RangeMark = 'start' | 'in' | 'end';
export type PresetId = 'one-day' | 'tomorrow' | 'next-monday' | 'in-a-week';

export const DEFAULT_START = '08:00';
export const DEFAULT_END = '17:00';
/** The length given to a date added next to a picked one: 8:00 to 17:00. */
const DEFAULT_LENGTH = 9 * 60;
const MINUTE_STEP = 5;
const DAY_MS = 86_400_000;

const pad = (n: number, width = 2) => String(n).padStart(width, '0');
const LOCAL = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/;
const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

export type Month = { year: number; month: number };

/** The day text of a year, a month (1 to 12) and a day. */
export function dayText(year: number, month: number, day: number): string {
  return `${pad(year, 4)}-${pad(month)}-${pad(day)}`;
}

function dayMs(day: string): number | null {
  const found = DAY.exec(day);

  if (!found) return null;

  const [year, month, date] = [Number(found[1]), Number(found[2]), Number(found[3])];
  const ms = Date.UTC(year, month - 1, date);
  const probe = new Date(ms);

  return probe.getUTCFullYear() === year &&
    probe.getUTCMonth() === month - 1 &&
    probe.getUTCDate() === date
    ? ms
    : null;
}

function textOfMs(ms: number): string {
  const d = new Date(ms);

  return dayText(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate());
}

/** The day `count` days after (or before, when negative) a day. */
export function addDays(day: string, count: number): string {
  const ms = dayMs(day);

  return ms === null ? day : textOfMs(ms + count * DAY_MS);
}

/** 0 for Sunday to 6 for Saturday. */
export function weekdayOf(day: string): number {
  const ms = dayMs(day);

  return ms === null ? 0 : new Date(ms).getUTCDay();
}

/** The month of a day text, or null. */
export function monthOf(day: string): Month | null {
  const ms = dayMs(day);

  if (ms === null) return null;

  const d = new Date(ms);

  return { year: d.getUTCFullYear(), month: d.getUTCMonth() + 1 };
}

/** The month `count` months after (or before) a month. */
export function addMonths(from: Month, count: number): Month {
  const index = from.year * 12 + (from.month - 1) + count;

  return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}

/**
 * The cells of a month, weeks starting on Monday: a day text for each day, null for the blanks
 * before the first and after the last, so the length is a multiple of seven.
 */
export function monthGrid(month: Month): (string | null)[] {
  const first = dayText(month.year, month.month, 1);
  const lead = (weekdayOf(first) + 6) % 7;
  const length = new Date(Date.UTC(month.year, month.month, 0)).getUTCDate();
  const cells: (string | null)[] = Array.from({ length: lead }, () => null);

  for (let day = 1; day <= length; day += 1) cells.push(dayText(month.year, month.month, day));
  while (cells.length % 7 !== 0) cells.push(null);

  return cells;
}

/** The day and the time `HH:MM` of a local date-time text, or null when it is not complete. */
export function splitLocal(local: string): { day: string; time: string } | null {
  const found = LOCAL.exec(local);

  if (!found || dayMs(`${found[1]}-${found[2]}-${found[3]}`) === null) return null;
  if (Number(found[4]) > 23 || Number(found[5]) > 59) return null;

  return { day: `${found[1]}-${found[2]}-${found[3]}`, time: `${found[4]}:${found[5]}` };
}

/** Minutes of a local date-time on a plain calendar (no zone), or null. */
export function minutesOf(local: string): number | null {
  const parts = splitLocal(local);

  if (!parts) return null;

  const ms = dayMs(parts.day)!;
  const [hour, minute] = parts.time.split(':').map(Number) as [number, number];

  return ms / 60_000 + hour * 60 + minute;
}

/** The local date-time text of minutes on a plain calendar. */
export function localOf(minutes: number): string {
  const d = new Date(minutes * 60_000);

  return `${dayText(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}

/** Today in the election's time zone. */
export function todayIn(zone: string, now: Date = new Date()): string {
  const p = wallClock(now, zone);

  return dayText(p.year, p.month, p.day);
}

/** `start`, `in` or `end` for a day inside the voting days; undefined outside. One day is only its start. */
export function markOf(day: string, range: Range): RangeMark | undefined {
  const from = splitLocal(range.starts)?.day;
  const to = splitLocal(range.ends)?.day;

  if (!from || !to || to < from) return undefined;
  if (day === from) return 'start';
  if (day === to) return 'end';

  return day > from && day < to ? 'in' : undefined;
}

/**
 * A new value for one of the two fields. The other one stays, unless the end would no longer be
 * after the start: then it moves so the length the vote had is kept. A date next to an empty
 * one is given nine hours (8:00 to 17:00 by default). A half typed other field is left alone.
 */
export function setField(which: Which, value: string, range: Range): Range {
  const next = minutesOf(value);

  if (next === null) return { ...range, [which]: value };

  const start = minutesOf(range.starts);
  const end = minutesOf(range.ends);
  const length = start !== null && end !== null && end > start ? end - start : DEFAULT_LENGTH;

  if (which === 'starts') {
    if (range.ends === '') return { starts: value, ends: localOf(next + DEFAULT_LENGTH) };
    if (end === null || next < end) return { starts: value, ends: range.ends };

    return { starts: value, ends: localOf(next + length) };
  }

  if (range.starts === '') return { starts: localOf(next - DEFAULT_LENGTH), ends: value };
  if (start === null || next > start) return { starts: range.starts, ends: value };

  return { starts: localOf(next - length), ends: value };
}

/** The day and time of the field being edited, or what to start from when it is empty. */
function currentOf(which: Which, range: Range, today: string): { day: string; time: string } {
  const own = splitLocal(range[which]);

  if (own) return own;

  const other = splitLocal(range[which === 'starts' ? 'ends' : 'starts']);

  return {
    day: other?.day ?? today,
    time: which === 'starts' ? DEFAULT_START : DEFAULT_END,
  };
}

/** A click on a day: that day for the field being edited, its time kept. */
export function pickDay(which: Which, day: string, range: Range, today: string): Range {
  const { time } = currentOf(which, range, today);

  return setField(which, `${day}T${time}`, range);
}

/** A time chip (`HH:MM`): that time on the day of the field being edited. */
export function pickTime(which: Which, time: string, range: Range, today: string): Range {
  const { day } = currentOf(which, range, today);

  return setField(which, `${day}T${time}`, range);
}

/** The hour plus or minus one, or the minutes by five, inside their own limits (no carry). */
export function stepTime(
  which: Which,
  unit: 'hour' | 'minute',
  direction: 1 | -1,
  range: Range,
  today: string,
): Range {
  const { day, time } = currentOf(which, range, today);
  const [hour, minute] = time.split(':').map(Number) as [number, number];
  const nextHour = unit === 'hour' ? (hour + direction + 24) % 24 : hour;
  const nextMinute = unit === 'minute' ? (minute + direction * MINUTE_STEP + 60) % 60 : minute;

  return setField(which, `${day}T${pad(nextHour)}:${pad(nextMinute)}`, range);
}

/** The first Monday after a day. */
export function nextMonday(day: string): string {
  return addDays(day, (1 - weekdayOf(day) + 7) % 7 || 7);
}

/** The quick choices, from today (in the zone) and the dates now in the fields. */
export function presetRange(id: PresetId, range: Range, today: string): Range {
  const at = (from: string, to: string = from): Range => ({
    starts: `${from}T${DEFAULT_START}`,
    ends: `${to}T${DEFAULT_END}`,
  });

  switch (id) {
    case 'one-day':
      return at(splitLocal(range.starts)?.day ?? addDays(today, 1));
    case 'tomorrow':
      return at(addDays(today, 1));
    case 'next-monday': {
      const monday = nextMonday(today);

      return at(monday, addDays(monday, 4));
    }
    default:
      return at(addDays(today, 7));
  }
}

export const PRESETS: readonly PresetId[] = ['tomorrow', 'next-monday', 'in-a-week', 'one-day'];
