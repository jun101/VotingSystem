/*
 * The candidates inside the list of ballots the page holds: pure functions, so the screen, the
 * rollback of a failed save and the answer of the API all go through the same changes. A
 * ballot's `candidates` is in order and its `candidates_count` always follows it; the positions
 * of a ballot are 1, 2, 3… with no gap.
 */

import type { Ballot } from '@/lib/api/ballots';
import type { Candidate } from '@/lib/api/candidates';

/** The list with positions 1, 2, 3… in the order it has. */
function numbered(candidates: readonly Candidate[]): Candidate[] {
  return candidates.map((candidate, index) => ({ ...candidate, position: index + 1 }));
}

/** The ballot with this list of candidates (renumbered) and the matching count. */
function holding(ballot: Ballot, candidates: readonly Candidate[]): Ballot {
  const list = numbered(candidates);

  return { ...ballot, candidates: list, candidates_count: list.length };
}

/** The ballots with this ballot's candidates replaced by `candidates`, in that order. */
export function withCandidates(
  ballots: readonly Ballot[],
  ballotId: string,
  candidates: readonly Candidate[],
): Ballot[] {
  return ballots.map((ballot) => (ballot.id === ballotId ? holding(ballot, candidates) : ballot));
}

/**
 * A candidate as the API gave it back: new at the end of its ballot, changed where it stands,
 * or moved (taken out of the ballot it was in, and put at the end of the new one).
 */
export function placeCandidate(ballots: readonly Ballot[], saved: Candidate): Ballot[] {
  return ballots.map((ballot) => {
    const has = ballot.candidates.some((candidate) => candidate.id === saved.id);

    if (ballot.id === saved.ballot) {
      return holding(
        ballot,
        has
          ? ballot.candidates.map((candidate) => (candidate.id === saved.id ? saved : candidate))
          : [...ballot.candidates, saved],
      );
    }

    return has
      ? holding(
          ballot,
          ballot.candidates.filter((candidate) => candidate.id !== saved.id),
        )
      : ballot;
  });
}

/** The ballots without this candidate; the ones after it move up. */
export function removeCandidate(ballots: readonly Ballot[], id: string): Ballot[] {
  return ballots.map((ballot) =>
    ballot.candidates.some((candidate) => candidate.id === id)
      ? holding(
          ballot,
          ballot.candidates.filter((candidate) => candidate.id !== id),
        )
      : ballot,
  );
}

/** The ballots after a party is deleted: its candidates stay and become independent. */
export function detachParty(ballots: readonly Ballot[], partyId: string): Ballot[] {
  return ballots.map((ballot) =>
    ballot.candidates.some((candidate) => candidate.party === partyId)
      ? {
          ...ballot,
          candidates: ballot.candidates.map((candidate) =>
            candidate.party === partyId ? { ...candidate, party: null } : candidate,
          ),
        }
      : ballot,
  );
}

/**
 * What the API just said about the candidates, laid over the ballots as they are shown: the
 * ballots keep their order and their other fields (a reorder may be on its way), only their
 * candidates are the server's. A ballot the server no longer knows is left as it is.
 */
export function mergeCandidates(ballots: readonly Ballot[], fresh: readonly Ballot[]): Ballot[] {
  return ballots.map((ballot) => {
    const server = fresh.find((other) => other.id === ballot.id);

    return server
      ? { ...ballot, candidates: server.candidates, candidates_count: server.candidates_count }
      : ballot;
  });
}

/** Every candidate of every ballot. */
export function candidateTotal(ballots: readonly Ballot[]): number {
  return ballots.reduce((sum, ballot) => sum + ballot.candidates.length, 0);
}

/** How many candidates each party has, by party UUID (a party with none is absent). */
export function partyCounts(ballots: readonly Ballot[]): Map<string, number> {
  const counts = new Map<string, number>();

  for (const ballot of ballots) {
    for (const candidate of ballot.candidates) {
      if (candidate.party) counts.set(candidate.party, (counts.get(candidate.party) ?? 0) + 1);
    }
  }

  return counts;
}

/** A ballot to complete: it has no candidate, or just one (a vote needs a choice). */
export type Check = {
  ballot: Ballot;
  /** The 1-based place of the ballot's card. */
  n: number;
  kind: 'none' | 'one';
};

/** The ballots with no candidate or with one, in order. */
export function checksOf(ballots: readonly Ballot[]): Check[] {
  const checks: Check[] = [];

  ballots.forEach((ballot, index) => {
    if (ballot.candidates.length === 0) checks.push({ ballot, n: index + 1, kind: 'none' });
    else if (ballot.candidates.length === 1) checks.push({ ballot, n: index + 1, kind: 'one' });
  });

  return checks;
}
