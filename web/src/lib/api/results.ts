import type { operations } from './schema';

/** `GET /elections/{election}/results`: the counted result of a closed election, per ballot. */
export type Results =
  operations['results.results']['responses'][200]['content']['application/json']['data'];

/**
 * One ballot's result. `second_round` is the UUID of the draft election already created from
 * the ballot, or null (docs/api/results/GET-elections-{election}-results.md).
 */
export type ResultBallot = Results['ballots'][number];

export type ResultCandidate = ResultBallot['candidates'][number];

/** `POST /elections/{election}/second-round`: the new draft election, with its parent. */
export type SecondRound =
  operations['results.secondRound']['responses'][201]['content']['application/json']['data'];
