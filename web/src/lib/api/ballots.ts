import type { components, operations } from './schema';

/** One ballot (a position to fill): an item of `GET /elections/{election}/ballots`. */
export type Ballot =
  operations['ballot.index']['responses'][200]['content']['application/json']['data'][number];

/** The body of `POST /elections/{election}/ballots`. */
export type NewBallot = components['schemas']['CreateBallotRequest'];

/** The body of `PATCH /ballots/{ballot}`: every field optional. */
export type BallotChanges = components['schemas']['UpdateBallotRequest'];

/** An election holds at most 50 ballots (`ballot_limit_reached` otherwise). */
export const BALLOT_LIMIT = 50;
