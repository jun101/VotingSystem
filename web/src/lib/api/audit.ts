import type { operations } from './schema';

type AuditIndex = operations['audit.index']['responses'][200]['content']['application/json'];

/** One entry of `GET /audit`. `actor.id` is null for the scheduler (`actor.name` is `system`). */
export type AuditEntry = AuditIndex['data'][number];

/** One page of the log, with the total of entries matching the filters. */
export type AuditPage = { items: AuditEntry[]; total: number; page: number };

/** What the log is asked for. Blank strings are not sent. */
export type AuditFilters = {
  /** `''` all, an action code, or a family prefix ending with a dot. */
  action: string;
  /** `''` all, or an election's UUID. */
  election: string;
  /** `''` all, `system`, or a user's UUID. */
  user: string;
  /** `YYYY-MM-DD` or `''`. */
  from: string;
  to: string;
};

export const NO_AUDIT_FILTERS: AuditFilters = {
  action: '',
  election: '',
  user: '',
  from: '',
  to: '',
};

/** 50 entries a page. */
export const AUDIT_PER_PAGE = 50;
