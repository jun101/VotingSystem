/*
 * Local time in a named time zone, with the browser's own `Intl` and nothing else (no time
 * zone library). The API stores UTC and the election's zone; the person types a local date and
 * time in that zone (`<input type="datetime-local">`, the text `2026-10-12T08:00`).
 */

/** The text `YYYY-MM-DDTHH:mm` of a `datetime-local` field. */
const LOCAL = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/;

const formatters = new Map<string, Intl.DateTimeFormat>();

/** Whether this browser can compute times in the zone (its `Intl` knows it). */
export function isSupportedZone(zone: string): boolean {
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: zone });

    return true;
  } catch {
    return false;
  }
}

/**
 * A zone the browser does not know is read as UTC for display only, rather than crashing the
 * page; `fromLocalInput` and `toLocalInput` do not convert in such a zone.
 */
function formatterFor(zone: string): Intl.DateTimeFormat {
  const cached = formatters.get(zone);

  if (cached) return cached;

  let made: Intl.DateTimeFormat;

  try {
    made = new Intl.DateTimeFormat('en-US', {
      timeZone: zone,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  } catch {
    made = formatterFor('UTC');
  }

  formatters.set(zone, made);

  return made;
}

type Parts = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
};

/** The wall clock of an instant in a zone. */
export function wallClock(instant: Date, zone: string): Parts {
  const found: Record<string, number> = {};

  for (const part of formatterFor(zone).formatToParts(instant)) {
    if (part.type !== 'literal') found[part.type] = Number(part.value);
  }

  return {
    year: found.year ?? 1970,
    month: found.month ?? 1,
    day: found.day ?? 1,
    // Some engines write midnight as 24 with `h23` missing; 24 is 0.
    hour: (found.hour ?? 0) % 24,
    minute: found.minute ?? 0,
    second: found.second ?? 0,
  };
}

/** Milliseconds the zone is ahead of UTC at this instant (negative to the west). */
function offsetAt(instant: Date, zone: string): number {
  const p = wallClock(instant, zone);
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);

  return asUtc - Math.floor(instant.getTime() / 1000) * 1000;
}

const pad = (n: number, width = 2) => String(n).padStart(width, '0');

/** An instant (ISO text, UTC) as the `datetime-local` text of the zone. */
export function toLocalInput(iso: string, zone: string): string {
  const instant = new Date(iso);

  if (Number.isNaN(instant.getTime()) || !isSupportedZone(zone)) return '';

  const p = wallClock(instant, zone);

  return `${pad(p.year, 4)}-${pad(p.month)}-${pad(p.day)}T${pad(p.hour)}:${pad(p.minute)}`;
}

export type ZonedInstant = {
  /** The UTC instant. */
  utc: Date;
  /** The typed time does not exist in the zone (a clock change skips it): the nearest valid one was used. */
  adjusted: boolean;
};

/**
 * The UTC instant of a local date and time in a zone, or null when the text is not a complete
 * date and time.
 *
 * - A zone this browser does not know gives null: nothing is converted as if it were UTC.
 * - A time that happens twice (the clock goes back) is read as its first occurrence, east and
 *   west of UTC alike.
 * - A time that never happens (the clock jumps forward) is moved forward by the length of the
 *   jump, to the nearest time that exists; `adjusted` says so.
 */
export function fromLocalInput(local: string, zone: string): ZonedInstant | null {
  const found = LOCAL.exec(local);

  if (!found || !isSupportedZone(zone)) return null;

  const [year, month, day, hour, minute] = found.slice(1).map(Number) as [
    number,
    number,
    number,
    number,
    number,
  ];

  // The date must exist (30 February does not).
  const probe = new Date(Date.UTC(year, month - 1, day, hour, minute));

  if (
    probe.getUTCFullYear() !== year ||
    probe.getUTCMonth() !== month - 1 ||
    probe.getUTCDate() !== day ||
    hour > 23 ||
    minute > 59
  ) {
    return null;
  }

  const wall = probe.getTime();
  const DAY = 24 * 60 * 60 * 1000;
  // The offsets in force a day before and a day after: one, or two when a clock change is near.
  const offsets = [
    ...new Set([offsetAt(new Date(wall - DAY), zone), offsetAt(new Date(wall + DAY), zone)]),
  ];

  // An offset is right for the text when the instant it gives has that offset in the zone.
  const valid = offsets
    .map((offset) => wall - offset)
    .filter((ms) => offsetAt(new Date(ms), zone) === wall - ms);

  // Twice valid: the clock went back and the text happens twice; the earlier instant is first.
  if (valid.length > 0) return { utc: new Date(Math.min(...valid)), adjusted: false };

  // In the jump: moved forward past it, by the offset in force before it.
  return { utc: new Date(wall - Math.min(...offsets)), adjusted: true };
}
