import { describe, expect, it } from 'vitest';
import { filtersOf, queryOf, sameFilters } from './voterQuery';

const GROUP = '4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60';

describe('filtersOf', () => {
  it('gives the defaults for an address with no query', () => {
    expect(filtersOf({})).toEqual({ page: 1, q: '', group: '' });
  });

  it('reads the page, the trimmed search and a group UUID', () => {
    expect(filtersOf({ page: '3', q: '  rose ', group: GROUP })).toEqual({
      page: 3,
      q: 'rose',
      group: GROUP,
    });
  });

  it('keeps `none` and lower-cases a UUID', () => {
    expect(filtersOf({ group: 'none' }).group).toBe('none');
    expect(filtersOf({ group: GROUP.toUpperCase() }).group).toBe(GROUP);
  });

  it('drops what is not valid', () => {
    expect(filtersOf({ page: '0' }).page).toBe(1);
    expect(filtersOf({ page: '-2' }).page).toBe(1);
    expect(filtersOf({ page: 'abc' }).page).toBe(1);
    expect(filtersOf({ group: 'all' }).group).toBe('');
    expect(filtersOf({ group: '12' }).group).toBe('');
  });

  it('takes the first of a repeated parameter and cuts a search at 100 characters', () => {
    expect(filtersOf({ page: ['2', '5'] }).page).toBe(2);
    expect(filtersOf({ q: 'a'.repeat(150) }).q).toHaveLength(100);
  });
});

describe('queryOf', () => {
  it('is empty for the defaults', () => {
    expect(queryOf({ page: 1, q: '', group: '' })).toBe('');
  });

  it('writes only what differs from the defaults', () => {
    expect(queryOf({ page: 2, q: '', group: '' })).toBe('?page=2');
    expect(queryOf({ page: 1, q: 'rose', group: '' })).toBe('?q=rose');
    expect(queryOf({ page: 1, q: '', group: 'none' })).toBe('?group=none');
    expect(queryOf({ page: 4, q: 'a', group: GROUP })).toBe(`?page=4&q=a&group=${GROUP}`);
  });

  it('encodes the search, and reads back what it wrote', () => {
    const filters = { page: 2, q: 'Désir & fils', group: 'none' };
    const query = queryOf(filters);
    const params = Object.fromEntries(new URLSearchParams(query.slice(1)));

    expect(query).not.toContain(' ');
    expect(filtersOf(params)).toEqual(filters);
  });
});

describe('sameFilters', () => {
  it('compares the three fields', () => {
    expect(sameFilters({ page: 1, q: 'a', group: '' }, { page: 1, q: 'a', group: '' })).toBe(true);
    expect(sameFilters({ page: 1, q: 'a', group: '' }, { page: 2, q: 'a', group: '' })).toBe(false);
    expect(sameFilters({ page: 1, q: 'a', group: '' }, { page: 1, q: 'b', group: '' })).toBe(false);
    expect(sameFilters({ page: 1, q: '', group: 'none' }, { page: 1, q: '', group: '' })).toBe(
      false,
    );
  });
});
