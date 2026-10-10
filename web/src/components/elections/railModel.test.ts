import { describe, expect, it } from 'vitest';
import type { Election } from '@/lib/api/elections';
import { calendarFor, daysUntil, nearestUpcoming, todoFor } from './railModel';

const NOW = new Date('2026-10-09T16:00:00Z'); // 12:00 in Port-au-Prince, a Friday
const ZONE = 'America/Port-au-Prince';

function election(
  n: number,
  status: Election['status'],
  starts: string,
  ends: string,
  timezone = ZONE,
): Election {
  return {
    id: `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`,
    title: `Élection ${n}`,
    description: null,
    status,
    starts_at: starts,
    ends_at: ends,
    timezone,
    language: 'fr',
    candidate_order: 'manual',
    results_display: 'full',
    cover: null,
    ballots_count: 0,
    voters_count: 0,
    created_at: '2026-10-01T00:00:00Z',
  };
}

const days = (month: ReturnType<typeof calendarFor>) => month.weeks.flat();

describe('the calendar', () => {
  it('shows the current month, today ringed, when there is no election', () => {
    const month = calendarFor([], NOW, ZONE);

    expect(month.month).toEqual({ year: 2026, month: 10 });
    expect(
      days(month)
        .filter((day) => day.today)
        .map((day) => day.day),
    ).toEqual(['2026-10-09']);
    expect(days(month).some((day) => day.event)).toBe(false);
    expect(month.weeks.every((week) => week.length === 7)).toBe(true);
  });

  it('shows the month of the nearest upcoming election and marks every day it runs', () => {
    const later = election(1, 'draft', '2026-12-01T13:00:00Z', '2026-12-02T20:00:00Z');
    const sooner = election(2, 'scheduled', '2026-11-02T13:00:00Z', '2026-11-04T20:00:00Z');
    const month = calendarFor([later, sooner], NOW, ZONE);
    const marked = days(month)
      .filter((day) => day.event && !day.outside)
      .map((day) => day.day);

    expect(month.month).toEqual({ year: 2026, month: 11 });
    expect(marked).toEqual(['2026-11-02', '2026-11-03', '2026-11-04']);
  });

  it("reads the days in the election's own zone", () => {
    // 01:00 UTC on 3 November is still 2 November, 21:00, in Port-au-Prince.
    const late = election(1, 'draft', '2026-11-03T01:00:00Z', '2026-11-03T03:00:00Z');
    const marked = days(calendarFor([late], NOW, ZONE)).filter((day) => day.event && !day.outside);

    expect(marked.map((day) => day.day)).toEqual(['2026-11-02']);
  });

  it('stays on the current month for an election that is running, and skips the finished ones', () => {
    const running = election(1, 'open', '2026-09-28T13:00:00Z', '2026-10-12T20:00:00Z');
    const done = election(2, 'closed', '2026-03-01T13:00:00Z', '2026-03-02T20:00:00Z');

    expect(nearestUpcoming([done, running], NOW)).toBe(running);
    expect(calendarFor([done, running], NOW, ZONE).month).toEqual({ year: 2026, month: 10 });
  });

  it('marks the days of the neighbouring weeks too', () => {
    const edge = election(1, 'draft', '2026-11-01T13:00:00Z', '2026-11-01T20:00:00Z');
    const cell = days(calendarFor([edge], NOW, ZONE)).find((day) => day.day === '2026-11-01');

    expect(cell?.event).toBe(true);
  });
});

describe('the to-do list', () => {
  it('is empty with nothing to do', () => {
    expect(todoFor([], NOW)).toEqual([]);
    expect(
      todoFor([election(1, 'closed', '2026-03-01T13:00:00Z', '2026-03-02T20:00:00Z')], NOW),
    ).toEqual([]);
  });

  it('lists every draft', () => {
    const items = todoFor(
      [election(1, 'draft', '2026-11-02T13:00:00Z', '2026-11-03T20:00:00Z')],
      NOW,
    );

    expect(items.map((item) => [item.kind, item.election.title])).toEqual([
      ['draft', 'Élection 1'],
    ]);
  });

  it('lists an election that starts within seven days, first, and counts the days', () => {
    const draft = election(1, 'draft', '2026-11-02T13:00:00Z', '2026-11-03T20:00:00Z');
    const soon = election(2, 'scheduled', '2026-10-12T13:00:00Z', '2026-10-14T20:00:00Z');
    const far = election(3, 'scheduled', '2026-10-30T13:00:00Z', '2026-10-31T20:00:00Z');
    const items = todoFor([draft, far, soon], NOW);

    expect(items.map((item) => [item.kind, item.election.title])).toEqual([
      ['soon', 'Élection 2'],
      ['draft', 'Élection 1'],
    ]);
    expect(items[0]!.inDays).toBe(3);
  });

  it('gives a draft one item even when it starts soon, and never an election already started', () => {
    const draftSoon = election(1, 'draft', '2026-10-10T13:00:00Z', '2026-10-11T20:00:00Z');
    const started = election(2, 'open', '2026-10-08T13:00:00Z', '2026-10-14T20:00:00Z');

    expect(todoFor([draftSoon, started], NOW).map((item) => item.kind)).toEqual(['draft']);
  });

  it("counts calendar days in the election's zone", () => {
    const tomorrow = election(1, 'scheduled', '2026-10-10T13:00:00Z', '2026-10-11T20:00:00Z');

    expect(daysUntil(tomorrow, NOW)).toBe(1);
  });
});
