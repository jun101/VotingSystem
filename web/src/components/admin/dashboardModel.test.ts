import { describe, expect, it } from 'vitest';
import type { Election } from '@/lib/api/elections';
import type { TeamMember } from '@/lib/api/user';
import {
  elapsedShare,
  figuresOf,
  gettingStarted,
  latestOf,
  openElectionOf,
  openShown,
  roleCounts,
  timeLeft,
  todayText,
  todoOf,
} from './dashboardModel';

function election(over: Partial<Election> & { id: string }): Election {
  return {
    title: over.id,
    description: null,
    status: 'draft',
    starts_at: '2026-11-02T12:00:00Z',
    ends_at: '2026-11-04T19:00:00Z',
    timezone: 'America/Port-au-Prince',
    language: 'fr',
    candidate_order: 'manual',
    results_display: 'full',
    cover: null,
    ballots_count: 0,
    voters_count: 0,
    created_at: '2026-10-01T10:00:00Z',
    ...over,
  };
}

const counts = (all: number, archived = 0) =>
  ({ all, archived, draft: 0, scheduled: 0, open: 0, closed: 0, published: 0 }) as Parameters<
    typeof figuresOf
  >[1];

describe('gettingStarted', () => {
  it('goes 0, 33, 67 and 100 percent, and is complete at three steps', () => {
    const none = gettingStarted({ emailVerified: false, hasLogo: false, hasElection: false });
    const one = gettingStarted({ emailVerified: false, hasLogo: false, hasElection: true });
    const two = gettingStarted({ emailVerified: true, hasLogo: false, hasElection: true });
    const all = gettingStarted({ emailVerified: true, hasLogo: true, hasElection: true });

    expect([none.percent, one.percent, two.percent, all.percent]).toEqual([0, 33, 67, 100]);
    expect(none.complete).toBe(false);
    expect(all.complete).toBe(true);
    expect(one.steps.find((step) => step.key === 'election')?.done).toBe(true);
    expect(two.steps.map((step) => step.key)).toEqual(['email', 'institution', 'election']);
  });
});

describe('figuresOf', () => {
  it('counts the voters of all the elections it is given, and never a vote', () => {
    const figures = figuresOf(
      [
        election({ id: 'a', voters_count: 10 }),
        election({ id: 'b', voters_count: 5, status: 'open' }),
        election({ id: 'c', voters_count: 99, status: 'archived' }),
      ],
      counts(2, 1),
    );

    expect(figures).toEqual({ elections: 2, voters: 114, ballots: 0 });
  });
});

describe('todoOf', () => {
  const now = new Date('2026-10-09T12:00:00Z');

  it('puts the election starting within seven days first, then up to three drafts', () => {
    const rows = todoOf(
      [
        election({ id: 'd1' }),
        election({ id: 'd2' }),
        election({ id: 'd3' }),
        election({ id: 'd4' }),
        election({ id: 'soon', status: 'scheduled', starts_at: '2026-10-12T12:00:00Z' }),
        election({ id: 'later', status: 'scheduled', starts_at: '2026-10-30T12:00:00Z' }),
      ],
      now,
    );

    expect(rows.map((row) => row.election.id)).toEqual(['soon', 'd1', 'd2', 'd3']);
    expect(rows[0]).toMatchObject({ kind: 'soon', inDays: 3 });
  });

  it('is empty when nothing is left to do', () => {
    expect(todoOf([election({ id: 'x', status: 'open' })], now)).toEqual([]);
  });
});

describe('open election and latest', () => {
  it('picks the open election that ends first', () => {
    const picked = openElectionOf([
      election({ id: 'late', status: 'open', ends_at: '2026-10-20T12:00:00Z' }),
      election({ id: 'soon', status: 'open', ends_at: '2026-10-10T12:00:00Z' }),
      election({ id: 'draft' }),
    ]);

    expect(picked?.id).toBe('soon');
    expect(openElectionOf([election({ id: 'draft' })])).toBeNull();
  });

  it('takes the three newest that are not archived', () => {
    const list = [
      election({ id: 'a', created_at: '2026-10-01T00:00:00Z' }),
      election({ id: 'b', created_at: '2026-10-03T00:00:00Z' }),
      election({ id: 'c', created_at: '2026-10-02T00:00:00Z' }),
      election({ id: 'd', created_at: '2026-10-04T00:00:00Z' }),
      election({ id: 'z', created_at: '2026-10-09T00:00:00Z', status: 'archived' }),
    ];

    expect(latestOf(list).map((e) => e.id)).toEqual(['d', 'b', 'c']);
  });

  it('measures the time that has passed, between 0 and 1', () => {
    const e = { starts_at: '2026-10-10T00:00:00Z', ends_at: '2026-10-12T00:00:00Z' };

    expect(elapsedShare(e, new Date('2026-10-09T00:00:00Z'))).toBe(0);
    expect(elapsedShare(e, new Date('2026-10-11T00:00:00Z'))).toBe(0.5);
    expect(elapsedShare(e, new Date('2026-10-13T00:00:00Z'))).toBe(1);
  });
});

describe('small helpers', () => {
  it('writes today in the zone of the institution', () => {
    const instant = new Date('2026-10-10T02:00:00Z');

    expect(todayText(instant, 'fr', 'America/Port-au-Prince')).toBe('9 octobre 2026');
    expect(todayText(instant, 'fr', 'Not/AZone')).toMatch(/octobre 2026/);
  });

  it('counts owners and managers', () => {
    const member = (role: 'owner' | 'manager') => ({ role }) as TeamMember;

    expect(roleCounts([member('owner'), member('manager'), member('manager')])).toEqual({
      owners: 1,
      managers: 2,
    });
  });
});

describe('timeLeft', () => {
  const end = '2026-10-16T19:00:00Z';

  it('shows days and hours, then hours and minutes, then minutes', () => {
    const at = (iso: string) => new Date(iso).getTime();

    expect(timeLeft(end, at('2026-10-14T15:00:00Z'))).toEqual({
      kind: 'days',
      days: 2,
      hours: 4,
      minutes: 0,
    });
    expect(timeLeft(end, at('2026-10-16T15:40:00Z'))).toEqual({
      kind: 'hours',
      days: 0,
      hours: 3,
      minutes: 20,
    });
    expect(timeLeft(end, at('2026-10-16T18:48:00Z'))).toMatchObject({
      kind: 'minutes',
      minutes: 12,
    });
  });

  it('says it is over at the end and after it', () => {
    expect(timeLeft(end, new Date(end).getTime()).kind).toBe('over');
    expect(timeLeft(end, new Date(end).getTime() + 5 * 60_000).kind).toBe('over');
  });
});

describe('openShown', () => {
  it('keeps the first three and counts the others', () => {
    expect(openShown([1, 2, 3, 4, 5])).toEqual({ shown: [1, 2, 3], more: 2 });
    expect(openShown([1])).toEqual({ shown: [1], more: 0 });
  });
});
