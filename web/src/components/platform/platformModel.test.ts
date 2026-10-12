import { describe, expect, it } from 'vitest';
import { dayOf, electionChips, electionTotal, hasMore, isFiltering } from './platformModel';

const counts = { draft: 1, scheduled: 0, open: 2, closed: 0, published: 0, archived: 3 };

describe('platform model', () => {
  it('lists only the statuses that have elections, in order', () => {
    expect(electionChips(counts)).toEqual([
      { status: 'draft', count: 1 },
      { status: 'open', count: 2 },
      { status: 'archived', count: 3 },
    ]);
    expect(electionTotal(counts)).toBe(6);
  });

  it('knows when a filter is on and when there is more', () => {
    expect(isFiltering({ q: '  ', status: 'all' })).toBe(false);
    expect(isFiltering({ q: 'a', status: 'all' })).toBe(true);
    expect(isFiltering({ q: '', status: 'suspended' })).toBe(true);
    expect(hasMore(20, 37)).toBe(true);
    expect(hasMore(37, 37)).toBe(false);
  });

  it('writes a day in the language, or nothing', () => {
    expect(dayOf('2026-10-11T08:12:00Z', 'en')).toBe('October 11, 2026');
    expect(dayOf('2026-10-11T08:12:00Z', 'fr')).toBe('11 octobre 2026');
    expect(dayOf(null, 'fr')).toBeNull();
    expect(dayOf('nope', 'fr')).toBeNull();
  });
});
