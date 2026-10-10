import type { components, operations } from './schema';

type VoterIndex = operations['voter.index']['responses'][200]['content']['application/json'];
type GroupIndex = operations['group.index']['responses'][200]['content']['application/json'];

/** One voter: an item of `GET /elections/{election}/voters`. `group` is `{ id, name }` or `null`. */
export type Voter = VoterIndex['data'][number];

/** One group of voters: an item of `GET /elections/{election}/groups`. */
export type VoterGroup = GroupIndex['data'][number];

/** One page of voters. */
export type VoterList = { items: Voter[]; total: number; page: number };

/** The groups of an election with the two counts of the list meta. */
export type GroupList = {
  items: VoterGroup[];
  votersTotal: number;
  ungrouped: number;
};

/** What the list is asked for: the page, the search text and the group (`''` all, `none`, or a UUID). */
export type VoterFilters = { page: number; q: string; group: string };

/** The body of `POST /elections/{election}/voters`. */
export type NewVoter = components['schemas']['CreateVoterRequest'];

/** The body of `PATCH /voters/{voter}`: every field optional. */
export type VoterChanges = components['schemas']['UpdateVoterRequest'];

/** 24 voters a page. */
export const VOTERS_PER_PAGE = 24;

/** An election holds at most 10 000 voters and 100 groups. */
export const VOTER_LIMIT = 10000;
export const GROUP_LIMIT = 100;
