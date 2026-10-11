import type { ResultBallot, ResultCandidate } from '@/lib/api/results';

/*
 * What the results page draws, worked out from the numbers the API gives. Pure functions, so
 * they can be tested without a screen.
 */

/** The most characters a result note may hold (docs/api/results/PUT-ballots-{ballot}-result-note.md). */
export const NOTE_MAX = 1000;

/** The bar of a candidate: the share of the votes cast on the ballot, between 0 and 1. */
export function barShare(votes: number, cast: number): number {
  if (!(cast > 0) || !(votes > 0)) return 0;

  return Math.min(1, votes / cast);
}

/** The characters of a note, counted as a person counts them (an accent or an emoji is one). */
export function noteLength(text: string): number {
  return Array.from(text).length;
}

/** The note as the server keeps it: trimmed, and nothing when empty. */
export function normalizeNote(text: string): string {
  return text.trim();
}

/** The note is different from the saved one (the saved one is null when there is none). */
export function noteChanged(draft: string, saved: string | null): boolean {
  return normalizeNote(draft) !== (saved ?? '');
}

/** The note is longer than the server accepts. */
export function noteTooLong(text: string): boolean {
  return noteLength(text) > NOTE_MAX;
}

export function fullName(candidate: Pick<ResultCandidate, 'first_name' | 'last_name'>): string {
  return `${candidate.first_name} ${candidate.last_name}`.trim();
}

export type CandidateState = 'winner' | 'tied' | 'none';

/** What a candidate is on the ballot: a winner, one of the tied candidates, or neither. */
export function candidateState(ballot: ResultBallot, id: string): CandidateState {
  if (ballot.winners.includes(id)) return 'winner';
  if (ballot.tie?.candidates.includes(id)) return 'tied';

  return 'none';
}

/** The names of the tied candidates, in the order of the list. */
export function tiedNames(ballot: ResultBallot): string[] {
  const tied = ballot.tie?.candidates ?? [];

  return ballot.candidates.filter((candidate) => tied.includes(candidate.id)).map(fullName);
}

/** A second round can be asked for: the ballot has a tie and none exists yet. */
export function canCreateSecondRound(ballot: ResultBallot): boolean {
  return ballot.tie !== null && !ballot.second_round;
}

/** Turnout of a ballot as a share of its voters, between 0 and 1. */
export function ballotTurnout(ballot: Pick<ResultBallot, 'eligible' | 'voted'>): number {
  return barShare(ballot.voted, ballot.eligible);
}
