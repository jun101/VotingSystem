import { describe, expect, it } from 'vitest';
import { dateRange, durationBetween, joinList, longDay, pluralForm, yearIn } from './electionDates';

describe('dateRange', () => {
  const zone = 'America/Port-au-Prince';

  it('keeps the month and the year once when both ends share them', () => {
    expect(dateRange('2026-10-12T12:00:00Z', '2026-10-16T19:00:00Z', 'fr', zone)).toEqual({
      kind: 'sameMonth',
      values: { from: '12', to: '16', month: 'oct.', year: '2026' },
    });
  });

  it('knows a single day, a range inside one year and a range over two years', () => {
    expect(dateRange('2025-09-02T12:00:00Z', '2025-09-02T19:00:00Z', 'fr', zone)?.kind).toBe(
      'sameDay',
    );
    expect(dateRange('2026-10-30T12:00:00Z', '2026-11-02T19:00:00Z', 'fr', zone)?.kind).toBe(
      'sameYear',
    );
    expect(dateRange('2026-12-30T12:00:00Z', '2027-01-02T19:00:00Z', 'fr', zone)?.kind).toBe(
      'otherYears',
    );
  });

  it('reads the days in the zone of the election, not in UTC', () => {
    // 02:00 UTC on the 17th is still the 16th in Port-au-Prince.
    const range = dateRange('2026-10-12T12:00:00Z', '2026-10-17T02:00:00Z', 'fr', zone);

    expect(range).toMatchObject({ kind: 'sameMonth', values: { to: '16' } });
  });

  it('gives null for text that is not a date', () => {
    expect(dateRange('x', 'y', 'fr', zone)).toBeNull();
  });
});

describe('longDay and yearIn', () => {
  it('writes the day of the week, the date and the year in the language', () => {
    const start = new Date('2026-10-12T12:00:00Z');

    expect(longDay(start, 'fr', 'America/Port-au-Prince')).toBe('Lundi 12 octobre 2026');
    expect(longDay(start, 'en', 'America/Port-au-Prince', false)).toBe('Monday, October 12');
  });

  it('reads the year in the zone', () => {
    expect(yearIn(new Date('2026-01-01T04:30:00Z'), 'America/Port-au-Prince')).toBe(2025);
    expect(yearIn(new Date('2026-01-01T04:30:00Z'), 'UTC')).toBe(2026);
  });
});

describe('durationBetween', () => {
  it('gives days, hours and minutes', () => {
    expect(
      durationBetween(new Date('2026-10-12T12:00:00Z'), new Date('2026-10-16T19:00:00Z')),
    ).toEqual({
      days: 4,
      hours: 7,
      minutes: 0,
    });
  });

  it('gives null when the end is not after the start', () => {
    expect(
      durationBetween(new Date('2026-10-12T12:00:00Z'), new Date('2026-10-12T12:00:00Z')),
    ).toBeNull();
    expect(
      durationBetween(new Date('2026-10-12T12:00:00Z'), new Date('2026-10-11T12:00:00Z')),
    ).toBeNull();
  });
});

describe('plurals and lists', () => {
  it('picks the form of the message', () => {
    expect(pluralForm('fr', 1)).toBe('one');
    expect(pluralForm('fr', 4)).toBe('other');
    expect(pluralForm('en', 1)).toBe('one');
    expect(pluralForm('en', 0)).toBe('other');
  });

  it('joins with the "and" of the language', () => {
    expect(joinList('fr', ['4 jours', '7 heures'])).toBe('4 jours et 7 heures');
    expect(joinList('en', ['4 days', '7 hours'])).toBe('4 days and 7 hours');
  });
});
