import { describe, expect, it } from 'vitest';
import {
  apiFieldErrors,
  bodyOf,
  EMPTY_DRAFT,
  validateBallot,
  type BallotDraft,
} from './ballotForm';

const draft = (changes: Partial<BallotDraft>): BallotDraft => ({
  ...EMPTY_DRAFT,
  title: 'Président(e)',
  ...changes,
});

describe('validateBallot', () => {
  it('accepts a title with the defaults of one seat and a blank vote', () => {
    expect(validateBallot(draft({}))).toEqual({});
  });

  it('wants a title that is not only spaces', () => {
    expect(validateBallot(draft({ title: '' })).title).toBe('required');
    expect(validateBallot(draft({ title: '   ' })).title).toBe('required');
  });

  it('refuses a title of more than 200 characters, counted after trimming', () => {
    expect(validateBallot(draft({ title: 'a'.repeat(200) })).title).toBeUndefined();
    expect(validateBallot(draft({ title: ` ${'a'.repeat(200)} ` })).title).toBeUndefined();
    expect(validateBallot(draft({ title: 'a'.repeat(201) })).title).toBe('max');
  });

  it('refuses a description of more than 1000 characters', () => {
    expect(validateBallot(draft({ description: 'a'.repeat(1000) })).description).toBeUndefined();
    expect(validateBallot(draft({ description: 'a'.repeat(1001) })).description).toBe('max');
  });

  it('wants a whole number of seats from 1 to 20', () => {
    expect(validateBallot(draft({ seats: '1' })).seats).toBeUndefined();
    expect(validateBallot(draft({ seats: '20' })).seats).toBeUndefined();
    expect(validateBallot(draft({ seats: '' })).seats).toBe('integer');
    expect(validateBallot(draft({ seats: '2.5' })).seats).toBe('integer');
    expect(validateBallot(draft({ seats: 'abc' })).seats).toBe('integer');
    expect(validateBallot(draft({ seats: '0' })).seats).toBe('min');
    expect(validateBallot(draft({ seats: '-3' })).seats).toBe('min');
    expect(validateBallot(draft({ seats: '21' })).seats).toBe('max');
  });

  it('reports every field that fails at once', () => {
    expect(validateBallot(draft({ title: '', seats: '0' }))).toEqual({
      title: 'required',
      seats: 'min',
    });
  });
});

describe('bodyOf', () => {
  it('trims, sends a blank description as null and the seats as a number', () => {
    expect(
      bodyOf(draft({ title: '  Délégués ', description: '  ', seats: '3', allowBlank: false })),
    ).toEqual({
      title: 'Délégués',
      description: null,
      seats: 3,
      allow_blank: false,
    });
    expect(bodyOf(draft({ description: ' Un par classe ' })).description).toBe('Un par classe');
  });
});

describe('apiFieldErrors', () => {
  it('keeps the first code of the fields of the form and drops the others', () => {
    expect(
      apiFieldErrors({ title: ['required', 'max'], seats: ['max'], allow_blank: ['boolean'] }),
    ).toEqual({ title: 'required', seats: 'max' });
  });
});
