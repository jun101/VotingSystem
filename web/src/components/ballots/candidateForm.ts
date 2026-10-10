/*
 * The rules of the candidate form (docs/api/candidates/POST-ballots-{ballot}-candidates.md),
 * checked before the call so an obvious mistake does not wait for the network. The codes are the
 * API's own (`required`, `max`, `in`), so one message table serves both.
 */

import type { Candidate, CandidateChanges, NewCandidate, Sex } from '@/lib/api/candidates';
import { sexOf } from '@/lib/api/candidates';

export const NAME_MAX = 80;
export const SLOGAN_MAX = 80;
export const BIOGRAPHY_MAX = 1000;

/** The sex chosen when the form opens (the first of the two choices). */
export const DEFAULT_SEX: Sex = 'female';

export type CandidateDraft = {
  firstName: string;
  lastName: string;
  sex: Sex;
  /** The UUID of the ballot. */
  ballot: string;
  /** The UUID of the party, or '' for an independent candidate. */
  party: string;
  slogan: string;
  biography: string;
};

/** Field names as the API writes them, which are also the keys of the messages. */
export type CandidateField =
  'first_name' | 'last_name' | 'sex' | 'ballot' | 'party' | 'slogan' | 'biography';

export type CandidateFieldErrors = Partial<Record<CandidateField, string>>;

const FIELDS: readonly CandidateField[] = [
  'first_name',
  'last_name',
  'sex',
  'ballot',
  'party',
  'slogan',
  'biography',
];

/** The number of characters as the API counts them (one per code point). */
export function charCount(value: string): number {
  return Array.from(value).length;
}

/** An empty form for this ballot and this party (the two things "save and add another" keeps). */
export function emptyDraft(ballot: string, party = ''): CandidateDraft {
  return {
    firstName: '',
    lastName: '',
    sex: DEFAULT_SEX,
    ballot,
    party,
    slogan: '',
    biography: '',
  };
}

/** The draft that fills the form to change this candidate. */
export function draftOf(candidate: Candidate): CandidateDraft {
  return {
    firstName: candidate.first_name,
    lastName: candidate.last_name,
    sex: sexOf(candidate),
    ballot: candidate.ballot,
    party: candidate.party ?? '',
    slogan: candidate.slogan ?? '',
    biography: candidate.biography ?? '',
  };
}

/** The field → code of every rule that fails; an empty object when the draft is valid. */
export function validateCandidate(draft: CandidateDraft): CandidateFieldErrors {
  const errors: CandidateFieldErrors = {};
  const first = draft.firstName.trim();
  const last = draft.lastName.trim();

  if (first === '') errors.first_name = 'required';
  else if (charCount(first) > NAME_MAX) errors.first_name = 'max';

  if (last === '') errors.last_name = 'required';
  else if (charCount(last) > NAME_MAX) errors.last_name = 'max';

  if (draft.sex !== 'male' && draft.sex !== 'female') errors.sex = 'in';

  if (draft.ballot === '') errors.ballot = 'required';

  if (charCount(draft.slogan.trim()) > SLOGAN_MAX) errors.slogan = 'max';

  if (charCount(draft.biography.trim()) > BIOGRAPHY_MAX) errors.biography = 'max';

  return errors;
}

function blankToNull(value: string): string | null {
  const text = value.trim();

  return text === '' ? null : text;
}

/** The body of the create call; no party is `null`, and a blank slogan or biography is `null`. */
export function bodyOf(draft: CandidateDraft): NewCandidate {
  return {
    first_name: draft.firstName.trim(),
    last_name: draft.lastName.trim(),
    sex: draft.sex,
    party: draft.party === '' ? null : draft.party,
    slogan: blankToNull(draft.slogan),
    biography: blankToNull(draft.biography),
  };
}

/** The body of the update call: the same fields, and `ballot` only when the candidate moves. */
export function changesOf(draft: CandidateDraft, currentBallot: string): CandidateChanges {
  const body: CandidateChanges = { ...bodyOf(draft) };

  if (draft.ballot !== currentBallot) body.ballot = draft.ballot;

  return body;
}

/** The first code the API gave for each field of the form (other fields are not ours). */
export function apiFieldErrors(fields: Record<string, string[]>): CandidateFieldErrors {
  const errors: CandidateFieldErrors = {};

  for (const name of FIELDS) {
    const code = fields[name]?.[0];

    if (code) errors[name] = code;
  }

  return errors;
}

/** "First Last", as a row and the voter's card write it. */
export function fullName(person: { first_name: string; last_name: string }): string {
  return `${person.first_name} ${person.last_name}`.trim();
}
