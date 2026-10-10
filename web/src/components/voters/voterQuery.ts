/*
 * The filters of the voters page and the address that keeps them: `?page=2&q=rose&group=none`.
 * A refresh, a copied link and the back button give the same list. What is not valid is dropped
 * (the API would answer 422 to a `group` that is neither a UUID nor `none`).
 */

import type { VoterFilters } from '@/lib/api/voters';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** The longest search the API takes. */
export const SEARCH_MAX = 100;

export const NO_FILTERS: VoterFilters = { page: 1, q: '', group: '' };

type Params = Record<string, string | string[] | undefined>;

function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value) ?? '';
}

/** The filters an address asks for: page from 1, a trimmed search, a group UUID or `none`. */
export function filtersOf(params: Params): VoterFilters {
  const page = Number.parseInt(first(params.page), 10);
  const group = first(params.group).trim();

  return {
    page: Number.isInteger(page) && page >= 1 ? page : 1,
    q: first(params.q).trim().slice(0, SEARCH_MAX),
    group: group === 'none' || UUID.test(group) ? group.toLowerCase() : '',
  };
}

/** The query string of these filters, `''` when they are the defaults (`?` included otherwise). */
export function queryOf(filters: VoterFilters): string {
  const query = new URLSearchParams();

  if (filters.page > 1) query.set('page', String(filters.page));
  if (filters.q !== '') query.set('q', filters.q);
  if (filters.group !== '') query.set('group', filters.group);

  const text = query.toString();

  return text === '' ? '' : `?${text}`;
}

export function sameFilters(a: VoterFilters, b: VoterFilters): boolean {
  return a.page === b.page && a.q === b.q && a.group === b.group;
}
