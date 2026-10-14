import { describe, expect, it } from 'vitest';
import type { VoterFilters } from '@/lib/api/voters';
import { NO_FILTERS, filtersOf, queryOf, sameFilters } from './voterQuery';

const GROUP = '4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60';

function filters(over: Partial<VoterFilters> = {}): VoterFilters {
  return { ...NO_FILTERS, ...over };
}

describe('filtersOf', () => {
  it('gives the defaults for an address with no query', () => {
    expect(filtersOf({})).toEqual(NO_FILTERS);
  });

  it('reads the page, the trimmed search and a group UUID', () => {
    expect(filtersOf({ page: '3', q: '  rose ', group: GROUP })).toEqual(
      filters({ page: 3, q: 'rose', group: GROUP }),
    );
  });

  it('keeps `none` and lower-cases a UUID', () => {
    expect(filtersOf({ group: 'none' }).group).toBe('none');
    expect(filtersOf({ group: GROUP.toUpperCase() }).group).toBe(GROUP);
  });

  it('reads the credential and email filters, and drops other values', () => {
    expect(filtersOf({ credential: 'issued', email: 'failed' })).toEqual(
      filters({ credential: 'issued', email: 'failed' }),
    );
    expect(filtersOf({ credential: 'none', email: 'none' })).toEqual(
      filters({ credential: 'none', email: 'none' }),
    );
    expect(filtersOf({ credential: 'maybe', email: 'sent;drop' })).toEqual(NO_FILTERS);
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
    expect(queryOf(NO_FILTERS)).toBe('');
  });

  it('writes only what differs from the defaults', () => {
    expect(queryOf(filters({ page: 2 }))).toBe('?page=2');
    expect(queryOf(filters({ q: 'rose' }))).toBe('?q=rose');
    expect(queryOf(filters({ group: 'none' }))).toBe('?group=none');
    expect(queryOf(filters({ page: 4, q: 'a', group: GROUP }))).toBe(`?page=4&q=a&group=${GROUP}`);
    expect(queryOf(filters({ credential: 'issued', email: 'sent' }))).toBe(
      '?credential=issued&email=sent',
    );
  });

  it('encodes the search, and reads back what it wrote', () => {
    const wanted = filters({
      page: 2,
      q: 'Désir & fils',
      group: 'none',
      credential: 'none',
      email: 'failed',
    });
    const query = queryOf(wanted);
    const params = Object.fromEntries(new URLSearchParams(query.slice(1)));

    expect(query).not.toContain(' ');
    expect(filtersOf(params)).toEqual(wanted);
  });
});

describe('sameFilters', () => {
  it('compares every field', () => {
    expect(sameFilters(filters({ q: 'a' }), filters({ q: 'a' }))).toBe(true);
    expect(sameFilters(filters({ q: 'a' }), filters({ page: 2, q: 'a' }))).toBe(false);
    expect(sameFilters(filters({ q: 'a' }), filters({ q: 'b' }))).toBe(false);
    expect(sameFilters(filters({ group: 'none' }), filters())).toBe(false);
    expect(sameFilters(filters({ credential: 'issued' }), filters())).toBe(false);
    expect(sameFilters(filters({ email: 'sent' }), filters({ email: 'failed' }))).toBe(false);
  });
});

describe('the has-voted filter', () => {
  it('is read from the address, written back, and dropped when invalid', () => {
    expect(filtersOf({ voted: 'no' }).voted).toBe('no');
    expect(filtersOf({ voted: 'yes' }).voted).toBe('yes');
    expect(filtersOf({ voted: 'maybe' }).voted).toBeUndefined();
    expect(queryOf({ ...NO_FILTERS, voted: 'yes' })).toBe('?voted=yes');
    expect(sameFilters(NO_FILTERS, { ...NO_FILTERS, voted: '' })).toBe(true);
    expect(sameFilters(NO_FILTERS, { ...NO_FILTERS, voted: 'no' })).toBe(false);
  });
});
