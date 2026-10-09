import { describe, expect, it } from 'vitest';
import {
  addDays,
  addMonths,
  markOf,
  minutesOf,
  localOf,
  monthGrid,
  nextMonday,
  pickDay,
  pickTime,
  presetRange,
  setField,
  splitLocal,
  stepTime,
  todayIn,
  weekdayOf,
} from './pickerLogic';

const TODAY = '2026-10-09';

describe('the day grid', () => {
  it('starts on a Monday, with blanks before the 1st and after the last, in whole weeks', () => {
    const cells = monthGrid({ year: 2026, month: 10 });

    // 1 October 2026 is a Thursday.
    expect(cells.slice(0, 4)).toEqual([null, null, null, '2026-10-01']);
    expect(cells.filter(Boolean)).toHaveLength(31);
    expect(cells.at(-1)).toBeNull();
    expect(cells.length % 7).toBe(0);
  });

  it('has no blank before a month that starts on a Monday', () => {
    const cells = monthGrid({ year: 2026, month: 6 });

    expect(cells[0]).toBe('2026-06-01');
    expect(cells.filter(Boolean)).toHaveLength(30);
  });

  it('knows a leap February', () => {
    expect(monthGrid({ year: 2028, month: 2 }).filter(Boolean)).toHaveLength(29);
    expect(monthGrid({ year: 2027, month: 2 }).filter(Boolean)).toHaveLength(28);
  });

  it('moves between months across a year', () => {
    expect(addMonths({ year: 2026, month: 12 }, 1)).toEqual({ year: 2027, month: 1 });
    expect(addMonths({ year: 2026, month: 1 }, -1)).toEqual({ year: 2025, month: 12 });
  });

  it('adds days over a month end', () => {
    expect(addDays('2026-10-30', 3)).toBe('2026-11-02');
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
    expect(weekdayOf('2026-11-02')).toBe(1);
  });
});

describe('the local text', () => {
  it('is read in parts, and refused when incomplete or impossible', () => {
    expect(splitLocal('2026-11-02T08:30')).toEqual({ day: '2026-11-02', time: '08:30' });
    expect(splitLocal('2026-11-02')).toBeNull();
    expect(splitLocal('2026-02-30T08:00')).toBeNull();
    expect(splitLocal('2026-11-02T24:00')).toBeNull();
  });

  it('goes to minutes and back', () => {
    expect(localOf(minutesOf('2026-11-02T08:00')! + 35 * 60)).toBe('2026-11-03T19:00');
  });

  it('gives today in the election zone, not the browser one', () => {
    // 02:00 UTC on 9 October is still the 8th in Port-au-Prince (UTC-4) and already the 9th in Paris.
    const instant = new Date('2026-10-09T02:00:00Z');

    expect(todayIn('America/Port-au-Prince', instant)).toBe('2026-10-08');
    expect(todayIn('Europe/Paris', instant)).toBe('2026-10-09');
  });
});

describe('the voting days', () => {
  const range = { starts: '2026-11-02T08:00', ends: '2026-11-06T15:00' };

  it('marks the first day, the days between and the last', () => {
    expect(markOf('2026-11-02', range)).toBe('start');
    expect(markOf('2026-11-04', range)).toBe('in');
    expect(markOf('2026-11-06', range)).toBe('end');
    expect(markOf('2026-11-09', range)).toBeUndefined();
    expect(markOf('2026-11-01', range)).toBeUndefined();
  });

  it('marks nothing while a date is missing or the end comes first', () => {
    expect(markOf('2026-11-04', { starts: '2026-11-02T08:00', ends: '' })).toBeUndefined();
    expect(
      markOf('2026-11-04', { starts: '2026-11-06T08:00', ends: '2026-11-02T08:00' }),
    ).toBeUndefined();
  });
});

describe('picking a day', () => {
  it('keeps the other date while the end stays after the start', () => {
    const next = pickDay(
      'starts',
      '2026-11-03',
      { starts: '2026-11-02T08:00', ends: '2026-11-04T19:00' },
      TODAY,
    );

    expect(next).toEqual({ starts: '2026-11-03T08:00', ends: '2026-11-04T19:00' });
  });

  it('moves the end to keep the duration when the start passes it', () => {
    const next = pickDay(
      'starts',
      '2026-11-20',
      { starts: '2026-11-03T08:00', ends: '2026-11-04T19:00' },
      TODAY,
    );

    expect(next).toEqual({ starts: '2026-11-20T08:00', ends: '2026-11-21T19:00' });
  });

  it('moves the start back to keep the duration when the end comes before it', () => {
    const next = pickDay(
      'ends',
      '2026-11-10',
      { starts: '2026-11-20T08:00', ends: '2026-11-25T19:00' },
      TODAY,
    );

    expect(next).toEqual({ starts: '2026-11-05T08:00', ends: '2026-11-10T19:00' });
  });

  it('allows a past day', () => {
    const next = pickDay(
      'starts',
      '2026-01-05',
      { starts: '2026-11-02T08:00', ends: '2026-11-04T19:00' },
      TODAY,
    );

    expect(next.starts).toBe('2026-01-05T08:00');
    expect(next.ends).toBe('2026-11-04T19:00');
  });

  it('gives nine hours to a date picked next to an empty one', () => {
    expect(pickDay('starts', '2026-11-02', { starts: '', ends: '' }, TODAY)).toEqual({
      starts: '2026-11-02T08:00',
      ends: '2026-11-02T17:00',
    });
    expect(pickDay('ends', '2026-11-02', { starts: '', ends: '' }, TODAY)).toEqual({
      starts: '2026-11-02T08:00',
      ends: '2026-11-02T17:00',
    });
  });

  it('leaves a half typed other field alone', () => {
    expect(setField('starts', '2026-11-02T08:00', { starts: '', ends: '2026-11' })).toEqual({
      starts: '2026-11-02T08:00',
      ends: '2026-11',
    });
  });
});

describe('time chips and steppers', () => {
  const range = { starts: '2026-11-02T12:00', ends: '2026-11-06T19:00' };

  it('set the time on the day of the field being edited', () => {
    expect(pickTime('ends', '17:00', range, TODAY).ends).toBe('2026-11-06T17:00');
  });

  it('step the hour by one and the minutes by five, each inside its own limits', () => {
    expect(stepTime('starts', 'hour', 1, range, TODAY).starts).toBe('2026-11-02T13:00');
    expect(stepTime('starts', 'minute', 1, range, TODAY).starts).toBe('2026-11-02T12:05');
    expect(stepTime('starts', 'minute', -1, range, TODAY).starts).toBe('2026-11-02T12:55');
    expect(stepTime('ends', 'hour', 1, { ...range, ends: '2026-11-06T23:00' }, TODAY).ends).toBe(
      '2026-11-06T00:00',
    );
  });

  it('keeps the duration when a time puts the end before the start', () => {
    const same = { starts: '2026-11-02T08:00', ends: '2026-11-02T17:00' };

    expect(pickTime('starts', '19:00', same, TODAY)).toEqual({
      starts: '2026-11-02T19:00',
      ends: '2026-11-03T04:00',
    });
  });

  it('starts from a default time on an empty field', () => {
    expect(pickTime('ends', '12:00', { starts: '2026-11-02T08:00', ends: '' }, TODAY).ends).toBe(
      '2026-11-02T12:00',
    );
  });
});

describe('the quick choices', () => {
  const range = { starts: '2026-11-02T10:30', ends: '2026-11-06T19:00' };

  it('one day: the day of the start, 8:00 to 17:00', () => {
    expect(presetRange('one-day', range, TODAY)).toEqual({
      starts: '2026-11-02T08:00',
      ends: '2026-11-02T17:00',
    });
  });

  it('one day with no start: tomorrow', () => {
    expect(presetRange('one-day', { starts: '', ends: '' }, TODAY).starts).toBe('2026-10-10T08:00');
  });

  it('tomorrow and in a week', () => {
    expect(presetRange('tomorrow', range, TODAY)).toEqual({
      starts: '2026-10-10T08:00',
      ends: '2026-10-10T17:00',
    });
    expect(presetRange('in-a-week', range, TODAY)).toEqual({
      starts: '2026-10-16T08:00',
      ends: '2026-10-16T17:00',
    });
  });

  it('next Monday for five days, strictly after today', () => {
    // 9 October 2026 is a Friday.
    expect(presetRange('next-monday', range, TODAY)).toEqual({
      starts: '2026-10-12T08:00',
      ends: '2026-10-16T17:00',
    });
    expect(nextMonday('2026-10-12')).toBe('2026-10-19');
    expect(nextMonday('2026-10-11')).toBe('2026-10-12');
  });
});
