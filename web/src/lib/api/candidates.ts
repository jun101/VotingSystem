import type { Ballot } from './ballots';
import type { components } from './schema';

/** One candidate of a ballot: an item of `candidates` in `GET /elections/{election}/ballots`. */
export type Candidate = Ballot['candidates'][number];

/** The two values of `sex`, which chooses the avatar while there is no photo. */
export type Sex = 'male' | 'female';

/** The body of `POST /ballots/{ballot}/candidates`. */
export type NewCandidate = components['schemas']['CreateCandidateRequest'];

/** The body of `PATCH /candidates/{candidate}`: every field optional, and `ballot` to move it. */
export type CandidateChanges = components['schemas']['UpdateCandidateRequest'];

/** A ballot holds at most 50 candidates (`candidate_limit_reached` otherwise). */
export const CANDIDATE_LIMIT = 50;

/** The sex of a candidate as one of the two values (anything else counts as female, the default). */
export function sexOf(candidate: Pick<Candidate, 'sex'>): Sex {
  return candidate.sex === 'male' ? 'male' : 'female';
}
