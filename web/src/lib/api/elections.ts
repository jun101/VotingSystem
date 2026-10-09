import type { components, operations } from './schema';

/** One election: the `data` of `GET /elections/{election}`. */
export type Election =
  operations['election.show']['responses'][200]['content']['application/json']['data'];

export type ElectionStatus = Election['status'];

/** The six statuses, in the order the tiles are drawn. */
export const ELECTION_STATUSES: readonly ElectionStatus[] = [
  'draft',
  'scheduled',
  'open',
  'closed',
  'published',
  'archived',
];

/** `GET /elections`: the page of cards and what the filter bar needs. */
type ElectionIndex = operations['election.index']['responses'][200]['content']['application/json'];

export type ElectionCounts = ElectionIndex['meta']['counts'];

export type ElectionList = {
  items: Election[];
  total: number;
  counts: ElectionCounts;
  /** Years of all the institution's elections, newest first. */
  years: number[];
};

/** What the filter bar asks for: both are optional and live in the address. */
export type ElectionFilters = { status?: ElectionStatus; year?: number };

/** The body of `POST /elections`. */
export type NewElection = components['schemas']['CreateElectionRequest'];

/** The body of `PATCH /elections/{election}`. */
export type ElectionChanges = components['schemas']['UpdateElectionRequest'];
