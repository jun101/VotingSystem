import { describe, expect, it } from 'vitest';
import type { Candidate } from '@/lib/api/candidates';
import {
  apiFieldErrors,
  bodyOf,
  changesOf,
  charCount,
  draftOf,
  emptyDraft,
  fullName,
  validateCandidate,
} from './candidateForm';

const BALLOT = '10000000-0000-4000-8000-000000000001';
const OTHER = '10000000-0000-4000-8000-000000000002';
const PARTY = '20000000-0000-4000-8000-000000000001';

function valid() {
  return { ...emptyDraft(BALLOT), firstName: 'Nadège', lastName: 'Pierre-Louis' };
}

describe('emptyDraft', () => {
  it('has a sex chosen and keeps the ballot and the party it is given', () => {
    const draft = emptyDraft(BALLOT, PARTY);

    expect(draft).toMatchObject({ sex: 'female', ballot: BALLOT, party: PARTY, firstName: '' });
    expect(emptyDraft(BALLOT).party).toBe('');
  });
});

describe('validateCandidate', () => {
  it('accepts a first name, a last name and a ballot', () => {
    expect(validateCandidate(valid())).toEqual({});
  });

  it('asks for both names, trimmed', () => {
    const found = validateCandidate({ ...valid(), firstName: '   ', lastName: '' });

    expect(found).toEqual({ first_name: 'required', last_name: 'required' });
  });

  it('refuses names over 80 characters, counting code points', () => {
    expect(validateCandidate({ ...valid(), firstName: 'a'.repeat(81) })).toEqual({
      first_name: 'max',
    });
    expect(validateCandidate({ ...valid(), lastName: '😀'.repeat(80) })).toEqual({});
    expect(validateCandidate({ ...valid(), lastName: '😀'.repeat(81) })).toEqual({
      last_name: 'max',
    });
  });

  it('limits the slogan to 80 and the biography to 1000 after trimming', () => {
    expect(validateCandidate({ ...valid(), slogan: ` ${'a'.repeat(80)} ` })).toEqual({});
    expect(validateCandidate({ ...valid(), slogan: 'a'.repeat(81) })).toEqual({ slogan: 'max' });
    expect(validateCandidate({ ...valid(), biography: 'a'.repeat(1000) })).toEqual({});
    expect(validateCandidate({ ...valid(), biography: 'a'.repeat(1001) })).toEqual({
      biography: 'max',
    });
  });

  it('asks for a ballot', () => {
    expect(validateCandidate({ ...valid(), ballot: '' })).toEqual({ ballot: 'required' });
  });
});

describe('bodyOf', () => {
  it('trims, sends no party as null and blanks as null', () => {
    expect(bodyOf({ ...valid(), firstName: ' Nadège ', slogan: '   ', biography: '' })).toEqual({
      first_name: 'Nadège',
      last_name: 'Pierre-Louis',
      sex: 'female',
      party: null,
      slogan: null,
      biography: null,
    });
  });

  it('sends the party and the texts when given', () => {
    expect(
      bodyOf({ ...valid(), sex: 'male', party: PARTY, slogan: ' Un mot ', biography: 'Texte' }),
    ).toEqual({
      first_name: 'Nadège',
      last_name: 'Pierre-Louis',
      sex: 'male',
      party: PARTY,
      slogan: 'Un mot',
      biography: 'Texte',
    });
  });
});

describe('changesOf', () => {
  it('leaves the ballot out unless it changed', () => {
    expect(changesOf(valid(), BALLOT)).not.toHaveProperty('ballot');
    expect(changesOf({ ...valid(), ballot: OTHER }, BALLOT)).toMatchObject({ ballot: OTHER });
  });
});

describe('draftOf', () => {
  it('fills the form with the values of a candidate', () => {
    const candidate = {
      id: 'c',
      ballot: BALLOT,
      party: null,
      first_name: 'Jean',
      last_name: 'Désir',
      sex: 'male',
      slogan: null,
      biography: 'Bio',
      photo: null,
      position: 1,
      created_at: '',
      updated_at: '',
    } satisfies Candidate;

    expect(draftOf(candidate)).toEqual({
      firstName: 'Jean',
      lastName: 'Désir',
      sex: 'male',
      ballot: BALLOT,
      party: '',
      slogan: '',
      biography: 'Bio',
    });
  });
});

describe('apiFieldErrors', () => {
  it('keeps the first code of each field of the form and ignores the others', () => {
    expect(
      apiFieldErrors({
        first_name: ['required', 'max'],
        party: ['invalid'],
        photo: ['file'],
      }),
    ).toEqual({ first_name: 'required', party: 'invalid' });
  });
});

describe('fullName and charCount', () => {
  it('writes "First Last"', () => {
    expect(fullName({ first_name: 'Jean-Marc', last_name: 'Désir' })).toBe('Jean-Marc Désir');
  });

  it('counts one per code point', () => {
    expect(charCount('Ensemble, plus loin')).toBe(19);
    expect(charCount('😀é')).toBe(2);
  });
});
