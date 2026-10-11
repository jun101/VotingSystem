import type { operations } from './schema';

type PlatformIndex =
  operations['platformInstitution.index']['responses'][200]['content']['application/json'];

/** One institution of `GET /platform/institutions`: counts only, never a person, a code or a vote. */
export type PlatformInstitution = PlatformIndex['data'][number];

/** One page of the list, with the total matching the filters. */
export type PlatformPage = { items: PlatformInstitution[]; total: number; page: number };

/** What the list is asked for. A blank search and `all` are not sent. */
export type PlatformFilters = { q: string; status: 'all' | 'active' | 'suspended' };

export const NO_PLATFORM_FILTERS: PlatformFilters = { q: '', status: 'all' };

/** 20 institutions a page. */
export const PLATFORM_PER_PAGE = 20;
