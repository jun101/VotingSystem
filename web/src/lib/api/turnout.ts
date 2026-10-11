import type { operations } from './schema';

/** `GET /elections/{election}/turnout`: how many have voted. Never what they voted. */
export type Turnout =
  operations['turnout.turnout']['responses'][200]['content']['application/json']['data'];

export type TurnoutGroup = Turnout['by_group'][number];
export type TurnoutPoint = Turnout['over_time'][number];
export type TurnoutBucket = Turnout['bucket'];

/** `GET /dashboard`: the institution's elections by state, the open ones with turnout, the to-do. */
export type Dashboard =
  operations['turnout.dashboard']['responses'][200]['content']['application/json']['data'];

export type DashboardOpen = Dashboard['open'][number];
export type DashboardUpcoming = Dashboard['upcoming'][number];
export type DashboardTodo = Dashboard['todo'][number];

/** What an export is asked for: the filters of the non-voters list. */
export type ExportQuery = {
  format: 'xlsx' | 'csv';
  voted: 'no' | 'yes' | 'all';
  /** `''` all, `none`, or a group's UUID. */
  group: string;
  q: string;
};
