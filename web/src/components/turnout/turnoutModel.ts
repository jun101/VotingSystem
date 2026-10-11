import type { Locale } from '@/lib/i18n/locale';
import type { TurnoutGroup, TurnoutPoint } from '@/lib/api/turnout';

/*
 * What the turnout page draws, worked out from the numbers the API gives (`participations`
 * only: nothing here knows what anyone voted). Pure functions, so the chart can be tested
 * without a screen.
 */

/** The share of a part in a whole, between 0 and 1 (0 when the whole is empty). */
export function share(part: number, whole: number): number {
  if (!(whole > 0) || !(part > 0)) return 0;

  return Math.min(1, part / whole);
}

/** A percentage with at most one decimal, in the language of the page: `33,3`. */
export function percentText(value: number, locale: Locale): string {
  return new Intl.NumberFormat(locale, {
    maximumFractionDigits: 1,
    minimumFractionDigits: 0,
  }).format(value);
}

/**
 * Round tick values from 0 up to a ceiling at or above `max`: a step of 1, 2, 5 or 10 times a
 * power of ten, so that about `count` ticks are drawn. `niceTicks(0)` is `[0, 1]`.
 */
export function niceTicks(max: number, count = 4): number[] {
  const top = Math.max(1, Math.ceil(max));
  const rough = top / Math.max(1, count);
  const power = Math.pow(10, Math.floor(Math.log10(rough)));
  const unit = rough / power;
  const factor = unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10;
  const step = Math.max(1, factor * power);
  const ticks: number[] = [];

  for (let value = 0; value < top + step; value += step) {
    ticks.push(value);
    if (value >= top) break;
  }

  return ticks;
}

export type ChartBox = {
  width: number;
  height: number;
  left: number;
  right: number;
  top: number;
  bottom: number;
};

export type ChartDot = { x: number; y: number; voted: number; total: number; at: string };

export type ChartShape = {
  /** The ceiling of the vertical scale (the last tick). */
  ceiling: number;
  ticks: { value: number; y: number }[];
  dots: ChartDot[];
  /** The cumulative line, as an SVG path (`''` with no point). */
  line: string;
  /** The same, closed down to the baseline, for the soft area under it. */
  area: string;
  /** Bars of the votes of each bucket. */
  bars: { x: number; y: number; width: number; height: number; voted: number }[];
  /** The baseline's y. */
  base: number;
};

function round(value: number): number {
  return Math.round(value * 10) / 10;
}

/**
 * The shape of the over-time chart in a box: one dot for each bucket, spread over the width
 * (a single bucket sits in the middle), the cumulative total as a line and the votes of each
 * bucket as bars.
 */
export function chartShape(points: readonly TurnoutPoint[], box: ChartBox): ChartShape {
  const inner = {
    width: box.width - box.left - box.right,
    height: box.height - box.top - box.bottom,
  };
  const base = box.top + inner.height;
  const peak = points.reduce((max, point) => Math.max(max, point.total), 0);
  const ticks = niceTicks(peak);
  const ceiling = ticks[ticks.length - 1] ?? 1;
  const yOf = (value: number) => round(base - (value / ceiling) * inner.height);
  const slot = points.length > 0 ? inner.width / points.length : inner.width;
  const xOf = (index: number) => round(box.left + slot * index + slot / 2);

  const dots = points.map((point, index) => ({
    x: xOf(index),
    y: yOf(point.total),
    voted: point.voted,
    total: point.total,
    at: point.at,
  }));

  const line = dots.map((dot, index) => `${index === 0 ? 'M' : 'L'}${dot.x} ${dot.y}`).join(' ');
  const first = dots[0];
  const last = dots[dots.length - 1];
  const area = first && last ? `${line} L${last.x} ${base} L${first.x} ${base} Z` : '';
  const barWidth = Math.max(2, Math.min(36, slot * 0.5));

  return {
    ceiling,
    ticks: ticks.map((value) => ({ value, y: yOf(value) })),
    dots,
    line,
    area,
    base,
    bars: points.map((point, index) => {
      const height = round((point.voted / ceiling) * inner.height);

      return {
        x: round(xOf(index) - barWidth / 2),
        y: round(base - height),
        width: round(barWidth),
        height,
        voted: point.voted,
      };
    }),
  };
}

/** The indexes of the buckets that get a label under the axis: at most `max`, first and last included. */
export function labelIndexes(length: number, max = 5): number[] {
  if (length <= 0) return [];
  if (length <= max) return Array.from({ length }, (_, index) => index);

  const picked = new Set<number>();

  for (let step = 0; step < max; step++) {
    picked.add(Math.round((step * (length - 1)) / (max - 1)));
  }

  return [...picked].sort((a, b) => a - b);
}

/** The label of a bucket on the axis, in the election's time zone: an hour, or a day. */
export function bucketLabel(
  at: string,
  bucket: 'hour' | 'day',
  locale: Locale,
  zone: string,
): string {
  const instant = new Date(at);
  const options: Intl.DateTimeFormatOptions =
    bucket === 'hour'
      ? { day: 'numeric', month: 'short', hour: 'numeric' }
      : { day: 'numeric', month: 'short' };

  try {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: zone }).format(instant);
  } catch {
    return new Intl.DateTimeFormat(locale, options).format(instant);
  }
}

export type GroupLine = {
  /** The group's UUID, `none` for the voters with no group. */
  key: string;
  name: string | null;
  voters: number;
  voted: number;
  share: number;
};

/** The group lines in the order the API gave them (the line with no group is last). */
export function groupLines(groups: readonly TurnoutGroup[]): GroupLine[] {
  return groups.map((line) => ({
    key: line.group ? line.group.id : 'none',
    name: line.group ? line.group.name : null,
    voters: line.voters,
    voted: line.voted,
    share: share(line.voted, line.voters),
  }));
}
